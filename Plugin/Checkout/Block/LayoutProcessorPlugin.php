<?php
namespace uafrica\Customshipping\Plugin\Checkout\Block;

use Magento\Checkout\Block\Checkout\LayoutProcessor;

class LayoutProcessorPlugin
{
    /**
     * This is the Class That Allows The Field To Appear As Required On Checkout,
     * But It Does Not Save The Data To The Quote Table In The Database For The Order
     * To Be Processed Correctly In The Backend And Frontend It Works With Shipping
     * Information Management Class
     * @param LayoutProcessor $subject
     * @param array $jsLayout
     * @return array
     */
    public function afterProcess(
        LayoutProcessor $subject,
        array  $jsLayout
    ): array
    {

        $jsLayout['components']['checkout']['children']['steps']['children']['shipping-step']['children']
        ['shippingAddress']['children']['shipping-address-fieldset']['children']['suburb'] = [
            'component' => 'Magento_Ui/js/form/element/textarea',
            'config' => [
                'customScope' => 'shippingAddress.extension_attributes',
                'customEntry' => null,
                'template' => 'ui/form/field',
                'elementTmpl' => 'ui/form/element/input',
                'options' => [],
                'tooltip' => [
                    'description' => __('Necessary for shipping.')
                ],
                'id' => 'suburb'
            ],
            'dataScope' => 'shippingAddress.extension_attributes.suburb',
            'label' => 'Suburb',
            'provider' => 'checkoutProvider',
            'visible' => true,
            'validation' => [
                'required-entry' => true
            ],
            'sortOrder' => 110,
            /*'customEntry' => null,*/
            'id' => 'suburb'
        ];

        return $jsLayout;
    }
}
