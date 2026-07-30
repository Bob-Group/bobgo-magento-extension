<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Observer;

use BobGroup\BobGo\Model\Config\ApiConfig;
use BobGroup\BobGo\Service\InboundGuard;
use BobGroup\BobGo\Service\OrderSyncPolicy;
use BobGroup\BobGo\Service\OrderSyncQueue;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Queues orders for pushing to Bob Go when they are saved.
 *
 * Note what this observer does NOT do: talk to Bob Go. It writes one row to the
 * outbox and returns; Cron\PushOrders does the POST/PATCH a moment later.
 *
 * That split matters because of where this event fires. `sales_order_save_after`
 * runs on checkout, on every admin order save, on invoice creation, on shipment
 * creation, and from our own webhook handlers. Doing the HTTP call inline meant
 * every one of those paid for it — and a slow or unreachable Bob Go was paid for
 * by the customer placing the order. It also means retries are now free: a
 * failed push stays queued with a backoff instead of waiting for someone to
 * re-save the order.
 *
 * Note also that `etc/events.xml` is global, not frontend-only, so this runs in
 * the admin, in cron, and during webhook processing too.
 */
class OrderSaveObserver implements ObserverInterface
{
    private OrderSyncQueue $queue;
    private OrderSyncPolicy $policy;
    private ApiConfig $apiConfig;
    private InboundGuard $inboundGuard;
    private LoggerInterface $logger;

    public function __construct(
        OrderSyncQueue $queue,
        OrderSyncPolicy $policy,
        ApiConfig $apiConfig,
        InboundGuard $inboundGuard,
        LoggerInterface $logger
    ) {
        $this->queue = $queue;
        $this->policy = $policy;
        $this->apiConfig = $apiConfig;
        $this->inboundGuard = $inboundGuard;
        $this->logger = $logger;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        try {
            $order = $observer->getEvent()->getOrder();
            if (!$order) {
                $this->logger->warning('Bob Go: OrderSaveObserver received event without order');
                return;
            }

            if (!$this->apiConfig->isOrderPushEnabled() || !$this->apiConfig->isConfigured()) {
                return;
            }

            $orderId = (int) $order->getEntityId();

            // This save is Bob Go's own change coming back to us. Sending it
            // straight back out is pointless; the sync-hash check would stop the
            // API call anyway, but that makes the loop protection accidental
            // rather than deliberate.
            if ($this->inboundGuard->isActive($orderId)) {
                return;
            }

            // Cheap gate so the queue doesn't fill with orders the job would only
            // throw away. The job re-checks anyway, since state can change
            // between here and there.
            if (!$this->policy->shouldPush($order)) {
                return;
            }

            $this->queue->enqueue($orderId);
        } catch (\Throwable $e) {
            // Order saving is never blocked by Bob Go.
            $this->logger->error('Bob Go: OrderSaveObserver failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
