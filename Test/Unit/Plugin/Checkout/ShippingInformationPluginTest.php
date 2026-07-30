<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Plugin\Checkout;

use BobGroup\BobGo\Plugin\Checkout\ShippingInformationPlugin;
use Magento\Checkout\Api\Data\ShippingInformationInterface;
use Magento\Checkout\Model\ShippingInformationManagement;
use Magento\Quote\Api\Data\AddressInterface;
use PHPUnit\Framework\TestCase;

/**
 * The link that was missing from the suburb chain.
 *
 * Every other piece existed — the checkout field, the JS that moves it into
 * extension_attributes, the quote -> order converter, the payload mapper — but
 * nothing ever wrote the value onto the quote address. Extension attributes live
 * only for the request that carried them, and order placement is a later
 * request, so the converter kept reading an address that had never stored a
 * suburb and every order went out with the city in `local_area`.
 *
 * The assertion that matters throughout is setData('suburb', ...): a plain data
 * key is what Quote::setShippingAddress() merges via addData(), and therefore
 * what reaches the column.
 */
class ShippingInformationPluginTest extends TestCase
{
    /** @var ShippingInformationPlugin */
    private $plugin;

    protected function setUp(): void
    {
        $this->plugin = new ShippingInformationPlugin();
    }

    public function testPersistsSuburbFromExtensionAttributes(): void
    {
        $address = $this->address();
        $address->method('getExtensionAttributes')->willReturn($this->extensionWith('Menlyn'));
        $address->expects($this->once())->method('setData')->with('suburb', 'Menlyn');

        $this->plugin->beforeSaveAddressInformation(
            $this->subject(),
            1,
            $this->information($address)
        );
    }

    /**
     * A checkout that posts the field without our mixin — a third-party one-page
     * checkout, say. Dropping the suburb silently is the failure this plugin exists
     * to end, so the fallback is deliberate rather than defensive noise.
     */
    public function testFallsBackToCustomAttribute(): void
    {
        $attribute = $this->createMock(\Magento\Framework\Api\AttributeInterface::class);
        $attribute->method('getValue')->willReturn('Rosebank');

        $address = $this->address();
        $address->method('getExtensionAttributes')->willReturn(null);
        $address->method('getCustomAttribute')->with('suburb')->willReturn($attribute);
        $address->expects($this->once())->method('setData')->with('suburb', 'Rosebank');

        $this->plugin->beforeSaveAddressInformation($this->subject(), 1, $this->information($address));
    }

    public function testPrefersExtensionAttributeOverCustomAttribute(): void
    {
        $attribute = $this->createMock(\Magento\Framework\Api\AttributeInterface::class);
        $attribute->method('getValue')->willReturn('Stale');

        $address = $this->address();
        $address->method('getExtensionAttributes')->willReturn($this->extensionWith('Menlyn'));
        $address->method('getCustomAttribute')->willReturn($attribute);
        $address->expects($this->once())->method('setData')->with('suburb', 'Menlyn');

        $this->plugin->beforeSaveAddressInformation($this->subject(), 1, $this->information($address));
    }

    public function testTrimsSurroundingWhitespace(): void
    {
        $address = $this->address();
        $address->method('getExtensionAttributes')->willReturn($this->extensionWith("  Menlyn \n"));
        $address->expects($this->once())->method('setData')->with('suburb', 'Menlyn');

        $this->plugin->beforeSaveAddressInformation($this->subject(), 1, $this->information($address));
    }

    /**
     * Never write an empty suburb: OrderMapper falls back to the city when the
     * suburb is absent, and an empty string is not absent.
     *
     * @dataProvider emptyValueProvider
     * @param mixed $value
     */
    public function testDoesNotWriteAnEmptySuburb($value): void
    {
        $address = $this->address();
        $address->method('getExtensionAttributes')->willReturn($this->extensionWith($value));
        $address->method('getCustomAttribute')->willReturn(null);
        $address->expects($this->never())->method('setData');

        $this->plugin->beforeSaveAddressInformation($this->subject(), 1, $this->information($address));
    }

    /**
     * @return array<string,array{0:mixed}>
     */
    public function emptyValueProvider(): array
    {
        return [
            'empty string' => [''],
            'whitespace only' => ["  \n "],
            'null' => [null],
        ];
    }

    public function testLeavesTheAddressAloneWhenNoSuburbIsSupplied(): void
    {
        $address = $this->address();
        $address->method('getExtensionAttributes')->willReturn(null);
        $address->method('getCustomAttribute')->willReturn(null);
        $address->expects($this->never())->method('setData');

        $this->plugin->beforeSaveAddressInformation($this->subject(), 1, $this->information($address));
    }

    /**
     * A virtual cart has no shipping address. Checkout must not break.
     */
    public function testToleratesAMissingShippingAddress(): void
    {
        $information = $this->createMock(ShippingInformationInterface::class);
        $information->method('getShippingAddress')->willReturn(null);

        $this->assertNull(
            $this->plugin->beforeSaveAddressInformation($this->subject(), 1, $information)
        );
    }

    /**
     * Arguments are mutated in place, so the plugin must not rewrite them.
     */
    public function testReturnsNullSoArgumentsAreNotRewritten(): void
    {
        $address = $this->address();
        $address->method('getExtensionAttributes')->willReturn($this->extensionWith('Menlyn'));

        $this->assertNull(
            $this->plugin->beforeSaveAddressInformation($this->subject(), 1, $this->information($address))
        );
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    private function address()
    {
        // The methods the plugin probes via method_exists, declared the way the
        // sibling ToOrderAddressPlugin test does — the interface stub is bare.
        return $this->getMockBuilder(AddressInterface::class)
            ->addMethods(['getExtensionAttributes', 'getCustomAttribute', 'setData', 'getData'])
            ->getMockForAbstractClass();
    }

    /**
     * @param mixed $suburb
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    private function extensionWith($suburb)
    {
        $ext = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getSuburb'])
            ->getMock();
        $ext->method('getSuburb')->willReturn($suburb);
        return $ext;
    }

    /**
     * @param \PHPUnit\Framework\MockObject\MockObject $address
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    private function information($address)
    {
        $information = $this->createMock(ShippingInformationInterface::class);
        $information->method('getShippingAddress')->willReturn($address);
        return $information;
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    private function subject()
    {
        return $this->createMock(ShippingInformationManagement::class);
    }
}
