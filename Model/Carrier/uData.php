<?php

namespace BobGroup\BobGo\Model\Carrier;

/**
 * Class uData
 * @package BobGroup\BobGo\Model\Carrier
 */
class uData
{

    /** Tracking Endpoint */
    public const TRACKING = 'https://api.bobgo.co.za/tracking?channel=localhost&tracking_reference=';

    /*** RATES API Endpoint*/
    public const RATES_ENDPOINT = 'https://api.bobgo.co.za/rates-at-checkout/magento';
}
