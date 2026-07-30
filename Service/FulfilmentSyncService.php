<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Service;

use BobGroup\BobGo\Api\BobGoApiClient;
use BobGroup\BobGo\Api\BobGoApiException;
use BobGroup\BobGo\Model\Config\ApiConfig;
use BobGroup\BobGo\Model\SyncLog;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\ShipmentItemCreationInterfaceFactory;
use Magento\Sales\Api\Data\ShipmentTrackCreationInterfaceFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Sales\Api\ShipOrderInterface;
use Psr\Log\LoggerInterface;

/**
 * Makes local fulfilment state match Bob Go's, for one order.
 *
 * WEBHOOKS ARE TRIGGERS, NOT DATA
 *
 * Bob Go is the source of truth for fulfilment; the store is the source of truth
 * for orders. So every inbound signal — a fulfillment/created webhook, a
 * tracking/updated webhook, an hourly reconciliation tick, an admin Resync —
 * funnels into this one method, which re-fetches the authoritative state from
 * GET /v2/order-fulfillments and reconciles against it. Nothing patches local
 * state from a webhook body.
 *
 * That single decision buys three things the previous design didn't have:
 *
 *  - **Out-of-order and duplicate deliveries stop mattering.** tracking/updated
 *    overtaking fulfillment/created used to force a 500-and-retry dance, because
 *    the shipment it wanted to annotate didn't exist yet. Now either delivery
 *    creates whatever is missing.
 *  - **Lost webhooks self-heal.** Reconciliation runs the same code path, so a
 *    delivery that was never processed — order on hold at the time, an
 *    unresolvable reference, a bug — is picked up within the hour. Previously
 *    the webhook was the *only* path that ever created a shipment, so a dropped
 *    one meant an order that shipped in Bob Go and never shipped in Magento,
 *    with no alert and no recovery.
 *  - **Cancelled fulfilments are visible.** Bob Go retains cancelled shipment
 *    records; counting them as shipped pinned WooCommerce orders on "shipped"
 *    forever. They are excluded here.
 */
class FulfilmentSyncService
{
    private const ENDPOINT = 'order-fulfillments';

    /** Placeholder titles that a real courier name is allowed to overwrite. */
    private const PLACEHOLDER_TITLES = ['', 'Bob Go'];

    /**
     * Response wrappers Bob Go has used for the fulfilments list, in order of
     * preference. A bare top-level list is also tolerated.
     */
    private const LIST_KEYS = ['order_fulfillments', 'fulfillments', 'shipments', 'data'];

    /** Keys under which a fulfilment record has carried its line items. */
    private const ITEM_KEYS = ['items', 'order_items', 'fulfillment_items', 'line_items'];

    private BobGoApiClient $apiClient;
    private OrderRepositoryInterface $orderRepository;
    private ShipOrderInterface $shipOrder;
    private ShipmentTrackCreationInterfaceFactory $trackCreationFactory;
    private ShipmentItemCreationInterfaceFactory $itemCreationFactory;
    private ShipmentRepositoryInterface $shipmentRepository;
    private ApiConfig $apiConfig;
    private SyncLogger $syncLogger;
    private DateTime $dateTime;
    private LoggerInterface $logger;

    public function __construct(
        BobGoApiClient $apiClient,
        OrderRepositoryInterface $orderRepository,
        ShipOrderInterface $shipOrder,
        ShipmentTrackCreationInterfaceFactory $trackCreationFactory,
        ShipmentItemCreationInterfaceFactory $itemCreationFactory,
        ShipmentRepositoryInterface $shipmentRepository,
        ApiConfig $apiConfig,
        SyncLogger $syncLogger,
        DateTime $dateTime,
        LoggerInterface $logger
    ) {
        $this->apiClient = $apiClient;
        $this->orderRepository = $orderRepository;
        $this->shipOrder = $shipOrder;
        $this->trackCreationFactory = $trackCreationFactory;
        $this->itemCreationFactory = $itemCreationFactory;
        $this->shipmentRepository = $shipmentRepository;
        $this->apiConfig = $apiConfig;
        $this->syncLogger = $syncLogger;
        $this->dateTime = $dateTime;
        $this->logger = $logger;
    }

    /**
     * Re-fetch Bob Go's fulfilment state for this order and reconcile locally:
     * full-replace the bobgo_shipments blob, then create any Magento shipment
     * that Bob Go knows about and we don't.
     *
     * @param array<int,array<string,mixed>> $webhookItems Line items from the
     *        webhook body, used only as a fallback when the authoritative record
     *        doesn't enumerate its items (see resolveItemRows).
     * @return bool True when the fetch succeeded (whether or not anything changed)
     * @throws TransientWebhookException When creating a shipment failed in a way
     *         a retry could fix. Callers on the webhook path let this become a
     *         500 so Bob Go retries; the cron path catches it per order.
     */
    public function syncOrder(OrderInterface $order, array $webhookItems = []): bool
    {
        $bobgoOrderId = $order->getData('bobgo_order_id');
        if ($bobgoOrderId === null || $bobgoOrderId === '') {
            // No link, no request. Never substitute a Magento identifier into
            // Bob Go's id namespace — that produced 404 floods on the
            // WooCommerce integration, and risked applying a colliding
            // account order's fulfilments to a real customer order.
            return false;
        }

        try {
            $response = $this->apiClient->get(self::ENDPOINT, ['order_id' => $bobgoOrderId]);
        } catch (BobGoApiException $e) {
            $this->logger->warning('Bob Go fulfilment sync: API error', [
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
            return false;
        }

        $fulfilments = $this->extractFulfilments($response);
        $this->persistShipmentsBlob($order, $fulfilments);

        $this->syncLogger->logOutbound(
            SyncLog::EVENT_RECONCILIATION_FETCHED,
            ['order_id' => $bobgoOrderId, 'shipment_count' => count($fulfilments)],
            (int) $order->getEntityId(),
            200,
            true
        );

        $this->applyFulfilments($order, $fulfilments, $webhookItems);

        return true;
    }

    /**
     * Full-replace bobgo_shipments, writing only when the value actually
     * changed — a quiet reconciliation tick must not touch the order row.
     *
     * @param array<int,array<string,mixed>> $fulfilments
     */
    private function persistShipmentsBlob(OrderInterface $order, array $fulfilments): void
    {
        $previous = (string) ($order->getData('bobgo_shipments') ?? '');
        $next = (string) json_encode($fulfilments);

        if ($previous === $next) {
            return;
        }

        $order->setData('bobgo_shipments', $next);
        $order->setData('bobgo_last_synced', $this->dateTime->gmtDate());
        $this->orderRepository->save($order);
    }

    /**
     * Create a Magento shipment for every Bob Go fulfilment we don't have yet.
     *
     * @param array<int,array<string,mixed>> $fulfilments
     * @param array<int,array<string,mixed>> $webhookItems
     * @throws TransientWebhookException
     */
    private function applyFulfilments(OrderInterface $order, array $fulfilments, array $webhookItems): void
    {
        $liveCount = 0;
        foreach ($fulfilments as $fulfilment) {
            if (!$this->isCancelled($fulfilment)) {
                $liveCount++;
            }
        }

        // Identifiers we create during this run. Magento loads and caches the
        // order's shipment collection on first access, so a shipment created for
        // fulfilment #1 is invisible to findShipment() when we get to #2 — and two
        // records sharing a tracking number would then produce two shipments.
        $created = [];

        foreach ($fulfilments as $fulfilment) {
            if ($this->isCancelled($fulfilment)) {
                // Bob Go keeps cancelled fulfilment records. Creating a shipment
                // for one would be wrong, and counting it as shipped is what
                // pinned WooCommerce orders on "shipped" forever.
                continue;
            }
            $this->applyFulfilment($order, $fulfilment, $webhookItems, $liveCount, $created);
        }
    }

    /**
     * @param array<string,mixed> $fulfilment
     * @param array<int,array<string,mixed>> $webhookItems
     * @throws TransientWebhookException
     */
    private function applyFulfilment(
        OrderInterface $order,
        array $fulfilment,
        array $webhookItems,
        int $liveFulfilmentCount,
        array &$created
    ): void {
        $fulfilmentId = (string) ($fulfilment['fulfillment_id'] ?? '');
        $trackingNumber = (string) ($fulfilment['tracking_number'] ?? '');
        $courier = (string) ($fulfilment['courier'] ?? '');

        // Created moments ago in this same run — the cached shipment collection
        // won't show it.
        foreach ([$fulfilmentId, $trackingNumber] as $identifier) {
            if ($identifier !== '' && isset($created[$identifier])) {
                return;
            }
        }

        // Already have it? Then the only thing left to do is keep the courier
        // title fresh — fulfillment/created often lands before Bob Go knows
        // which courier collected, leaving our generic "Bob Go" placeholder.
        $existing = $this->findShipment($order, $fulfilmentId, $trackingNumber);
        if ($existing !== null) {
            $this->refreshTrackTitle($existing, $trackingNumber, $courier);
            return;
        }

        if ($trackingNumber === '' && $fulfilmentId === '') {
            // Nothing to dedup on. Refuse rather than risk creating the same
            // shipment again on the next tick.
            $this->logger->warning('Bob Go fulfilment sync: record has no tracking number or id, skipping', [
                'order_id' => $order->getEntityId(),
            ]);
            return;
        }

        if (!$order->canShip()) {
            // Held, in payment review, cancelled, or already fully shipped.
            // Reconciliation will retry every hour, so this is no longer a
            // permanent drop — but it is still worth surfacing, because a
            // merchant fulfilling in Bob Go while the Magento order is on hold
            // will otherwise wonder where the shipment went.
            $this->logger->warning('Bob Go fulfilment sync: order cannot be shipped', [
                'order_id' => $order->getEntityId(),
                'state' => $order->getState(),
                'fulfillment_id' => $fulfilmentId,
            ]);
            return;
        }

        $itemRows = $this->resolveItemRows($fulfilment, $webhookItems, $liveFulfilmentCount, $order);
        if ($itemRows === null) {
            return;
        }

        $items = $this->buildShipmentItems($order, $itemRows);
        if (!empty($itemRows) && empty($items)) {
            // ShipOrderInterface treats an empty item list as "ship everything",
            // so a fulfilment naming items we can't match must not fall through
            // to a full shipment. Unlike the old webhook-payload path this is
            // NOT transient: the authoritative record won't change on a retry.
            $this->logger->error('Bob Go fulfilment sync: record lists items that are not on the order', [
                'order_id' => $order->getEntityId(),
                'fulfillment_id' => $fulfilmentId,
                'skus' => array_values(array_filter(array_map(
                    static function ($row) {
                        return is_array($row) ? ($row['sku'] ?? null) : null;
                    },
                    $itemRows
                ))),
            ]);
            return;
        }

        $tracks = [];
        if ($trackingNumber !== '') {
            $track = $this->trackCreationFactory->create();
            $track->setTrackNumber($trackingNumber);
            $track->setCarrierCode('bobgo');
            $track->setTitle($courier !== '' ? $courier : 'Bob Go');
            $tracks[] = $track;
        }

        try {
            $shipmentId = $this->shipOrder->execute(
                (int) $order->getEntityId(),
                $items,
                $this->apiConfig->shouldNotifyCustomer(),
                false,
                null,
                $tracks
            );

            foreach ([$fulfilmentId, $trackingNumber] as $identifier) {
                if ($identifier !== '') {
                    $created[$identifier] = true;
                }
            }

            if ($fulfilmentId !== '') {
                $this->stampFulfilmentId((int) $shipmentId, $fulfilmentId);
            }

            $this->logger->info('Bob Go fulfilment sync: shipment created', [
                'order_id' => $order->getEntityId(),
                'increment_id' => $order->getIncrementId(),
                'fulfillment_id' => $fulfilmentId,
                'tracking_number' => $trackingNumber,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Bob Go fulfilment sync: failed to create shipment', [
                'order_id' => $order->getEntityId(),
                'fulfillment_id' => $fulfilmentId,
                'error' => $e->getMessage(),
            ]);
            // Transient: DB lock, a concurrent shipment, a momentary integrity
            // violation. On the webhook path this becomes a 500 so Bob Go
            // retries; on the cron path reconcileOrder() catches it per order.
            throw new TransientWebhookException(
                'Shipment creation failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Which line items does this fulfilment cover?
     *
     * Returns an empty array to mean "the whole order" (what
     * ShipOrderInterface does with an empty item list), or null to mean
     * "unknown — don't ship anything".
     *
     * The distinction matters because defaulting to "ship everything" when we
     * simply don't know the scope is how a partial fulfilment silently closes a
     * whole order. We only take that default when Bob Go reports exactly one
     * live fulfilment for the order, which is the overwhelmingly common case and
     * the one where "everything" is right by definition.
     *
     * @param array<string,mixed> $fulfilment
     * @param array<int,array<string,mixed>> $webhookItems
     * @return array<int,array<string,mixed>>|null
     */
    private function resolveItemRows(
        array $fulfilment,
        array $webhookItems,
        int $liveFulfilmentCount,
        OrderInterface $order
    ): ?array {
        $rows = $fulfilment['items'] ?? [];
        if (!empty($rows)) {
            return $rows;
        }

        // The authoritative record didn't enumerate items. If this call was
        // driven by a webhook that did, trust that.
        if (!empty($webhookItems)) {
            return $webhookItems;
        }

        if ($liveFulfilmentCount === 1) {
            $this->logger->info(
                'Bob Go fulfilment sync: no item detail, shipping the whole order (single fulfilment)',
                ['order_id' => $order->getEntityId()]
            );
            return [];
        }

        $this->logger->error(
            'Bob Go fulfilment sync: cannot determine which items a fulfilment covers, skipping',
            [
                'order_id' => $order->getEntityId(),
                'live_fulfilments' => $liveFulfilmentCount,
            ]
        );
        return null;
    }

    /**
     * @param array<string,mixed> $fulfilment
     */
    private function isCancelled(array $fulfilment): bool
    {
        $status = strtolower((string) ($fulfilment['status'] ?? ''));
        if ($status === '') {
            return false;
        }
        return strpos($status, 'cancel') !== false
            || strpos($status, 'failed') !== false
            || strpos($status, 'rejected') !== false;
    }

    /**
     * Find the Magento shipment for a Bob Go fulfilment, by stamped fulfilment
     * id first and tracking number second.
     *
     * @return \Magento\Sales\Model\Order\Shipment|null
     */
    private function findShipment(OrderInterface $order, string $fulfilmentId, string $trackingNumber)
    {
        /** @var \Magento\Sales\Model\Order $order */
        $shipments = $order->getShipmentsCollection();
        if (!$shipments || $shipments->getSize() === 0) {
            return null;
        }

        foreach ($shipments as $shipment) {
            /** @var \Magento\Sales\Model\Order\Shipment $shipment */
            if ($fulfilmentId !== '' && (string) $shipment->getData('bobgo_fulfillment_id') === $fulfilmentId) {
                return $shipment;
            }
            if ($trackingNumber === '') {
                continue;
            }
            foreach ($shipment->getAllTracks() as $track) {
                if ((string) $track->getTrackNumber() === $trackingNumber) {
                    return $shipment;
                }
            }
        }

        return null;
    }

    /**
     * Replace a placeholder courier title with the real one once Bob Go knows it.
     *
     * @param \Magento\Sales\Model\Order\Shipment $shipment
     */
    private function refreshTrackTitle($shipment, string $trackingNumber, string $courier): void
    {
        if ($trackingNumber === '' || $courier === '' || in_array($courier, self::PLACEHOLDER_TITLES, true)) {
            return;
        }

        try {
            foreach ($shipment->getAllTracks() as $track) {
                if ((string) $track->getTrackNumber() !== $trackingNumber) {
                    continue;
                }
                if (!in_array((string) $track->getTitle(), self::PLACEHOLDER_TITLES, true)) {
                    return;
                }
                $track->setTitle($courier);
                $track->save();
                $this->logger->info('Bob Go fulfilment sync: courier title backfilled', [
                    'tracking_number' => $trackingNumber,
                    'courier' => $courier,
                ]);
                return;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Bob Go fulfilment sync: failed to backfill courier title', [
                'tracking_number' => $trackingNumber,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Persist the Bob Go fulfilment id on the shipment so later duplicate
     * events (and every subsequent reconciliation tick) dedup against it even
     * after webhook event_id retention has expired.
     */
    private function stampFulfilmentId(int $shipmentId, string $fulfilmentId): void
    {
        if ($shipmentId <= 0) {
            return;
        }
        try {
            $shipment = $this->shipmentRepository->get($shipmentId);
            $shipment->setData('bobgo_fulfillment_id', $fulfilmentId);
            $this->shipmentRepository->save($shipment);
        } catch (\Throwable $e) {
            $this->logger->warning('Bob Go: failed to stamp fulfilment id on shipment', [
                'shipment_id' => $shipmentId,
                'fulfillment_id' => $fulfilmentId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build shipment items for a partial fulfilment.
     *
     * Matches by Bob Go order_item id first (the canonical link we set on order
     * push), then falls back to SKU, popping from a per-SKU queue so duplicate
     * SKUs on an order don't collapse onto a single line.
     *
     * @param array<int,array<string,mixed>> $itemRows
     * @return array<\Magento\Sales\Api\Data\ShipmentItemCreationInterface>
     */
    private function buildShipmentItems(OrderInterface $order, array $itemRows): array
    {
        if (empty($itemRows)) {
            return [];
        }

        /** @var \Magento\Sales\Model\Order $order */
        $byBobgoItemId = [];
        $bySku = [];
        foreach ($order->getAllItems() as $orderItem) {
            /** @var \Magento\Sales\Model\Order\Item $orderItem */
            $bobgoItemId = (string) ($orderItem->getData('bobgo_order_item_id') ?? '');
            if ($bobgoItemId !== '') {
                $byBobgoItemId[$bobgoItemId] = $orderItem;
            }
            $sku = (string) $orderItem->getSku();
            if ($sku !== '') {
                $bySku[$sku][] = $orderItem;
            }
        }

        $items = [];
        foreach ($itemRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $qty = (int) ($row['fulfilled_qty'] ?? $row['qty'] ?? $row['quantity'] ?? 0);
            if ($qty <= 0) {
                continue;
            }

            $matched = null;

            $bobgoItemId = (string) ($row['channel_ref_id'] ?? $row['id'] ?? '');
            if ($bobgoItemId !== '' && isset($byBobgoItemId[$bobgoItemId])) {
                $matched = $byBobgoItemId[$bobgoItemId];
            }

            if ($matched === null) {
                $sku = (string) ($row['sku'] ?? '');
                if ($sku !== '' && !empty($bySku[$sku])) {
                    $matched = array_shift($bySku[$sku]);
                }
            }

            if ($matched === null) {
                continue;
            }

            $shipmentItem = $this->itemCreationFactory->create();
            $shipmentItem->setOrderItemId((int) $matched->getItemId());
            $shipmentItem->setQty((float) $qty);
            $items[] = $shipmentItem;
        }

        return $items;
    }

    /**
     * Normalise Bob Go's fulfilments response into a flat list.
     *
     * The response has shipped in several shapes over time (wrapped under one of
     * LIST_KEYS, or a bare list) and each entry may be flat or nested under
     * `shipment` / `order_fulfillment`. Everything downstream — including the
     * admin panel template — reads the normalised keys only.
     *
     * @param array<string,mixed> $response
     * @return array<int,array<string,mixed>>
     */
    private function extractFulfilments(array $response): array
    {
        $raw = [];
        foreach (self::LIST_KEYS as $key) {
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
            $normalised[] = $this->normaliseFulfilment($entry);
        }
        return $normalised;
    }

    /**
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private function normaliseFulfilment(array $entry): array
    {
        $fulfilment = is_array($entry['order_fulfillment'] ?? null) ? $entry['order_fulfillment'] : $entry;
        $shipment = is_array($entry['shipment'] ?? null) ? $entry['shipment'] : $entry;
        $provider = is_array($shipment['provider'] ?? null) ? $shipment['provider'] : [];
        $serviceLevel = is_array($shipment['service_level'] ?? null) ? $shipment['service_level'] : [];

        return [
            'fulfillment_id'           => (string) ($fulfilment['id'] ?? $entry['fulfillment_id'] ?? ''),
            'tracking_number'          => (string) ($shipment['tracking_reference'] ?? $entry['tracking_number'] ?? ''),
            'provider_tracking_number' => (string) ($shipment['provider_tracking_reference'] ?? ''),
            'courier'                  => (string) ($provider['name'] ?? $entry['courier'] ?? $entry['courier_name'] ?? ''),
            'provider_slug'            => (string) ($shipment['provider_slug'] ?? ''),
            'service_level'            => (string) ($serviceLevel['name'] ?? ''),
            'status'                   => (string) ($shipment['status'] ?? $entry['status'] ?? ''),
            'items'                    => $this->extractItemRows($fulfilment, $entry, $shipment),
        ];
    }

    /**
     * @param array<string,mixed> ...$candidates
     * @return array<int,array<string,mixed>>
     */
    private function extractItemRows(array ...$candidates): array
    {
        foreach ($candidates as $candidate) {
            foreach (self::ITEM_KEYS as $key) {
                if (isset($candidate[$key]) && is_array($candidate[$key]) && $candidate[$key] !== []) {
                    return array_values(array_filter($candidate[$key], 'is_array'));
                }
            }
        }
        return [];
    }
}
