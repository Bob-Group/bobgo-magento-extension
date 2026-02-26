/* Mixin to add suburb to the shipping address extension attributes */
var config = {
    config: {
        mixins: {
            'Magento_Checkout/js/action/set-shipping-information': {
                'BobGroup_BobGo/js/action/set-shipping-information-mixin': true
            }
        }
    }
};
