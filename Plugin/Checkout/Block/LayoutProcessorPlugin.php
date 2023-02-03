<?php
namespace bobgo\CustomShipping\Plugin\Checkout\Block;

use Magento\Checkout\Block\Checkout\LayoutProcessor;

/**
 * Class LayoutProcessorPlugin
 * @package bobgo\CustomShipping\Plugin\Checkout\Block
 * This class is supposed to add a new field to the checkout page for the suburb. It is supposed to be used in conjunction with the SaveOrderBeforeSalesModelQuote observer.
 * At the moment, it overrides the 2 Address fields and adds a new 3 Address field to accommodate the suburb on the checkout page( including placeholders).
 * This is not the best way to do it, but it is the only way I could get it to work.
 */
class LayoutProcessorPlugin
{
    /**
     * @param \Magento\Checkout\Block\Checkout\LayoutProcessor $subject
     * @param array $jsLayout
     * @return array
     */

    public function afterProcess(
        \Magento\Checkout\Block\Checkout\LayoutProcessor $subject,
        array  $jsLayout
    ) {
        $jsLayout['components']['checkout']['children']['steps']['children']['shipping-step']['children']
        ['shippingAddress']['children']['shipping-address-fieldset']['children']['street'] = [
            'component' => 'Magento_Ui/js/form/components/group',
            'label' => ('Street Address'),
            'required' => false,
            'dataScope' => 'shippingAddress.street',
            'provider' => 'checkoutProvider',
            'sortOrder' => 55,
            'type' => 'group',
            'additionalClasses' => 'street',
            'children' => [
                [
                    'label' => ('Street Address'),
                    'component' => 'Magento_Ui/js/form/element/abstract',
                    'config' => [
                        'placeholder' => 'street address',
                        'customScope' => 'shippingAddress',
                        'template' => 'ui/form/field',
                        'elementTmpl' => 'ui/form/element/input'
                    ],
                    'dataScope' => '0',
                    'provider' => 'checkoutProvider',
                    'validation' => ['required-entry' => true, "min_text_length" => 1, "max_text_length" => 255],
                ],
                [
                    'label' => __('Label 2'),
                    'component' => 'Magento_Ui/js/form/element/abstract',
                    'config' => [
                        'placeholder' => 'town',
                        'customScope' => 'shippingAddress',
                        'template' => 'ui/form/field',
                        'elementTmpl' => 'ui/form/element/input'
                    ],
                    'dataScope' => '1',
                    'provider' => 'checkoutProvider',
                    'validation' => ['required-entry' => true, "min_text_length" => 1, "max_text_length" => 255],
                ],
                [
                    'label' => __('Label 3'),
                    'component' => 'Magento_Ui/js/form/element/abstract',
                    'config' => [
                        'placeholder' => 'suburb',
                        'customScope' => 'shippingAddress',
                        'template' => 'ui/form/field',
                        'elementTmpl' => 'ui/form/element/input'
                    ],
                    'dataScope' => '2',
                    'provider' => 'checkoutProvider',
                    'validation' => ['required-entry' => true, "min_text_length" => 1, "max_text_length" => 255],
                ],
                    'dataScope' => '3',
                    'provider' => 'checkoutProvider',
                    'validation' => ['required-entry' => false, "min_text_length" => 1, "max_text_length" => 255],
                ],
        ];
        return $jsLayout;
    }
}
