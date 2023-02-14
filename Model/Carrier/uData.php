<?php

namespace bobgo\CustomShipping\Model\Carrier;

/**
 * Class uData
 * bobGo API Resources for Custom Shipping
 * @package bobgo\CustomShipping\Model\Carrier
 */
class uData
{
    /** Tracking Endpoint */
    //dev
    public const TRACKING = 'https://api.dev.ship.uafrica.com/tracking?channel=localhost&tracking_reference=';
    //production
   // public const TRACKING = 'https://api.dev.ship.uafrica.com/tracking?channel=%s&tracking_reference=%s';

    /*** RATES API Endpoint*/
    public const RATES_ENDPOINT = 'https://api.dev.ship.uafrica.com/rates-at-checkout/magento';
}
