<?php

namespace BobGroup\BobGo\Model\Carrier;

/**
 * Class uData
 * @package BobGroup\BobGo\Model\Carrier
 */
class uData
{

    /** Tracking Endpoint */
    public const TRACKING = 'https://api.dev.bobgo.co.za/tracking?channel=%s&tracking_reference=%s';

    /*** RATES API Endpoint*/
    public const RATES_ENDPOINT = 'https://api.dev.bobgo.co.za/rates-at-checkout/magento';
}
