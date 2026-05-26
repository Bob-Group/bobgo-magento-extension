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

            // The suburb custom field is bound to
            // shippingAddress.custom_attributes.suburb (see
            // LayoutProcessorPlugin). Copy its value into extension_attributes
            // so it flows through to the order address — without this step
            // the suburb is rendered on the checkout but never reaches the
            // server-side address.
            var customAttributes = shippingAddress['custom_attributes']
                || shippingAddress['customAttributes'];
            if (customAttributes && customAttributes['suburb']) {
                var suburbValue = typeof customAttributes['suburb'] === 'object'
                    ? customAttributes['suburb'].value
                    : customAttributes['suburb'];
                if (suburbValue) {
                    shippingAddress['extension_attributes']['suburb'] = suburbValue;
                }
            }

            return originalAction();
        });
    };
});
