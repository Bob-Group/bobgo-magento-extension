<?php

namespace BobGroup\BobGo\Plugin\Checkout\Block;

use Magento\Checkout\Block\Checkout\LayoutProcessor;

/**
 * Class LayoutProcessorPlugin
 * Adds a new field to the checkout page for the suburb.
 *
 * This class is used in conjunction with the SaveOrderBeforeSalesModelQuote observer.
 * It overrides the 2 Address fields and adds a new 3rd Address field to accommodate the suburb
 * on the checkout page (including placeholders).
 * Note: This implementation, though functional, may not be the most optimal way.
 */
class LayoutProcessorPlugin
{
    /**
     * Modify checkout layout to add suburb field.
     *
     * @param LayoutProcessor $subject The subject being processed.
     * @param array<string,mixed> $jsLayout The JS layout array to be modified.
     * @return array<string, mixed> The modified JS layout array.
     */
    public function afterProcess(
        LayoutProcessor $subject,
        array $jsLayout
    ): array {
        $suburbAttribute = 'suburb';

        if (isset($jsLayout['components'])
            && is_array($jsLayout['components'])
            && isset($jsLayout['components']['checkout'])
            && is_array($jsLayout['components']['checkout'])
            && isset($jsLayout['components']['checkout']['children'])
            && is_array($jsLayout['components']['checkout']['children'])
            && isset($jsLayout['components']['checkout']['children']['steps'])
            && is_array($jsLayout['components']['checkout']['children']['steps'])
            && isset($jsLayout['components']['checkout']['children']['steps']['children'])
            && is_array($jsLayout['components']['checkout']['children']['steps']['children'])
            && isset($jsLayout['components']['checkout']['children']['steps']['children']['shipping-step'])
            && is_array($jsLayout['components']['checkout']['children']['steps']['children']['shipping-step'])
            && isset($jsLayout['components']['checkout']['children']['steps']['children']['shipping-step']['children'])
            && is_array($jsLayout['components']['checkout']['children']['steps']['children']['shipping-step']
            ['children'])
            && isset($jsLayout['components']['checkout']['children']['steps']['children']['shipping-step']['children']
                ['shippingAddress'])
            && is_array($jsLayout['components']['checkout']['children']['steps']['children']['shipping-step']
            ['children']['shippingAddress'])
            && isset($jsLayout['components']['checkout']['children']['steps']['children']['shipping-step']['children']
                ['shippingAddress']['children'])
            && is_array($jsLayout['components']['checkout']['children']['steps']['children']['shipping-step']
            ['children']['shippingAddress']['children'])
            && isset($jsLayout['components']['checkout']['children']['steps']['children']['shipping-step']['children']
                ['shippingAddress']['children']['shipping-address-fieldset'])
            && is_array($jsLayout['components']['checkout']['children']['steps']['children']['shipping-step']
            ['children']['shippingAddress']['children']['shipping-address-fieldset'])
            && isset($jsLayout['components']['checkout']['children']['steps']['children']['shipping-step']['children']
                ['shippingAddress']['children']['shipping-address-fieldset']['children'])
            && is_array($jsLayout['components']['checkout']['children']['steps']['children']['shipping-step']
            ['children']['shippingAddress']['children']['shipping-address-fieldset']['children'])) {

            $jsLayout['components']['checkout']['children']['steps']['children']['shipping-step']['children']
            ['shippingAddress']['children']['shipping-address-fieldset']['children'][$suburbAttribute] = [
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
                'dataScope' => 'shippingAddress.custom_attributes' . '.' . $suburbAttribute,
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
                'value' => '' // Default value for the attribute
            ];
        }

        return $jsLayout;
    }
}
