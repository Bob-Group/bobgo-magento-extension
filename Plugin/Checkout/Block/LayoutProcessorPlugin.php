<?php
//namespace BobGroup\BobGo\Plugin\Checkout\Block;
//
//use Magento\Checkout\Block\Checkout\LayoutProcessor;
//
///**
// * Class LayoutProcessorPlugin
// * @package BobGroup\BobGo\Plugin\Checkout\Block
// * This class is supposed to add a new field to the checkout page for the suburb. It is supposed to be used in conjunction with the SaveOrderBeforeSalesModelQuote observer.
// * At the moment, it overrides the 2 Address fields and adds a new 3 Address field to accommodate the suburb on the checkout page( including placeholders).
// * This is not the best way to do it, but it is the only way I could get it to work.
// */
//class LayoutProcessorPlugin
//{
//    /**
//     * @param \Magento\Checkout\Block\Checkout\LayoutProcessor $subject
//     * @param array $jsLayout
//     * @return array
//     */
//
//    public function afterProcess(
//        \Magento\Checkout\Block\Checkout\LayoutProcessor $subject,
//        array  $jsLayout
//    ) {
//        $suburbAttribute = 'suburb';
//        $suburb = [
//            'component' => 'Magento_Ui/js/form/element/abstract',
//            'config' => [
//                'customScope' => 'shippingAddress.custom_attributes',
//                'customEntry' => null,
//                'template' => 'ui/form/field',
//                'elementTmpl' => 'ui/form/element/input',
//                'tooltip' => [
//                    'description' => 'Required for shipping accuracy',
//                ],
//            ],
//            'dataScope' => 'shippingAddress.custom_attributes' . '.' . $suburbAttribute,
//            'label' => 'Suburb',
//            'provider' => 'checkoutProvider',
//            'sortOrder' => 80,
//            'validation' => [
//                'required-entry' => true
//            ],
//            'options' => [],
//            'filterBy' => null,
//            'customEntry' => null,
//            'visible' => true,
//            'value' => '' // value field is used to set a default value of the attribute
//        ];
//
//        $jsLayout['components']['checkout']['children']['steps']['children']['shipping-step']['children']['shippingAddress']['children']['shipping-address-fieldset']['children'][$suburbAttribute] = $suburb;
//
//        return $jsLayout;
//    }
//}
