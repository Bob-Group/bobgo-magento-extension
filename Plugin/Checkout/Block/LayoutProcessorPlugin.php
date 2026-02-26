<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Plugin\Checkout\Block;

use Magento\Checkout\Block\Checkout\LayoutProcessor;

/**
 * Adds a suburb field to the checkout shipping address form.
 *
 * Navigates the deeply nested jsLayout structure to inject a custom text input
 * into the shipping-address-fieldset.
 */
class LayoutProcessorPlugin
{
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
                        'description' => 'Required for shipping accuracy',
                    ],
                ],
                'dataScope' => 'shippingAddress.custom_attributes.suburb',
                'label' => 'Suburb',
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
