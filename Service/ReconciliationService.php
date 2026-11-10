<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Service;

use BobGroup\BobGo\Model\Config\ApiConfig;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\FlagManager;
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
     * Page cursor, so successive runs work through the whole population instead
     * of re-scanning the same first page forever.
     */
    private const PAGE_FLAG = 'bobgo_reconcile_page';

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
    private StoreScope $storeScope;
    private FlagManager $flagManager;
    private LoggerInterface $logger;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        FulfilmentSyncService $fulfilmentSync,
        ApiConfig $apiConfig,
        WebhookSubscriptionService $webhookSubscriptions,
        StoreScope $storeScope,
        FlagManager $flagManager,
        LoggerInterface $logger
    ) {
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->fulfilmentSync = $fulfilmentSync;
        $this->apiConfig = $apiConfig;
        $this->webhookSubscriptions = $webhookSubscriptions;
        $this->storeScope = $storeScope;
        $this->flagManager = $flagManager;
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

        $page = $this->currentPage();
        $orderIds = $this->loadCandidateOrderIds($page);

        if (empty($orderIds)) {
            // Ran off the end of the population — start again from the top next
            // hour rather than sitting on an empty page forever.
            $this->setPage(1);
            return;
        }

        $this->logger->info('Bob Go reconciliation: starting batch', [
            'count' => count($orderIds),
            'page' => $page,
        ]);

        foreach ($orderIds as $orderId) {
            // Load fresh inside the loop rather than reusing objects captured
            // when the batch was built: a webhook can relink an order mid-run,
            // and refreshing under a stale link would write another order's
            // fulfilments onto this one.
            try {
                $order = $this->orderRepository->get($orderId);
            } catch (\Throwable $e) {
                continue;
            }
            $this->reconcileOrder($order);
        }

        // A short page means this was the last one.
        $this->setPage(count($orderIds) < self::BATCH_SIZE ? 1 : $page + 1);
    }

    /**
     * Reconcile a single order. Public so an admin "Run reconciliation now"
     * button (or a Resync action) can target one order at a time.
     */
    public function reconcileOrder(OrderInterface $order): void
    {
        try {
            // Emulate the order's store: cron has no store context, so the API
            // key and channel identifier would otherwise come from the default
            // store rather than the order's.
            $this->storeScope->forOrder($order, function () use ($order) {
                $this->fulfilmentSync->syncOrder($order);
            });
        } catch (\Throwable $e) {
            // Per-order isolation: one bad order must not end the batch.
            $this->logger->error('Bob Go reconciliation: order failed', [
                'order_id' => $order->getEntityId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build the reconciliation batch, as order ids.
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
     * Both are paged by the same cursor so a store with more active orders than
     * BATCH_SIZE works through all of them across successive runs instead of
     * re-scanning the first page every hour and leaving the tail permanently
     * stale. The two scopes share the cursor, which means the (much smaller,
     * 14-day-bounded) complete set is only revisited when the cursor is back on
     * page 1 — acceptable for a safety net, and far better than never reaching
     * the active tail at all.
     *
     * @return int[]
     */
    private function loadCandidateOrderIds(int $page): array
    {
        $active = $this->loadOrderIds($this->activeStatesCriteria($page));
        $complete = $this->loadOrderIds($this->completeLookbackCriteria($page));

        $merged = [];
        foreach (array_merge($active, $complete) as $orderId) {
            if ($orderId <= 0 || isset($merged[$orderId])) {
                continue;
            }
            $merged[$orderId] = true;
            if (count($merged) >= self::BATCH_SIZE) {
                break;
            }
        }
        return array_keys($merged);
    }

    /**
     * @param \Magento\Framework\Api\SearchCriteriaInterface $criteria
     * @return int[]
     */
    private function loadOrderIds($criteria): array
    {
        $ids = [];
        foreach ($this->orderRepository->getList($criteria)->getItems() as $order) {
            $ids[] = (int) $order->getEntityId();
        }
        return $ids;
    }

    /**
     * @return \Magento\Framework\Api\SearchCriteriaInterface
     */
    private function activeStatesCriteria(int $page)
    {
        return $this->searchCriteriaBuilder
            ->addFilter('bobgo_order_id', null, 'notnull')
            ->addFilter('state', self::ACTIVE_STATES, 'in')
            ->setPageSize(self::BATCH_SIZE)
            ->setCurrentPage($page)
            ->create();
    }

    /**
     * @return \Magento\Framework\Api\SearchCriteriaInterface
     */
    private function completeLookbackCriteria(int $page)
    {
        $lookbackDate = gmdate('Y-m-d H:i:s', time() - (self::COMPLETE_LOOKBACK_DAYS * 86400));

        return $this->searchCriteriaBuilder
            ->addFilter('bobgo_order_id', null, 'notnull')
            ->addFilter('state', Order::STATE_COMPLETE, 'eq')
            ->addFilter('updated_at', $lookbackDate, 'gteq')
            ->setPageSize(self::BATCH_SIZE)
            ->setCurrentPage($page)
            ->create();
    }

    private function currentPage(): int
    {
        $stored = $this->flagManager->getFlagData(self::PAGE_FLAG);
        $page = is_numeric($stored) ? (int) $stored : 1;
        return $page > 0 ? $page : 1;
    }

    private function setPage(int $page): void
    {
        try {
            $this->flagManager->saveFlag(self::PAGE_FLAG, max(1, $page));
        } catch (\Throwable $e) {
            // Worst case we re-scan the same page next hour.
            $this->logger->warning('Bob Go reconciliation: could not persist the page cursor', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
