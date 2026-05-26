<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Model;

use Magento\Framework\Model\AbstractModel;

/**
 * Bob Go sync log entry — one row per inbound or outbound API event.
 */
class SyncLog extends AbstractModel
{
    public const DIRECTION_INBOUND  = 'inbound';
    public const DIRECTION_OUTBOUND = 'outbound';

    public const EVENT_WEBHOOK_RECEIVED        = 'webhook_received';
    public const EVENT_WEBHOOK_REJECTED        = 'webhook_rejected';
    public const EVENT_WEBHOOK_UNKNOWN_TOPIC   = 'webhook_unknown_topic';
    public const EVENT_FULFILLMENT_RECEIVED    = 'fulfillment_received';
    public const EVENT_TRACKING_UPDATED        = 'tracking_updated';
    public const EVENT_ORDER_UPDATED_INBOUND   = 'order_updated_inbound';
    public const EVENT_ORDER_CREATED           = 'order_created';
    public const EVENT_ORDER_UPDATED_OUTBOUND  = 'order_updated_outbound';
    public const EVENT_RECONCILIATION_FETCHED  = 'reconciliation_fetched';

    protected function _construct(): void
    {
        $this->_init(\BobGroup\BobGo\Model\ResourceModel\SyncLog::class);
    }
}
