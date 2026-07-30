<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Service;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * Webhook-facing side effects for inbound Bob Go events.
 *
 * Deliberately thin. Webhooks are treated as *triggers*, not as data: each
 * handler stamps the last-webhook timestamp, then hands off to
 * FulfilmentSyncService, which re-fetches authoritative state from Bob Go and
 * reconciles against it. Nothing here patches local fulfilment state from the
 * webhook body, which is what makes duplicate and out-of-order deliveries safe.
 *
 * The one exception is the payload's own line items: they are passed through as
 * a fallback for the rare case where the authoritative record doesn't enumerate
 * which items a fulfilment covers.
 *
 * Every entry point takes an already-resolved order — see OrderResolver for why
 * resolution is neither trivial nor safe to do here.
 */
class FulfillmentService
{
    private OrderRepositoryInterface $orderRepository;
    private OrderManagementInterface $orderManagement;
    private FulfilmentSyncService $fulfilmentSync;
    private OrderPushService $orderPushService;
    private DateTime $dateTime;
    private LoggerInterface $logger;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        OrderManagementInterface $orderManagement,
        FulfilmentSyncService $fulfilmentSync,
        OrderPushService $orderPushService,
        DateTime $dateTime,
        LoggerInterface $logger
    ) {
        $this->orderRepository = $orderRepository;
        $this->orderManagement = $orderManagement;
        $this->fulfilmentSync = $fulfilmentSync;
        $this->orderPushService = $orderPushService;
        $this->dateTime = $dateTime;
        $this->logger = $logger;
    }

    /**
     * Handle `fulfillment/created`.
     *
     * @param array<string,mixed> $data
     * @throws TransientWebhookException
     */
    public function processFulfillment(OrderInterface $order, array $data): void
    {
        $this->recordWebhook($order);

        $webhookItems = is_array($data['order_items'] ?? null) ? $data['order_items'] : [];
        $this->fulfilmentSync->syncOrder($order, $webhookItems);
    }

    /**
     * Handle `tracking/updated`.
     *
     * The refresh does the substantive work — the shipment and its tracking row
     * come from the authoritative fetch, so this no longer depends on
     * fulfillment/created having landed first. The status comment is added from
     * the payload because it is the human-readable checkpoint text and putting
     * it in order history is what gives the merchant a timeline.
     *
     * @param array<string,mixed> $data
     * @throws TransientWebhookException
     */
    public function processTrackingUpdate(OrderInterface $order, array $data): void
    {
        // On this topic the top-level `id` is the tracking-reference string, not
        // an id of anything — Bob Go sends no order id here at all.
        $trackingNumber = (string) ($data['shipment_tracking_reference'] ?? ($data['id'] ?? ''));
        $statusFriendly = (string) ($data['status_friendly'] ?? ($data['status'] ?? ''));

        $comment = null;
        if ($statusFriendly !== '') {
            $comment = $trackingNumber !== ''
                ? sprintf('Bob Go tracking update: %s (ref: %s)', $statusFriendly, $trackingNumber)
                : sprintf('Bob Go tracking update: %s', $statusFriendly);
        }

        $this->recordWebhook($order, $comment);
        $this->fulfilmentSync->syncOrder($order);
    }

    /**
     * Handle `order/updated`.
     *
     * Bob Go sends the full order object here, and re-fires on any relevant
     * change including bulk operations, so this must be idempotent. Only
     * cancellation is acted on: it is the one state transition Bob Go owns that
     * the store cannot infer for itself.
     *
     * @param array<string,mixed> $data
     */
    public function processOrderUpdate(OrderInterface $order, array $data): void
    {
        $this->recordWebhook($order);

        $status = strtolower(trim((string) ($data['status'] ?? '')));
        if ($status !== 'cancelled' && $status !== 'canceled') {
            return;
        }

        $this->cancelOrder($order);
    }

    /**
     * Cancel the Magento order because Bob Go says it was cancelled.
     */
    private function cancelOrder(OrderInterface $order): void
    {
        $orderId = (int) $order->getEntityId();

        /** @var \Magento\Sales\Model\Order $order */
        if ($order->getState() === \Magento\Sales\Model\Order::STATE_CANCELED) {
            return;
        }

        try {
            if (!$this->orderManagement->cancel($orderId)) {
                // Magento refuses to cancel once anything is invoiced or
                // shipped. Nothing we can do about that from here, but the
                // operator needs to know Bob Go and Magento now disagree.
                $this->logger->warning('Bob Go: Magento refused to cancel an order cancelled on Bob Go', [
                    'order_id' => $orderId,
                    'state' => $order->getState(),
                ]);
                return;
            }

            $this->logger->info('Bob Go: order cancelled from inbound webhook', ['order_id' => $orderId]);

            // Cancelling flips the derived payment_status (total_due drops to
            // zero, so unpaid -> paid), which changes the outbound payload hash
            // and would make the next save PATCH that meaningless change straight
            // back to Bob Go. Re-baseline the hash against the post-cancel
            // payload so the dirty check stays quiet.
            $this->orderPushService->refreshSyncHash($this->orderRepository->get($orderId));
        } catch (\Throwable $e) {
            $this->logger->error('Bob Go: failed to cancel order from inbound webhook', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Record that Bob Go contacted us about this order, plus any order-history
     * comment the event warrants — in a single save.
     *
     * One save rather than one per concern: every order save re-fires
     * sales_order_save_after, which runs the outbound push observer, so a
     * handler that saved three times made the webhook three times as expensive
     * for no benefit.
     *
     * Failures are swallowed. Neither the timestamp nor the comment is worth
     * failing a delivery over — the substantive work is the API refresh, and a
     * 500 here would make Bob Go retry the whole thing.
     */
    private function recordWebhook(OrderInterface $order, ?string $comment = null): void
    {
        try {
            $order->setData('bobgo_last_webhook', $this->dateTime->gmtDate());
            if ($comment !== null && $comment !== '') {
                /** @var \Magento\Sales\Model\Order $order */
                $order->addCommentToStatusHistory($comment);
            }
            $this->orderRepository->save($order);
        } catch (\Throwable $e) {
            $this->logger->warning('Bob Go: failed to record inbound webhook on the order', [
                'order_id' => $order->getEntityId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
