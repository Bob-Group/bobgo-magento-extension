<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Cron;

use BobGroup\BobGo\Model\Config\ApiConfig;
use BobGroup\BobGo\Service\OrderPushService;
use BobGroup\BobGo\Service\OrderSyncPolicy;
use BobGroup\BobGo\Service\OrderSyncQueue;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Drains the order-push outbox.
 *
 * This is what takes the Bob Go HTTP call off the request thread. The observer
 * on sales_order_save_after only writes a queue row; this job, a moment later,
 * does the POST/PATCH. That matters because the observer fires during checkout,
 * on every admin order save, and on invoice and shipment creation — all places
 * where a slow or unreachable API used to be paid for by whoever clicked.
 *
 * It also gives retries for free: a failure leaves the row in place with a
 * backoff, so a transient API problem resolves itself instead of needing an
 * operator to re-save the order.
 */
class PushOrders
{
    /**
     * Orders per run. At one run a minute this is far more headroom than any
     * store will need, and it keeps a backlog from monopolising the cron slot.
     */
    private const BATCH_SIZE = 50;

    private OrderSyncQueue $queue;
    private OrderRepositoryInterface $orderRepository;
    private OrderPushService $orderPushService;
    private OrderSyncPolicy $policy;
    private ApiConfig $apiConfig;
    private LoggerInterface $logger;

    public function __construct(
        OrderSyncQueue $queue,
        OrderRepositoryInterface $orderRepository,
        OrderPushService $orderPushService,
        OrderSyncPolicy $policy,
        ApiConfig $apiConfig,
        LoggerInterface $logger
    ) {
        $this->queue = $queue;
        $this->orderRepository = $orderRepository;
        $this->orderPushService = $orderPushService;
        $this->policy = $policy;
        $this->apiConfig = $apiConfig;
        $this->logger = $logger;
    }

    public function execute(): void
    {
        if (!$this->apiConfig->isOrderPushEnabled() || !$this->apiConfig->isConfigured()) {
            return;
        }

        foreach ($this->queue->claim(self::BATCH_SIZE) as $orderId) {
            $this->processOne($orderId);
        }
    }

    private function processOne(int $orderId): void
    {
        try {
            $order = $this->orderRepository->get($orderId);
        } catch (\Throwable $e) {
            // Deleted, or never existed. Nothing to retry.
            $this->queue->release($orderId);
            return;
        }

        // Re-check the policy here, not just at enqueue time: this job runs after
        // the save that queued it, and the order may have moved on since — an
        // order queued while `processing` can be `canceled` by the time we get here.
        if (!$this->policy->shouldPush($order)) {
            $this->queue->release($orderId);
            return;
        }

        try {
            if ($this->push($order) && $this->forwardStatus($order)) {
                $this->queue->release($orderId);
                return;
            }
            // Something reported failure. It has already been logged and recorded
            // on the order; back off and try again.
            $this->queue->defer($orderId);
        } catch (\Throwable $e) {
            $this->logger->error('Bob Go: order push job failed', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
            $this->queue->defer($orderId);
        }
    }

    private function push(OrderInterface $order): bool
    {
        return $this->policy->hasLink($order)
            ? $this->orderPushService->updateOrder($order)
            : $this->orderPushService->pushOrder($order);
    }

    /**
     * Forward `cancelled` / `completed` once the order data itself is in sync.
     *
     * Deliberately a separate call from the ordinary update: Bob Go's create POST
     * accepts no status field, and keeping status out of the routine PATCH means
     * the catch-up wave after any payload-shape change can't accidentally
     * re-assert a terminal status.
     */
    private function forwardStatus(OrderInterface $order): bool
    {
        $status = $this->policy->statusToForward($order);
        if ($status === null) {
            return true;
        }
        return $this->orderPushService->pushStatus($order, $status);
    }
}
