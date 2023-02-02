<?php
namespace bobgo\Customshipping\Plugin\Checkout\Block;

use Magento\Checkout\Block\Checkout\LayoutProcessor;

class LayoutProcessorPlugin
{
//    /**
//     * This is the Class That Allows The Field To Appear As Required On Checkout,
//     * But It Does Not Save The Data To The Quote Table In The Database For The Order
//     * To Be Processed Correctly In The Backend And Frontend It Works With Shipping
//     * Information Management Class
//     * @param LayoutProcessor $subject
//     * @param array $jsLayout
//     * @return array
//     */

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
