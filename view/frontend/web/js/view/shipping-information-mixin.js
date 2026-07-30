define([
    'mage/utils/wrapper',
    'Magento_Checkout/js/model/quote'
], function (wrapper, quote) {
    'use strict';

    return function (target) {
        target.prototype.getShippingMethodTitle = wrapper.wrap(
            target.prototype.getShippingMethodTitle,
            function (originalFn) {
                var shippingMethod = quote.shippingMethod();
                if (!shippingMethod) {
                    return originalFn();
                }

                var carrierTitle = shippingMethod['carrier_title'] || '';
                var methodTitle = shippingMethod['method_title'] || '';

                if (carrierTitle === '') {
                    return methodTitle;
                }
                if (methodTitle === '') {
                    return carrierTitle;
                }

                return originalFn();
            }
        );

        return target;
    };
});
