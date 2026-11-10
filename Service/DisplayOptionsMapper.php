<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Builds the `display_options` array Bob Go carries on order items.
 *
 * This is what a picker in the warehouse actually needs: which variant, which
 * custom option, what the customer typed into the personalisation field. Bob Go
 * added the column (JSONB on order_items) for this integration family, so the
 * contract is fixed: one entry per option row, carrying both the raw pair and the
 * human pair the customer saw.
 *
 *   [{ key, value, display_key, display_value }]
 *
 * Magento keeps all of it in the order item's `product_options`, spread across
 * three shapes: `attributes_info` for configurable variants, `options` for custom
 * options, and `bundle_options` for bundle selections. `info_buyRequest` lives in
 * the same array and is deliberately ignored — it is internal request state, not
 * something the customer saw.
 *
 * Constraints inherited from the WooCommerce implementation, each of which cost
 * someone time:
 *
 *  - Never merge duplicate keys. Raw values are slugs; joining them corrupts them.
 *  - Strip NUL bytes from raw values — PostgreSQL JSONB rejects them outright.
 *  - Normalise display values (strip tags, decode entities, drop control
 *    characters, collapse whitespace) so markup from a rich-text option doesn't
 *    end up in a picking list.
 *  - Cap the array and each field, and say so when truncating.
 *  - Blocklist rather than allowlist, so a newly added product option flows
 *    without anyone visiting the settings page first.
 */
class DisplayOptionsMapper
{
    public const XML_PATH_ENABLED = 'carriers/bobgo/send_display_options';
    public const XML_PATH_BLOCKLIST = 'carriers/bobgo/display_options_blocklist';

    /** Per item. Beyond this a picking list stops being useful anyway. */
    private const MAX_ENTRIES = 30;

    /** Per field. */
    private const MAX_FIELD_LENGTH = 500;

    private const TRUNCATION_MARKER = '…';

    /**
     * `product_options` keys holding customer-visible option rows, and whether
     * each one's `value` is itself a list of selections (bundles).
     */
    private const SOURCES = [
        'attributes_info' => false,
        'options' => false,
        'bundle_options' => true,
    ];

    private ScopeConfigInterface $scopeConfig;

    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * @param OrderItemInterface|null $parent The configurable parent, when $item is
     *        its simple child. Magento records the chosen variant attributes on the
     *        PARENT (`attributes_info`); the child carries only info_buyRequest. We
     *        send the child, so without the parent this returns nothing for every
     *        configurable product — the exact case the field exists for.
     * @return array<int,array<string,string>>
     */
    public function map(OrderItemInterface $item, ?OrderItemInterface $parent = null): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $sources = [$item->getProductOptions()];
        if ($parent !== null) {
            $sources[] = $parent->getProductOptions();
        }

        $blocked = $this->blocklist();
        $entries = [];
        $seen = [];
        $truncated = false;

        foreach ($sources as $productOptions) {
            if (!is_array($productOptions)) {
                continue;
            }

            foreach (self::SOURCES as $sourceKey => $valueIsList) {
                $rows = $productOptions[$sourceKey] ?? null;
                if (!is_array($rows)) {
                    continue;
                }

                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    $entry = $this->mapRow($row, $valueIsList);
                    if ($entry === null || in_array($entry['key'], $blocked, true)) {
                        continue;
                    }

                    // Child and parent can describe the same option. Dedupe on the
                    // whole entry, not on the key — two *different* selections that
                    // share a key are still two selections.
                    $fingerprint = $entry['key'] . "\0" . $entry['value'] . "\0" . $entry['display_value'];
                    if (isset($seen[$fingerprint])) {
                        continue;
                    }
                    $seen[$fingerprint] = true;

                    if (count($entries) >= self::MAX_ENTRIES) {
                        $truncated = true;
                        break 3;
                    }

                    $entries[] = $entry;
                }
            }
        }

        if ($truncated) {
            $entries[] = [
                'key' => 'bobgo_truncated',
                'value' => '1',
                'display_key' => 'Truncated',
                'display_value' => sprintf('Only the first %d options are shown', self::MAX_ENTRIES),
            ];
        }

        return $entries;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,string>|null
     */
    private function mapRow(array $row, bool $valueIsList): ?array
    {
        $label = $this->text($row['label'] ?? '');
        if ($label === '') {
            return null;
        }

        $displayValue = $valueIsList
            ? $this->flattenSelections($row['value'] ?? null)
            : $this->text($row['value'] ?? '');

        // Magento has no slug equivalent of WooCommerce's taxonomy key, so the
        // raw key is derived from the label. The raw *value* prefers the option
        // value id when Magento recorded one, which is the closest thing to the
        // unresolved value.
        $rawValue = isset($row['option_value']) && is_scalar($row['option_value'])
            ? (string) $row['option_value']
            : $displayValue;

        return [
            'key' => $this->slug($label),
            'value' => $this->raw($rawValue),
            'display_key' => $this->cap($label),
            'display_value' => $this->cap($displayValue),
        ];
    }

    /**
     * Bundle rows carry a list of selections rather than a scalar.
     *
     * @param mixed $value
     */
    private function flattenSelections($value): string
    {
        if (!is_array($value)) {
            return $this->text($value ?? '');
        }

        $parts = [];
        foreach ($value as $selection) {
            if (!is_array($selection)) {
                $parts[] = $this->text($selection);
                continue;
            }
            $title = $this->text($selection['title'] ?? '');
            if ($title === '') {
                continue;
            }
            $qty = isset($selection['qty']) && is_numeric($selection['qty']) ? (float) $selection['qty'] : null;
            $parts[] = $qty !== null && $qty > 1
                ? sprintf('%s x%s', $title, rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.'))
                : $title;
        }

        return implode(', ', array_filter($parts, static function ($part) {
            return $part !== '';
        }));
    }

    /**
     * Strip markup, decode entities, drop control characters, collapse whitespace.
     *
     * @param mixed $value
     */
    private function text($value): string
    {
        if (is_array($value)) {
            return '';
        }
        if (!is_scalar($value)) {
            return '';
        }

        $text = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }

    /**
     * Raw values keep their original form apart from NUL bytes, which
     * PostgreSQL JSONB — where Bob Go stores this — rejects outright.
     */
    private function raw(string $value): string
    {
        return $this->cap(str_replace("\0", '', $value));
    }

    private function slug(string $label): string
    {
        $slug = strtolower($this->text($label));
        $slug = (string) preg_replace('/[^a-z0-9]+/', '_', $slug);
        return trim($slug, '_');
    }

    private function cap(string $value): string
    {
        if (mb_strlen($value, 'UTF-8') <= self::MAX_FIELD_LENGTH) {
            return $value;
        }
        return mb_substr($value, 0, self::MAX_FIELD_LENGTH - 1, 'UTF-8') . self::TRUNCATION_MARKER;
    }

    private function isEnabled(): bool
    {
        // Default on: the whole point is that a merchant who adds a product
        // option gets it forwarded without having to know this setting exists.
        $value = $this->scopeConfig->getValue(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE);
        return $value === null || $value === '' || (bool) $value;
    }

    /**
     * Raw keys the merchant does not want forwarded.
     *
     * @return string[]
     */
    private function blocklist(): array
    {
        $configured = $this->scopeConfig->getValue(self::XML_PATH_BLOCKLIST, ScopeInterface::SCOPE_STORE);
        if (!is_string($configured) || trim($configured) === '') {
            return [];
        }

        $keys = [];
        foreach (preg_split('/[,\n]/', $configured) ?: [] as $key) {
            $key = $this->slug((string) $key);
            if ($key !== '') {
                $keys[] = $key;
            }
        }
        return $keys;
    }
}
