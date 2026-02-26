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

            if (shippingAddress.customAttributes && Array.isArray(shippingAddress.customAttributes)) {
                var attribute = shippingAddress.customAttributes.find(
                    function (element) {
                        return element.attribute_code === 'suburb';
                    }
                );

                if (attribute && attribute.value) {
                    shippingAddress['extension_attributes']['suburb'] = attribute.value;
                }
            }

            return originalAction();
        });
    };
});
