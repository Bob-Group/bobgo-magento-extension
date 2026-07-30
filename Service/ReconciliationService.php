<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Service;

use BobGroup\BobGo\Model\Config\ApiConfig;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * Safety-net job that catches webhook deliveries we missed.
 *
 * For each order in an active fulfilment state, refetch the authoritative
 * shipments list from Bob Go (GET /v2/order-fulfillments?order_id=...) and
 * persist it on the order. Webhooks remain the primary signal — this just
 * closes the gap when one is lost or delayed.
 *
 * Bob Go is the source of truth. The actual per-order work lives in
 * FulfilmentSyncService, which is the exact same code path the webhook handlers
 * use — that is what makes this a real safety net rather than a display refresh:
 * a fulfilment whose webhook was never processed gets its Magento shipment
 * created here, within the hour.
 *
 * This class owns only the batch: which orders to look at, and how many.
 *
 * Designed to be cron-driven (hourly) and batched (BATCH_SIZE per run).
 */
class ReconciliationService
{
    public const BATCH_SIZE = 100;

    /**
     * How far back to look for completed orders that may still receive
     * tracking updates from Bob Go. Catches late checkpoints (e.g. proof of
     * delivery uploaded a day after the order auto-completed) without
     * dragging every historical order into the batch.
     */
    private const COMPLETE_LOOKBACK_DAYS = 14;

    /**
     * Order states reconciled on every run, regardless of age. Stuck orders
     * (e.g. PROCESSING for 30 days because the merchant is slow to ship)
     * must remain in the pool — that's exactly the case reconciliation
     * exists for.
     */
    private const ACTIVE_STATES = [
        Order::STATE_PROCESSING,
        Order::STATE_HOLDED,
        Order::STATE_NEW,
    ];

    private OrderRepositoryInterface $orderRepository;
    private SearchCriteriaBuilder $searchCriteriaBuilder;
    private FulfilmentSyncService $fulfilmentSync;
    private ApiConfig $apiConfig;
    private WebhookSubscriptionService $webhookSubscriptions;
    private LoggerInterface $logger;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        FulfilmentSyncService $fulfilmentSync,
        ApiConfig $apiConfig,
        WebhookSubscriptionService $webhookSubscriptions,
        LoggerInterface $logger
    ) {
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->fulfilmentSync = $fulfilmentSync;
        $this->apiConfig = $apiConfig;
        $this->webhookSubscriptions = $webhookSubscriptions;
        $this->logger = $logger;
    }

    /**
     * Cron entry point. No-op when the merchant has disabled fulfilment sync
     * or hasn't configured an API key yet.
     */
    public function run(): void
    {
        if (!$this->apiConfig->isFulfillmentSyncEnabled() || !$this->apiConfig->isConfigured()) {
            return;
        }

        // Piggyback the webhook-subscription health check on this job. It is
        // internally rate-limited to one conclusive check per day, and it is the
        // only way to notice that Bob Go disabled our subscription — nothing
        // tells us when that happens.
        $this->webhookSubscriptions->verifyAndRepair();

        $orders = $this->loadCandidateOrders();
        if (empty($orders)) {
            return;
        }

        $this->logger->info('Bob Go reconciliation: starting batch', [
            'count' => count($orders),
        ]);

        foreach ($orders as $order) {
            $this->reconcileOrder($order);
        }
    }

    /**
     * Reconcile a single order. Public so an admin "Run reconciliation now"
     * button (or a Resync action) can target one order at a time.
     */
    public function reconcileOrder(OrderInterface $order): void
    {
        try {
            // Re-read the link from the order we're about to touch rather than
            // trusting a value captured when the batch was built: a webhook can
            // relink an order mid-run, and refreshing under a stale link would
            // write another order's fulfilments onto this one.
            $this->fulfilmentSync->syncOrder($order);
        } catch (\Throwable $e) {
            // Per-order isolation: one bad order must not end the batch.
            $this->logger->error('Bob Go reconciliation: order failed', [
                'order_id' => $order->getEntityId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return OrderInterface[]
     */
    /**
     * Build the reconciliation batch.
     *
     * Two scoped queries (rather than one with a date filter) because
     * SearchCriteriaBuilder ANDs filter groups together — putting
     * `updated_at >= X` on the same builder would exclude long-stuck
     * active-state orders, which are exactly what we need to reconcile.
     *
     * - Active states (NEW/PROCESSING/HOLDED): every run, regardless of age.
     * - COMPLETE: only orders touched within the lookback window, so we
     *   catch late tracking checkpoints (proof of delivery, etc.) without
     *   pulling in every historical order on every cron tick.
     *
     * The two result sets are merged and deduped by entity id, then capped
     * at BATCH_SIZE so the cron run stays bounded on busy stores.
     *
     * @return OrderInterface[]
     */
    private function loadCandidateOrders(): array
    {
        $active = $this->loadOrdersForStates(self::ACTIVE_STATES);
        $complete = $this->loadCompleteOrdersInLookback();

        $merged = [];
        foreach (array_merge($active, $complete) as $order) {
            $id = (int) $order->getEntityId();
            if ($id <= 0 || isset($merged[$id])) {
                continue;
            }
            $merged[$id] = $order;
            if (count($merged) >= self::BATCH_SIZE) {
                break;
            }
        }
        return array_values($merged);
    }

    /**
     * @param string[] $states
     * @return OrderInterface[]
     */
    private function loadOrdersForStates(array $states): array
    {
        $criteria = $this->searchCriteriaBuilder
            ->addFilter('bobgo_order_id', null, 'notnull')
            ->addFilter('state', $states, 'in')
            ->setPageSize(self::BATCH_SIZE)
            ->create();

        return $this->orderRepository->getList($criteria)->getItems();
    }

    /**
     * @return OrderInterface[]
     */
    private function loadCompleteOrdersInLookback(): array
    {
        $lookbackDate = gmdate('Y-m-d H:i:s', time() - (self::COMPLETE_LOOKBACK_DAYS * 86400));

        $criteria = $this->searchCriteriaBuilder
            ->addFilter('bobgo_order_id', null, 'notnull')
            ->addFilter('state', Order::STATE_COMPLETE, 'eq')
            ->addFilter('updated_at', $lookbackDate, 'gteq')
            ->setPageSize(self::BATCH_SIZE)
            ->create();

        return $this->orderRepository->getList($criteria)->getItems();
    }

}
