/*required for adding the mixing that is supposed to add the suburb to the shipping address
set-shipping-information-mixin.js*/
var config = {
    config: {
        mixins: {
            'Magento_Checkout/js/action/set-shipping-information': {
                'BobGroup_BobGo/js/action/set-shipping-information-mixin': true
            }
        }
    }
};
