var config = {
    config: {
        mixins: {
            // Add suburb to shipping address extension attributes
            'Magento_Checkout/js/action/set-shipping-information': {
                'BobGroup_BobGo/js/action/set-shipping-information-mixin': true
            },
            // Drop the "carrier_title - method_title" separator when carrier_title is empty
            'Magento_Checkout/js/view/shipping-information': {
                'BobGroup_BobGo/js/view/shipping-information-mixin': true
            }
        }
    }
};
