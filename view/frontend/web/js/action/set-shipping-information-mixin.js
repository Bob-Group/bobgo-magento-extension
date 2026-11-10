define([
    'jquery',
    'mage/utils/wrapper',
    'Magento_Checkout/js/model/quote'
], function ($, wrapper, quote) {
    'use strict';

    /**
     * Extract the suburb value from whichever custom-attribute shape the
     * current Magento build is using.
     *
     * Shapes observed in the wild:
     *   1. Object map:   { suburb: 'Sandton' }
     *   2. Object map of objects:   { suburb: { value: 'Sandton' } }
     *   3. List of {attribute_code, value}:   [ { attribute_code: 'suburb', value: 'Sandton' } ]
     *
     * Returns the suburb string, or null if not found / empty.
     */
    function extractSuburb(customAttributes) {
        if (!customAttributes) {
            return null;
        }

        if (Array.isArray(customAttributes)) {
            for (var i = 0; i < customAttributes.length; i++) {
                var entry = customAttributes[i];
                if (entry && entry.attribute_code === 'suburb' && entry.value) {
                    return entry.value;
                }
            }
            return null;
        }

        if (typeof customAttributes === 'object') {
            var val = customAttributes.suburb;
            if (!val) {
                return null;
            }
            if (typeof val === 'object') {
                return val.value || null;
            }
            return val;
        }

        return null;
    }

    return function (setShippingInformationAction) {

        return wrapper.wrap(setShippingInformationAction, function (originalAction) {
            var shippingAddress = quote.shippingAddress();

            if (shippingAddress.extension_attributes === undefined) {
                shippingAddress.extension_attributes = {};
            }

            // The suburb custom field is bound to
            // shippingAddress.custom_attributes.suburb (see
            // LayoutProcessorPlugin). Copy its value into extension_attributes
            // so it flows through to the order address — without this step
            // the suburb is rendered on the checkout but never reaches the
            // server-side address.
            var suburb = extractSuburb(shippingAddress.custom_attributes)
                || extractSuburb(shippingAddress.customAttributes);
            if (suburb) {
                shippingAddress.extension_attributes.suburb = suburb;
            }

            return originalAction();
        });
    };
});
