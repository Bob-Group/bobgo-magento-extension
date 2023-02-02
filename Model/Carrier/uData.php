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
    public const TRACKING = 'https://api.dev.ship.uafrica.com/tracking?channel=localhost&tracking_reference=';
    /*** RATES API Endpoint*/
    public const RATES_ENDPOINT = 'https://api.dev.ship.uafrica.com/rates-at-checkout/magento';
}
