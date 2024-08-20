<?php

namespace BobGroup\BobGo\Model\Carrier;

/**
 * Data class for managing API endpoints in the BobGo module.
 */
class UData
{
    /**
     * Tracking Endpoint
     *
     * @var string
     */
    public const TRACKING = 'https://api.bobgo.co.za/tracking?channel=%s&tracking_reference=%s';

    /**
     * Rates API Endpoint
     *
     * @var string
     */
    public const RATES_ENDPOINT = 'https://api.bobgo.co.za/rates-at-checkout/magento';
}
