<?php

namespace BobGroup\BobGo\Model\Carrier;

/**
 * Class uData
 * @package BobGroup\BobGo\Model\Carrier
 */
class uData
{

    /** Tracking Endpoint */
    //dev
    public const TRACKING = 'https://api.dev.bobgo.co.za/tracking?channel=localhost&tracking_reference=';

    //production
    //public const TRACKING = 'https://api.dev.bobgo.co.za/tracking?channel=%s&tracking_reference=%s';

    /*** RATES API Endpoint*/
    public const RATES_ENDPOINT = 'https://api.dev.bobgo.co.za/rates-at-checkout/magento';
}
