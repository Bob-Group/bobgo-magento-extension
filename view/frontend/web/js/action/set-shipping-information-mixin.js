/*jshint browser:true jquery:true*/
/*global alert*/
define([
    'jquery',
    'mage/utils/wrapper',
    'Magento_Checkout/js/model/quote'
], function ($, wrapper, quote) {
    'use strict';

    return function (setShippingInformationAction) {

        return wrapper.wrap(setShippingInformationAction, function (originalAction) {
            var shippingAddress = quote.shippingAddress();
            if (shippingAddress['extension_attributes'] === undefined) {
                shippingAddress['extension_attributes'] = {};
            }
            //This is supposed to be the mixin that adds the suburb to the shipping address, but it doesn't work
/*            var attribute = shippingAddress.customAttributes.find(
                function (element) {
                    return element.attribute_code === 'suburb';
                }
            );

            shippingAddress['extension_attributes']['suburb'] = attribute.value;

           shippingAddress['extension_attributes']['suburb'] = shippingAddress.value;

            pass execution to original action ('Magento_Checkout/js/action/set-shipping-information')*/
            //after all the of the above the suburb is still not added to the request payload that goes to CustomShipping.php
            return originalAction();
        });
    };
});
