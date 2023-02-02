<?php

namespace bobgo\Customshipping\Model\Carrier;

/**
 * Class uData
 * bobGo API Resources
 * @package bobgo\Customshipping\Model\Carrier
 */
class uData
{
    /** Tracking Endpoint */
    public const TRACKING = 'https://api.dev.ship.uafrica.com/tracking?channel=localhost&tracking_reference=';
    /*** RATES API Endpoint*/
    public const RATES_ENDPOINT = 'https://api.dev.ship.uafrica.com/rates-at-checkout/magento';
}
