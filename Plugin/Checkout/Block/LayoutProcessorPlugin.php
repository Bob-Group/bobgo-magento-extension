<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Plugin\Checkout\Block;

use Magento\Checkout\Block\Checkout\LayoutProcessor;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Adds a suburb field to the checkout shipping address form.
 *
 * Navigates the deeply nested jsLayout structure to inject a custom text input
 * into the shipping-address-fieldset.
 */
class LayoutProcessorPlugin
{
    private const XML_PATH_LABEL = 'carriers/bobgo/suburb_label';
    private const XML_PATH_TOOLTIP = 'carriers/bobgo/suburb_tooltip';

    private const DEFAULT_LABEL = 'Suburb';
    private const DEFAULT_TOOLTIP = 'Required for shipping accuracy';

    private ScopeConfigInterface $scopeConfig;

    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Path segments from jsLayout root to the shipping address fieldset children.
     */
    private const FIELDSET_PATH = [
        'components',
        'checkout',
        'children',
        'steps',
        'children',
        'shipping-step',
        'children',
        'shippingAddress',
        'children',
        'shipping-address-fieldset',
        'children',
    ];

    /**
     * Modify checkout layout to add suburb field.
     *
     * @param LayoutProcessor $subject
     * @param array<string,mixed> $jsLayout
     * @return array<string,mixed>
     */
    public function afterProcess(
        LayoutProcessor $subject,
        array $jsLayout
    ): array {
        $fieldsetChildren = &$this->resolveNestedPath($jsLayout, self::FIELDSET_PATH);

        if ($fieldsetChildren !== null) {
            $fieldsetChildren['suburb'] = [
                'component' => 'Magento_Ui/js/form/element/abstract',
                'config' => [
                    'customScope' => 'shippingAddress.custom_attributes',
                    'customEntry' => null,
                    'template' => 'ui/form/field',
                    'elementTmpl' => 'ui/form/element/input',
                    'tooltip' => [
                        'description' => $this->text(self::XML_PATH_TOOLTIP, self::DEFAULT_TOOLTIP),
                    ],
                ],
                'dataScope' => 'shippingAddress.custom_attributes.suburb',
                // Merchant-overridable: "Suburb" is the South African term, but a
                // store selling elsewhere may want "Area" or a local equivalent.
                'label' => $this->text(self::XML_PATH_LABEL, self::DEFAULT_LABEL),
                'provider' => 'checkoutProvider',
                'sortOrder' => 80,
                'validation' => [
                    'required-entry' => true,
                ],
                'options' => [],
                'filterBy' => null,
                'customEntry' => null,
                'visible' => true,
                'value' => '',
            ];
        }

        return $jsLayout;
    }

    private function text(string $path, string $default): string
    {
        $value = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE);
        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    /**
     * Walk into a nested array following the given path segments.
     *
     * Returns a reference to the target node, or null if any segment is missing.
     *
     * @param array<string,mixed> $data
     * @param string[] $path
     * @return array<string,mixed>|null
     */
    private function &resolveNestedPath(array &$data, array $path): ?array
    {
        $current = &$data;
        foreach ($path as $segment) {
            if (!isset($current[$segment]) || !is_array($current[$segment])) {
                $null = null;
                return $null;
            }
            $current = &$current[$segment];
        }
        return $current;
    }
}
