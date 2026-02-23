<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Service;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\ShipOrderInterface;
use Magento\Sales\Api\Data\ShipmentTrackCreationInterfaceFactory;
use Magento\Sales\Api\Data\ShipmentItemCreationInterfaceFactory;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Model\Order\Shipment\TrackFactory;
use BobGroup\BobGo\Model\Config\ApiConfig;
use Psr\Log\LoggerInterface;

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
     * @var SearchCriteriaBuilder
     */
    private SearchCriteriaBuilder $searchCriteriaBuilder;

    /**
     * @var TrackFactory
     */
    private TrackFactory $trackFactory;

    /**
     * @var ApiConfig
     */
    private ApiConfig $apiConfig;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        ShipOrderInterface $shipOrder,
        ShipmentTrackCreationInterfaceFactory $trackCreationFactory,
        ShipmentItemCreationInterfaceFactory $itemCreationFactory,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        TrackFactory $trackFactory,
        ApiConfig $apiConfig,
        LoggerInterface $logger
    ) {
        $this->orderRepository = $orderRepository;
        $this->shipOrder = $shipOrder;
        $this->trackCreationFactory = $trackCreationFactory;
        $this->itemCreationFactory = $itemCreationFactory;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->trackFactory = $trackFactory;
        $this->apiConfig = $apiConfig;
        $this->logger = $logger;
    }

    /**
     * Process a fulfillment from Bob Go - creates a shipment in Magento
     *
     * @param array<string,mixed> $data Fulfillment data from Bob Go
     */
    public function processFulfillment(array $data): void
    {
        $channelRefId = $data['channel_ref_id'] ?? null;
        $fulfillmentId = $data['fulfillment_id'] ?? null;
        $trackingNumbers = $data['tracking_numbers'] ?? [];
        $lineItems = $data['line_items'] ?? [];

        if ($channelRefId === null) {
            $this->logger->error('Bob Go fulfillment missing channel_ref_id', ['data' => $data]);
            return;
        }

        try {
            $order = $this->findOrderByEntityId((int) $channelRefId);
        } catch (\Exception $e) {
            $this->logger->error('Bob Go fulfillment: order not found', [
                'channel_ref_id' => $channelRefId,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        if (!$order->canShip()) {
            $this->logger->info('Bob Go fulfillment: order cannot be shipped', [
                'order_id' => $order->getEntityId(),
                'state' => $order->getState(),
                'fulfillment_id' => $fulfillmentId,
            ]);
            return;
        }

        // Idempotency check - don't create duplicate shipments
        if ($this->hasExistingFulfillment($order, $trackingNumbers)) {
            $this->logger->info('Bob Go fulfillment: shipment already exists', [
                'order_id' => $order->getEntityId(),
                'fulfillment_id' => $fulfillmentId,
            ]);
            return;
        }

        // Build items array for partial fulfillments
        $items = $this->buildShipmentItems($order, $lineItems);

        // Build tracking entries
        $tracks = [];
        foreach ($trackingNumbers as $tracking) {
            $track = $this->trackCreationFactory->create();
            $track->setTrackNumber($tracking['number'] ?? '');
            $track->setCarrierCode('bobgo');
            $track->setTitle($tracking['carrier'] ?? 'Bob Go');
            $tracks[] = $track;
        }

        try {
            $notifyCustomer = $this->apiConfig->shouldNotifyCustomer();
            $this->shipOrder->execute(
                (int) $order->getEntityId(),
                $items,
                $notifyCustomer,
                false,
                null,
                $tracks
            );

            $this->logger->info('Bob Go fulfillment: shipment created', [
                'order_id' => $order->getEntityId(),
                'fulfillment_id' => $fulfillmentId,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Bob Go fulfillment: failed to create shipment', [
                'order_id' => $order->getEntityId(),
                'fulfillment_id' => $fulfillmentId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Process a tracking update - updates tracking info on existing shipments
     *
     * @param array<string,mixed> $data Tracking update data from Bob Go
     */
    public function processTrackingUpdate(array $data): void
    {
        $channelRefId = $data['channel_ref_id'] ?? null;
        $trackingNumbers = $data['tracking_numbers'] ?? [];

        if ($channelRefId === null) {
            $this->logger->error('Bob Go tracking update missing channel_ref_id', ['data' => $data]);
            return;
        }

        try {
            $order = $this->findOrderByEntityId((int) $channelRefId);
        } catch (\Exception $e) {
            $this->logger->error('Bob Go tracking update: order not found', [
                'channel_ref_id' => $channelRefId,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        /** @var \Magento\Sales\Model\Order $order */
        $shipments = $order->getShipmentsCollection();
        if ($shipments === false || $shipments->getSize() === 0) {
            $this->logger->info('Bob Go tracking update: no shipments found for order', [
                'order_id' => $order->getEntityId(),
            ]);
            return;
        }

        /** @var \Magento\Sales\Model\Order\Shipment $shipment */
        $shipment = $shipments->getLastItem();

        // Collect existing tracking numbers to avoid duplicates
        $existingNumbers = [];
        foreach ($shipment->getAllTracks() as $existingTrack) {
            $existingNumbers[] = $existingTrack->getTrackNumber();
        }

        $tracksAdded = false;
        foreach ($trackingNumbers as $tracking) {
            $number = $tracking['number'] ?? '';
            if ($number === '' || in_array($number, $existingNumbers, true)) {
                continue;
            }

            try {
                /** @var \Magento\Sales\Model\Order\Shipment\Track $trackModel */
                $trackModel = $this->trackFactory->create();
                $trackModel->setTrackNumber($number);
                $trackModel->setCarrierCode('bobgo');
                $trackModel->setTitle($tracking['carrier'] ?? 'Bob Go');
                $shipment->addTrack($trackModel);
                $tracksAdded = true;
            } catch (\Exception $e) {
                $this->logger->error('Bob Go tracking update: failed to add track', [
                    'order_id' => $order->getEntityId(),
                    'tracking_number' => $number,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($tracksAdded) {
            try {
                $shipment->save();
                $this->logger->info('Bob Go tracking update: tracks updated', [
                    'order_id' => $order->getEntityId(),
                ]);
            } catch (\Exception $e) {
                $this->logger->error('Bob Go tracking update: failed to save shipment', [
                    'order_id' => $order->getEntityId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param int $entityId
     * @return \Magento\Sales\Api\Data\OrderInterface|\Magento\Sales\Model\Order
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    private function findOrderByEntityId(int $entityId): \Magento\Sales\Api\Data\OrderInterface
    {
        return $this->orderRepository->get($entityId);
    }

    /**
     * Check if a shipment with the given tracking numbers already exists on the order
     *
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @param array<int,array<string,string>> $trackingNumbers
     * @return bool
     */
    private function hasExistingFulfillment(\Magento\Sales\Api\Data\OrderInterface $order, array $trackingNumbers): bool
    {
        /** @var \Magento\Sales\Model\Order $order */
        $shipments = $order->getShipmentsCollection();
        if ($shipments === false || $shipments->getSize() === 0) {
            return false;
        }

        $incomingNumbers = [];
        foreach ($trackingNumbers as $tracking) {
            if (!empty($tracking['number'])) {
                $incomingNumbers[] = $tracking['number'];
            }
        }

        if (empty($incomingNumbers)) {
            return false;
        }

        foreach ($shipments as $shipment) {
            /** @var \Magento\Sales\Model\Order\Shipment $shipment */
            foreach ($shipment->getAllTracks() as $track) {
                if (in_array($track->getTrackNumber(), $incomingNumbers, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Build shipment items array from fulfillment line items.
     * Returns empty array for full fulfillment (ShipOrderInterface ships all when items is empty).
     *
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @param array<int,array<string,mixed>> $lineItems
     * @return array<\Magento\Sales\Api\Data\ShipmentItemCreationInterface>
     */
    private function buildShipmentItems(\Magento\Sales\Api\Data\OrderInterface $order, array $lineItems): array
    {
        if (empty($lineItems)) {
            return [];
        }

        // Build a map of channel_ref_id => quantity from fulfillment data
        $fulfillmentQtyMap = [];
        foreach ($lineItems as $lineItem) {
            $itemRefId = $lineItem['channel_ref_id'] ?? null;
            $qty = $lineItem['quantity'] ?? 0;
            if ($itemRefId !== null) {
                $fulfillmentQtyMap[(string) $itemRefId] = (int) $qty;
            }
        }

        $items = [];
        /** @var \Magento\Sales\Model\Order $order */
        foreach ($order->getAllItems() as $orderItem) {
            /** @var \Magento\Sales\Model\Order\Item $orderItem */
            $itemId = (string) $orderItem->getItemId();
            if (isset($fulfillmentQtyMap[$itemId])) {
                $shipmentItem = $this->itemCreationFactory->create();
                $shipmentItem->setOrderItemId((int) $orderItem->getItemId());
                $shipmentItem->setQty((float) $fulfillmentQtyMap[$itemId]);
                $items[] = $shipmentItem;
            }
        }

        return $items;
    }
}
