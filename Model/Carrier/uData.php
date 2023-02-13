<?php

namespace bobgo\CustomShipping\Model\Carrier;

/**
 * Class uData
 * Bob Go API Resources for Custom Shipping
 * @package bobgo\CustomShipping\Model\Carrier
 */
class uData
{
    /** Tracking Endpoint */
    public const TRACKING = 'https://api.dev.ship.uafrica.com/tracking?channel=%s&tracking_reference=%s';
    /*** RATES API Endpoint*/
    public const RATES_ENDPOINT = 'https://api.dev.ship.uafrica.com/rates-at-checkout/magento';
}
