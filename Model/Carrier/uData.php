<?php

namespace uafrica\Customshipping\Model\Carrier;

/**
 * Class uData
 * @package uafrica\Customshipping\Model\Carrier
 */
class uData
{

    /** Tracking Endpoint */
    public const TRACKING = 'https://api.dev.ship.uafrica.com/tracking?channel=localhost&tracking_reference=';
    /*** RATES API Endpoint*/
    public const RATES_ENDPOINT = 'https://api.dev.ship.uafrica.com/rates-at-checkout/woocommerce';
}
