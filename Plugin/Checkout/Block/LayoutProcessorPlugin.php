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
     * @param \Magento\Checkout\Block\Checkout\LayoutProcessor $subject
     * @param array $jsLayout
     * @return array
     */
    public function afterProcess(
        \Magento\Checkout\Block\Checkout\LayoutProcessor $subject,
        array $jsLayout
    ) {
        $suburbAttribute = 'suburb';
        $suburb = [
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

        $jsLayout['components']['checkout']['children']['steps']['children']['shipping-step']['children']
        ['shippingAddress']['children']['shipping-address-fieldset']['children'][$suburbAttribute]
            = $suburb;

        return $jsLayout;
    }
}
