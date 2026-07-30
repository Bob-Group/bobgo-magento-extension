<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Service;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\ShipOrderInterface;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Sales\Api\Data\ShipmentTrackCreationInterfaceFactory;
use Magento\Sales\Api\Data\ShipmentItemCreationInterfaceFactory;
use BobGroup\BobGo\Model\Config\ApiConfig;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * Creates Magento shipments from Bob Go fulfillment data.
 *
 * Handles two types of incoming data:
 * - Fulfillment creation: Creates a new shipment with items and tracking numbers.
 *   Includes idempotency checks to prevent duplicate shipments.
 * - Tracking updates: Backfills courier detail and status onto the shipment that
 *   already carries the tracking number.
 *
 * Bob Go payload format (fulfillment/created):
 *   - channel_ref_id: Magento entity_id — the authoritative order reference
 *   - channel_order_number: Magento increment_id
 *   - id: the Bob Go FULFILMENT id (not the order id — order_id carries that)
 *   - method_reference: tracking number (e.g. "UASDJ9LB")
 *   - order_items[].sku: item SKU (matched to Magento order items)
 *   - order_items[].fulfilled_qty: quantity fulfilled
 *
 * Both entry points take an already-resolved order. Resolution lives in
 * OrderResolver because it decides the HTTP status the webhook controller
 * returns, and because getting it wrong attaches foreign shipments to real
 * customer orders — see that class for why it is not a simple lookup.
 */
class FulfillmentService
{
    /**
     * @var OrderRepositoryInterface
     */
    private OrderRepositoryInterface $orderRepository;

    /**
     * @var ShipOrderInterface
     */
    private ShipOrderInterface $shipOrder;

    /**
     * @var ShipmentTrackCreationInterfaceFactory
     */
    private ShipmentTrackCreationInterfaceFactory $trackCreationFactory;

    /**
     * @var ShipmentItemCreationInterfaceFactory
     */
    private ShipmentItemCreationInterfaceFactory $itemCreationFactory;

    /**
     * @var ApiConfig
     */
    private ApiConfig $apiConfig;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var DateTime
     */
    private DateTime $dateTime;

    /**
     * @var ShipmentRepositoryInterface
     */
    private ShipmentRepositoryInterface $shipmentRepository;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        ShipOrderInterface $shipOrder,
        ShipmentTrackCreationInterfaceFactory $trackCreationFactory,
        ShipmentItemCreationInterfaceFactory $itemCreationFactory,
        ApiConfig $apiConfig,
        LoggerInterface $logger,
        DateTime $dateTime,
        ShipmentRepositoryInterface $shipmentRepository
    ) {
        $this->orderRepository = $orderRepository;
        $this->shipOrder = $shipOrder;
        $this->trackCreationFactory = $trackCreationFactory;
        $this->itemCreationFactory = $itemCreationFactory;
        $this->apiConfig = $apiConfig;
        $this->logger = $logger;
        $this->dateTime = $dateTime;
        $this->shipmentRepository = $shipmentRepository;
    }

    /**
     * Bump the bobgo_last_webhook timestamp on an order. Safe to call from
     * every webhook handler — failures are swallowed.
     */
    private function stampLastWebhook(OrderInterface $order): void
    {
        try {
            $order->setData('bobgo_last_webhook', $this->dateTime->gmtDate());
            $this->orderRepository->save($order);
        } catch (\Throwable $e) {
            $this->logger->warning('Bob Go: failed to stamp bobgo_last_webhook', [
                'order_id' => $order->getEntityId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Process a fulfillment from Bob Go - creates a shipment in Magento.
     *
     * @param OrderInterface $order The order this fulfilment belongs to (see OrderResolver)
     * @param array<string,mixed> $data Fulfillment data from Bob Go
     */
    public function processFulfillment(OrderInterface $order, array $data): void
    {
        $fulfillmentId = $data['id'] ?? null;
        $trackingNumber = $data['method_reference'] ?? '';
        $orderItems = $data['order_items'] ?? [];

        $this->stampLastWebhook($order);

        if (!$order->canShip()) {
            // Permanent as far as this delivery goes — retrying won't make the
            // order shippable. Warning rather than info because it is not
            // always benign: canShip() is false for orders on hold and in
            // payment review, so a merchant who fulfils in Bob Go while the
            // Magento order is held gets no shipment and no email, and nothing
            // re-drives it. Recovering that automatically needs the
            // refresh-from-API model (see docs/todo.md P1-6).
            $this->logger->warning('Bob Go fulfillment: order cannot be shipped', [
                'order_id' => $order->getEntityId(),
                'state' => $order->getState(),
                'fulfillment_id' => $fulfillmentId,
            ]);
            return;
        }

        // Idempotency — webhook event_id dedup already short-circuited a
        // retry of THIS event upstream. These checks catch a second event for
        // the same fulfilment (e.g. an updated webhook re-fired for an order
        // that was already shipped).
        if ($trackingNumber !== '' && $this->hasExistingTrackingNumber($order, $trackingNumber)) {
            $this->logger->info('Bob Go fulfillment: shipment already exists for tracking number', [
                'order_id' => $order->getEntityId(),
                'fulfillment_id' => $fulfillmentId,
                'tracking_number' => $trackingNumber,
            ]);
            return;
        }
        if ($fulfillmentId !== null && $fulfillmentId !== ''
            && $this->hasExistingFulfillmentId($order, (string) $fulfillmentId)
        ) {
            $this->logger->info('Bob Go fulfillment: shipment already exists for fulfillment id', [
                'order_id' => $order->getEntityId(),
                'fulfillment_id' => $fulfillmentId,
            ]);
            return;
        }
        if ($trackingNumber === '' && ($fulfillmentId === null || $fulfillmentId === '')) {
            // Nothing to dedup on — refuse rather than risk a duplicate shipment.
            $this->logger->warning('Bob Go fulfillment: payload has no tracking number or id, skipping', [
                'order_id' => $order->getEntityId(),
            ]);
            return;
        }

        // Build items array for partial fulfillments. If the payload listed
        // items but none of them matched a SKU on the order, refuse — Bob Go
        // treats an empty $items array as "ship everything", and a partial
        // fulfilment with a wrong/unknown SKU would otherwise blow out as a
        // full shipment.
        $items = $this->buildShipmentItems($order, $orderItems);
        if (!empty($orderItems) && empty($items)) {
            $this->logger->error('Bob Go fulfillment: payload listed items but none matched the order', [
                'order_id' => $order->getEntityId(),
                'fulfillment_id' => $fulfillmentId,
                'payload_skus' => array_values(array_filter(array_map(
                    static function ($i) { return $i['sku'] ?? null; },
                    $orderItems
                ))),
            ]);
            throw new TransientWebhookException(
                'Fulfillment payload references items that are not on the order'
            );
        }

        // Build tracking entry
        $tracks = [];
        if ($trackingNumber !== '') {
            $track = $this->trackCreationFactory->create();
            $track->setTrackNumber($trackingNumber);
            $track->setCarrierCode('bobgo');
            $track->setTitle($this->extractCourierName($data));
            $tracks[] = $track;
        }

        try {
            $notifyCustomer = $this->apiConfig->shouldNotifyCustomer();
            $shipmentId = $this->shipOrder->execute(
                (int) $order->getEntityId(),
                $items,
                $notifyCustomer,
                false,
                null,
                $tracks
            );

            if ($fulfillmentId !== null && $fulfillmentId !== '') {
                $this->stampFulfillmentIdOnShipment((int) $shipmentId, (string) $fulfillmentId);
            }

            $this->logger->info('Bob Go fulfillment: shipment created', [
                'order_id' => $order->getEntityId(),
                'increment_id' => $order->getIncrementId(),
                'fulfillment_id' => $fulfillmentId,
                'tracking_number' => $trackingNumber,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Bob Go fulfillment: failed to create shipment', [
                'order_id' => $order->getEntityId(),
                'fulfillment_id' => $fulfillmentId,
                'error' => $e->getMessage(),
            ]);
            // Re-throw — shipment creation failure is transient (DB lock, race,
            // momentary integrity violation). Letting the controller return 500
            // lets Bob Go retry instead of silently dropping the event.
            throw new TransientWebhookException(
                'Shipment creation failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Process a tracking update from Bob Go.
     *
     * The tracking/updated payload contains status changes for an existing shipment.
     * Key fields: shipment_tracking_reference, channel_order_number, status,
     * status_friendly, courier_name, shipment.tracking_url.
     *
     * This method ensures the tracking number exists on the shipment and adds
     * an order comment with the latest tracking status.
     *
     * @param OrderInterface $order The order this update belongs to (see OrderResolver)
     * @param array<string,mixed> $data Tracking update data from Bob Go
     */
    public function processTrackingUpdate(OrderInterface $order, array $data): void
    {
        // On this topic the top-level `id` is the tracking-reference string, not
        // an id of anything — Bob Go sends no order id here at all.
        $trackingNumber = $data['shipment_tracking_reference'] ?? ($data['id'] ?? '');
        $statusFriendly = $data['status_friendly'] ?? ($data['status'] ?? '');
        $courierName = $this->extractCourierName($data);

        if ($trackingNumber === '') {
            $this->logger->info('Bob Go tracking update: no tracking reference provided');
            return;
        }

        $this->stampLastWebhook($order);

        /** @var \Magento\Sales\Model\Order $order */
        $shipments = $order->getShipmentsCollection();
        if ($shipments === false || $shipments->getSize() === 0) {
            $this->logger->info('Bob Go tracking update: no shipments found for order', [
                'order_id' => $order->getEntityId(),
            ]);
            return;
        }

        // Locate the shipment that already carries this tracking number.
        // We will NOT fall back to "the latest shipment" — on a multi-shipment
        // order that misattaches the update; and even on single-shipment
        // orders it can attach to the wrong shipment when this webhook races
        // ahead of a fulfillment/created that's still in flight.
        $shipment = null;
        $existingTrack = null;
        foreach ($shipments as $candidate) {
            /** @var \Magento\Sales\Model\Order\Shipment $candidate */
            foreach ($candidate->getAllTracks() as $track) {
                if ($track->getTrackNumber() === (string) $trackingNumber) {
                    $shipment = $candidate;
                    $existingTrack = $track;
                    break 2;
                }
            }
        }

        if ($shipment === null) {
            // We know this order is ours (OrderResolver matched it), so this is
            // the genuine race, not foreign traffic: Bob Go fires
            // fulfillment/created and tracking/updated independently and their
            // deliveries can overtake each other. The shipment + track is owned
            // by the fulfillment/created handler; if it hasn't created it yet,
            // throw transient so Bob Go retries and we attach to the right
            // shipment on the next attempt.
            //
            // Foreign events never reach here — they are acknowledged with 200
            // at resolution time. That distinction matters: a 500 counts against
            // Bob Go's 3-day delivery-failure window, and account-wide delivery
            // means unresolvable events would otherwise arrive forever.
            $this->logger->info('Bob Go tracking update: no matching shipment yet, will retry', [
                'order_id' => $order->getEntityId(),
                'tracking_number' => $trackingNumber,
            ]);
            throw new TransientWebhookException(sprintf(
                'No shipment carries tracking %s yet for order %s; will retry once fulfillment/created arrives',
                $trackingNumber,
                (string) $order->getEntityId()
            ));
        }

        try {
            // Backfill the courier title when fulfillment/created stamped the
            // generic "Bob Go" placeholder (its payload often lacks the courier
            // display name) and tracking/updated now carries the real
            // courier_name. Saves the operator a manual edit.
            $currentTitle = (string) $existingTrack->getTitle();
            $isPlaceholder = $currentTitle === '' || $currentTitle === 'Bob Go';
            if ($isPlaceholder && $courierName !== '' && $courierName !== 'Bob Go') {
                $existingTrack->setTitle($courierName);
                $existingTrack->save();
                $this->logger->info('Bob Go tracking update: courier title backfilled', [
                    'order_id' => $order->getEntityId(),
                    'tracking_number' => $trackingNumber,
                    'courier_name' => $courierName,
                ]);
            }

            // Add order comment with tracking status
            if ($statusFriendly !== '') {
                $comment = sprintf('Bob Go tracking update: %s (ref: %s)', $statusFriendly, $trackingNumber);
                $order->addCommentToStatusHistory($comment);
                $order->save();

                $this->logger->info('Bob Go tracking update processed', [
                    'order_id' => $order->getEntityId(),
                    'tracking_number' => $trackingNumber,
                    'status' => $statusFriendly,
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Bob Go tracking update: failed to process', [
                'order_id' => $order->getEntityId(),
                'tracking_number' => $trackingNumber,
                'error' => $e->getMessage(),
            ]);
            throw new TransientWebhookException(
                'Tracking update processing failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Pull the courier display name out of the fulfillment payload, tolerating
     * the two shapes Bob Go emits: flat (courier_name on the root, as on the
     * tracking-update webhook) and nested (shipment.provider.name, as on the
     * order-fulfillments GET response). Falls back to "Bob Go" so the tracking
     * row always has a title.
     *
     * @param array<string,mixed> $data
     */
    private function extractCourierName(array $data): string
    {
        $shipment = is_array($data['shipment'] ?? null) ? $data['shipment'] : [];
        $provider = is_array($shipment['provider'] ?? null) ? $shipment['provider'] : [];
        $rootProvider = is_array($data['provider'] ?? null) ? $data['provider'] : [];

        $candidates = [
            $data['courier_name'] ?? null,
            $provider['name'] ?? null,
            $rootProvider['name'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }
        return 'Bob Go';
    }

    /**
     * Check if a shipment with the given tracking number already exists on the order.
     */
    private function hasExistingTrackingNumber(OrderInterface $order, string $trackingNumber): bool
    {
        /** @var \Magento\Sales\Model\Order $order */
        $shipments = $order->getShipmentsCollection();
        if ($shipments === false || $shipments->getSize() === 0) {
            return false;
        }

        foreach ($shipments as $shipment) {
            /** @var \Magento\Sales\Model\Order\Shipment $shipment */
            foreach ($shipment->getAllTracks() as $track) {
                if ($track->getTrackNumber() === $trackingNumber) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if a shipment with the given Bob Go fulfillment id already exists.
     *
     * The fulfilment id is stored on the shipment as `bobgo_fulfillment_id`
     * after we create it. Best-effort — if the column isn't populated we
     * fall back to the tracking-number dedup.
     */
    private function hasExistingFulfillmentId(OrderInterface $order, string $fulfillmentId): bool
    {
        /** @var \Magento\Sales\Model\Order $order */
        $shipments = $order->getShipmentsCollection();
        if ($shipments === false || $shipments->getSize() === 0) {
            return false;
        }
        foreach ($shipments as $shipment) {
            if ((string) $shipment->getData('bobgo_fulfillment_id') === $fulfillmentId) {
                return true;
            }
        }
        return false;
    }

    /**
     * Persist the Bob Go fulfilment id on the freshly-created shipment so
     * later duplicate webhook deliveries (after event_id retention has
     * expired) can still be deduped.
     */
    private function stampFulfillmentIdOnShipment(int $shipmentId, string $fulfillmentId): void
    {
        if ($shipmentId <= 0) {
            return;
        }
        try {
            $shipment = $this->shipmentRepository->get($shipmentId);
            $shipment->setData('bobgo_fulfillment_id', $fulfillmentId);
            $this->shipmentRepository->save($shipment);
        } catch (\Throwable $e) {
            $this->logger->warning('Bob Go: failed to stamp fulfillment id on shipment', [
                'shipment_id' => $shipmentId,
                'fulfillment_id' => $fulfillmentId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build shipment items array from Bob Go fulfillment order_items.
     *
     * Matches by Bob Go order_item id first (the canonical link we set on
     * order push), then falls back to SKU for orders pushed before that
     * field existed. SKU matching consumes from a remaining-qty pool so
     * duplicate SKUs on an order (e.g. the same simple product appearing
     * twice via different bundle / option configurations) don't collapse
     * into a single line.
     *
     * Returns empty array only when the payload itself was empty, in which
     * case ShipOrderInterface treats it as "ship everything." The caller
     * (processFulfillment) already refuses the case where the payload had
     * items but none matched.
     *
     * @param OrderInterface $order
     * @param array<int,array<string,mixed>> $orderItems Bob Go order_items
     * @return array<\Magento\Sales\Api\Data\ShipmentItemCreationInterface>
     */
    private function buildShipmentItems(OrderInterface $order, array $orderItems): array
    {
        if (empty($orderItems)) {
            return [];
        }

        /** @var \Magento\Sales\Model\Order $order */
        // Index order lines: by Bob Go order_item id (one row each) and by
        // SKU (a list of rows, so duplicates are preserved).
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
        foreach ($orderItems as $payloadItem) {
            if (!is_array($payloadItem)) {
                continue;
            }
            $qty = (int) ($payloadItem['fulfilled_qty'] ?? $payloadItem['qty'] ?? 0);
            if ($qty <= 0) {
                continue;
            }

            $matched = null;

            // Preferred: explicit channel_ref_id / id linkback.
            $bobgoItemId = (string) ($payloadItem['channel_ref_id'] ?? $payloadItem['id'] ?? '');
            if ($bobgoItemId !== '' && isset($byBobgoItemId[$bobgoItemId])) {
                $matched = $byBobgoItemId[$bobgoItemId];
            }

            // Fallback: pop the next not-yet-claimed SKU match. Using a
            // queue preserves the ordering and prevents two payload lines
            // for the same SKU from being collapsed onto one order line.
            if ($matched === null) {
                $sku = (string) ($payloadItem['sku'] ?? '');
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
}
