<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Service;

use BobGroup\BobGo\Api\BobGoApiClient;
use BobGroup\BobGo\Api\BobGoApiException;
use BobGroup\BobGo\Model\Config\ApiConfig;
use BobGroup\BobGo\Model\SyncLog;
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
 * Bob Go is the source of truth: we full-replace bobgo_shipments on the
 * order. We only mutate the order's own state (status, last_synced) when
 * something actually changed, so a quiet run is a no-op.
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
     * Order states eligible for reconciliation. "Active" states are always
     * included; STATE_COMPLETE is also reconciled within the lookback window
     * via an additional updated_at filter — see loadCandidateOrders().
     */
    private const ACTIVE_STATES = [
        Order::STATE_PROCESSING,
        Order::STATE_HOLDED,
        Order::STATE_NEW,
        Order::STATE_COMPLETE,
    ];

    private OrderRepositoryInterface $orderRepository;
    private SearchCriteriaBuilder $searchCriteriaBuilder;
    private BobGoApiClient $apiClient;
    private ApiConfig $apiConfig;
    private SyncLogger $syncLogger;
    private LoggerInterface $logger;
    private \Magento\Framework\Stdlib\DateTime\DateTime $dateTime;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        BobGoApiClient $apiClient,
        ApiConfig $apiConfig,
        SyncLogger $syncLogger,
        LoggerInterface $logger,
        \Magento\Framework\Stdlib\DateTime\DateTime $dateTime
    ) {
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->apiClient = $apiClient;
        $this->apiConfig = $apiConfig;
        $this->syncLogger = $syncLogger;
        $this->logger = $logger;
        $this->dateTime = $dateTime;
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
        $bobgoOrderId = $order->getData('bobgo_order_id');
        if ($bobgoOrderId === null || $bobgoOrderId === '') {
            return;
        }

        try {
            $response = $this->apiClient->get('order-fulfillments', ['order_id' => $bobgoOrderId]);
            $shipments = $this->extractShipments($response);

            $previous = (string) ($order->getData('bobgo_shipments') ?? '');
            $next = (string) json_encode($shipments);

            if ($previous !== $next) {
                $order->setData('bobgo_shipments', $next);
                $order->setData('bobgo_last_synced', $this->dateTime->gmtDate());
                $this->orderRepository->save($order);
            }

            $this->syncLogger->logOutbound(
                SyncLog::EVENT_RECONCILIATION_FETCHED,
                ['order_id' => $bobgoOrderId, 'shipment_count' => count($shipments)],
                (int) $order->getEntityId(),
                200,
                true
            );
        } catch (BobGoApiException $e) {
            $this->logger->warning('Bob Go reconciliation: API error', [
                'order_id' => $order->getEntityId(),
                'bobgo_order_id' => $bobgoOrderId,
                'status' => $e->getStatusCode(),
                'error' => $e->getMessage(),
            ]);
            $this->syncLogger->logOutbound(
                SyncLog::EVENT_RECONCILIATION_FETCHED,
                ['order_id' => $bobgoOrderId, 'error' => $e->getMessage()],
                (int) $order->getEntityId(),
                $e->getStatusCode(),
                false
            );
        } catch (\Throwable $e) {
            $this->logger->error('Bob Go reconciliation: unexpected error', [
                'order_id' => $order->getEntityId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return OrderInterface[]
     */
    private function loadCandidateOrders(): array
    {
        $lookbackDate = gmdate(
            'Y-m-d H:i:s',
            time() - (self::COMPLETE_LOOKBACK_DAYS * 86400)
        );

        $criteria = $this->searchCriteriaBuilder
            ->addFilter('bobgo_order_id', null, 'notnull')
            ->addFilter('state', self::ACTIVE_STATES, 'in')
            // Completed orders only stay in the reconciliation pool for
            // COMPLETE_LOOKBACK_DAYS — orders in active states aren't bound
            // by updated_at (we want them every run).
            ->addFilter('updated_at', $lookbackDate, 'gteq')
            ->setPageSize(self::BATCH_SIZE)
            ->create();

        $list = $this->orderRepository->getList($criteria);
        return $list->getItems();
    }

    /**
     * Bob Go's response may put the shipments under various keys depending on
     * the endpoint version. Normalise to a plain list of flat shipment objects
     * the admin block can render directly.
     *
     * @param array<string,mixed> $response
     * @return array<int,array<string,mixed>>
     */
    private function extractShipments(array $response): array
    {
        $raw = [];
        foreach (['order_fulfillments', 'fulfillments', 'shipments', 'data'] as $key) {
            if (isset($response[$key]) && is_array($response[$key])) {
                $raw = array_values($response[$key]);
                break;
            }
        }
        if ($raw === [] && count($response) > 0 && array_keys($response) === range(0, count($response) - 1)) {
            $raw = $response;
        }

        $normalised = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $normalised[] = $this->normaliseShipment($entry);
        }
        return $normalised;
    }

    /**
     * Flatten the Bob Go fulfillment entry shape
     * ({order_fulfillment, shipment, buyer_collection}) into the keys the admin
     * block reads. Tolerates already-flat entries from older response shapes.
     *
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private function normaliseShipment(array $entry): array
    {
        $shipment = is_array($entry['shipment'] ?? null) ? $entry['shipment'] : $entry;
        $provider = is_array($shipment['provider'] ?? null) ? $shipment['provider'] : [];
        $serviceLevel = is_array($shipment['service_level'] ?? null) ? $shipment['service_level'] : [];

        return [
            'tracking_number'          => (string) ($shipment['tracking_reference'] ?? $entry['tracking_number'] ?? ''),
            'provider_tracking_number' => (string) ($shipment['provider_tracking_reference'] ?? ''),
            'courier'                  => (string) ($provider['name'] ?? $entry['courier'] ?? ''),
            'provider_slug'            => (string) ($shipment['provider_slug'] ?? ''),
            'service_level'            => (string) ($serviceLevel['name'] ?? ''),
            'status'                   => (string) ($shipment['status'] ?? $entry['status'] ?? ''),
        ];
    }
}
