<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Service;

use BobGroup\BobGo\Service\DisplayOptionsMapper;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use PHPUnit\Framework\TestCase;

/**
 * What a picker in the warehouse needs: which variant, which custom option, what
 * the customer typed. Bob Go added the JSONB column for this integration family,
 * so the contract is fixed — raw pair plus the human pair the customer saw.
 */
class DisplayOptionsMapperTest extends TestCase
{
    private $scopeConfig;
    /** @var DisplayOptionsMapper */
    private $mapper;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->mapper = new DisplayOptionsMapper($this->scopeConfig);
    }

    public function testMapsConfigurableVariantAttributes(): void
    {
        $entries = $this->mapper->map($this->item([
            'attributes_info' => [
                ['label' => 'Colour', 'value' => 'Pure Hazel', 'option_id' => '93', 'option_value' => '50'],
                ['label' => 'Size', 'value' => 'Medium', 'option_id' => '94', 'option_value' => '72'],
            ],
        ]));

        $this->assertSame([
            ['key' => 'colour', 'value' => '50', 'display_key' => 'Colour', 'display_value' => 'Pure Hazel'],
            ['key' => 'size', 'value' => '72', 'display_key' => 'Size', 'display_value' => 'Medium'],
        ], $entries);
    }

    public function testMapsCustomOptionsIncludingPersonalisationText(): void
    {
        $entries = $this->mapper->map($this->item([
            'options' => [
                ['label' => 'Engraving', 'value' => 'Happy Birthday Sam'],
            ],
        ]));

        $this->assertCount(1, $entries);
        $this->assertSame('engraving', $entries[0]['key']);
        $this->assertSame('Happy Birthday Sam', $entries[0]['display_value']);
    }

    public function testFlattensBundleSelections(): void
    {
        $entries = $this->mapper->map($this->item([
            'bundle_options' => [
                [
                    'label' => 'Contents',
                    'value' => [
                        ['title' => 'Water bottle', 'qty' => 1],
                        ['title' => 'Energy bar', 'qty' => 3],
                    ],
                ],
            ],
        ]));

        $this->assertSame('Water bottle, Energy bar x3', $entries[0]['display_value']);
    }

    /**
     * Two selections that happen to share a key are two selections. Merging them
     * corrupts the raw values, which are slugs.
     */
    public function testDoesNotMergeDuplicateKeys(): void
    {
        $entries = $this->mapper->map($this->item([
            'options' => [
                ['label' => 'Extra', 'value' => 'Gift wrap'],
                ['label' => 'Extra', 'value' => 'Gift note'],
            ],
        ]));

        $this->assertCount(2, $entries);
        $this->assertSame('extra', $entries[0]['key']);
        $this->assertSame('extra', $entries[1]['key']);
    }

    /**
     * info_buyRequest sits in the same array and is internal request state, not
     * something the customer chose.
     */
    public function testIgnoresInternalBuyRequestState(): void
    {
        $entries = $this->mapper->map($this->item([
            'info_buyRequest' => ['qty' => 1, 'super_attribute' => [93 => 50]],
        ]));

        $this->assertSame([], $entries);
    }

    public function testStripsMarkupAndCollapsesWhitespaceInDisplayValues(): void
    {
        $entries = $this->mapper->map($this->item([
            'options' => [
                ['label' => 'Message', 'value' => "<b>Hello</b>&nbsp;&amp;   \n  goodbye"],
            ],
        ]));

        $this->assertSame('Hello & goodbye', $entries[0]['display_value']);
    }

    /**
     * Bob Go stores this in PostgreSQL JSONB, which rejects NUL bytes outright.
     */
    public function testStripsNulBytesFromRawValues(): void
    {
        $entries = $this->mapper->map($this->item([
            'options' => [
                ['label' => 'Ref', 'value' => "AB\0CD"],
            ],
        ]));

        $this->assertStringNotContainsString("\0", $entries[0]['value']);
    }

    public function testCapsFieldLength(): void
    {
        $entries = $this->mapper->map($this->item([
            'options' => [
                ['label' => 'Notes', 'value' => str_repeat('x', 900)],
            ],
        ]));

        $this->assertSame(500, mb_strlen($entries[0]['display_value']));
    }

    public function testCapsEntryCountAndSaysSo(): void
    {
        $rows = [];
        for ($i = 0; $i < 40; $i++) {
            $rows[] = ['label' => 'Option ' . $i, 'value' => 'v' . $i];
        }

        $entries = $this->mapper->map($this->item(['options' => $rows]));

        $this->assertCount(31, $entries, '30 options plus a truncation marker');
        $this->assertSame('bobgo_truncated', $entries[30]['key']);
    }

    /**
     * Blocklist, not allowlist: a newly added product option must flow without
     * anyone visiting the settings page first.
     */
    public function testHonoursTheBlocklist(): void
    {
        $this->scopeConfig->method('getValue')->willReturnCallback(
            static function ($path) {
                return $path === DisplayOptionsMapper::XML_PATH_BLOCKLIST ? "internal_note,\nwarehouse_ref" : null;
            }
        );

        $entries = $this->mapper->map($this->item([
            'options' => [
                ['label' => 'Internal note', 'value' => 'do not send'],
                ['label' => 'Engraving', 'value' => 'send me'],
            ],
        ]));

        $this->assertCount(1, $entries);
        $this->assertSame('engraving', $entries[0]['key']);
    }

    public function testCanBeTurnedOffEntirely(): void
    {
        $this->scopeConfig->method('getValue')->willReturnCallback(
            static function ($path) {
                return $path === DisplayOptionsMapper::XML_PATH_ENABLED ? '0' : null;
            }
        );

        $this->assertSame([], $this->mapper->map($this->item([
            'options' => [['label' => 'Engraving', 'value' => 'x']],
        ])));
    }

    public function testHandlesAnItemWithNoOptionsAtAll(): void
    {
        $item = $this->createMock(OrderItemInterface::class);
        $item->method('getProductOptions')->willReturn(null);

        $this->assertSame([], $this->mapper->map($item));
    }

    /**
     * @param array<string,mixed> $productOptions
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    private function item(array $productOptions)
    {
        $item = $this->createMock(OrderItemInterface::class);
        $item->method('getProductOptions')->willReturn($productOptions);
        return $item;
    }

    /**
     * The case this whole feature exists for, and the one it was silently failing.
     *
     * Magento records two rows for a configurable: the parent, which holds
     * `attributes_info` describing what the customer chose, and the simple child,
     * which holds only info_buyRequest. OrderMapper sends the CHILD (it has the
     * variant SKU), so reading options from the child alone returned nothing for
     * every configurable product — verified against a live order where a
     * size-L/yellow tank produced no display_options at all.
     */
    public function testReadsVariantAttributesFromTheConfigurableParent(): void
    {
        $child = $this->item(['info_buyRequest' => ['qty' => 1]]);
        $parent = $this->item([
            'info_buyRequest' => ['qty' => 1],
            'attributes_info' => [
                ['label' => 'Size', 'value' => 'L', 'option_value' => '169'],
                ['label' => 'Color', 'value' => 'Yellow', 'option_value' => '61'],
            ],
        ]);

        $entries = $this->mapper->map($child, $parent);

        $this->assertSame(['size', 'color'], array_column($entries, 'key'));
        $this->assertSame(['L', 'Yellow'], array_column($entries, 'display_value'));
    }

    /**
     * Child and parent can describe the same option; the same selection twice is
     * noise, but two genuinely different selections sharing a key are not.
     */
    public function testDoesNotDuplicateAnOptionPresentOnBothItems(): void
    {
        $options = ['options' => [['label' => 'Engraving', 'value' => 'Hello']]];

        $entries = $this->mapper->map($this->item($options), $this->item($options));

        $this->assertCount(1, $entries);
    }

    public function testStillKeepsTwoDifferentSelectionsSharingAKey(): void
    {
        $entries = $this->mapper->map(
            $this->item(['options' => [['label' => 'Extra', 'value' => 'Gift wrap']]]),
            $this->item(['options' => [['label' => 'Extra', 'value' => 'Gift note']]])
        );

        $this->assertCount(2, $entries);
    }

    public function testWorksWithNoParent(): void
    {
        $entries = $this->mapper->map($this->item([
            'options' => [['label' => 'Engraving', 'value' => 'Hello']],
        ]));

        $this->assertCount(1, $entries);
    }
}
