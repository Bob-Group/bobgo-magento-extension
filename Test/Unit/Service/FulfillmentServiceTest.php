<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Service;

use BobGroup\BobGo\Model\Config\ApiConfig;
use BobGroup\BobGo\Service\FulfillmentService;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\Data\ShipmentItemCreationInterface;
use Magento\Sales\Api\Data\ShipmentItemCreationInterfaceFactory;
use Magento\Sales\Api\Data\ShipmentTrackCreationInterface;
use Magento\Sales\Api\Data\ShipmentTrackCreationInterfaceFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\ShipOrderInterface;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\Order\Shipment\Track;
use Magento\Sales\Model\Order\Shipment\TrackFactory;
use Magento\Sales\Model\ResourceModel\Order\Shipment\Collection as ShipmentCollection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FulfillmentServiceTest extends TestCase
{
    /**
     * @var FulfillmentService
     */
    private $service;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $orderRepositoryMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $shipOrderMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $trackCreationFactoryMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $itemCreationFactoryMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $searchCriteriaBuilderMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $trackFactoryMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $apiConfigMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $loggerMock;

    protected function setUp(): void
    {
        $this->orderRepositoryMock = $this->createMock(OrderRepositoryInterface::class);
        $this->shipOrderMock = $this->createMock(ShipOrderInterface::class);
        $this->trackCreationFactoryMock = $this->createMock(ShipmentTrackCreationInterfaceFactory::class);
        $this->itemCreationFactoryMock = $this->createMock(ShipmentItemCreationInterfaceFactory::class);
        $this->searchCriteriaBuilderMock = $this->createMock(SearchCriteriaBuilder::class);
        $this->trackFactoryMock = $this->createMock(TrackFactory::class);
        $this->apiConfigMock = $this->createMock(ApiConfig::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $dateTimeMock = $this->createMock(\Magento\Framework\Stdlib\DateTime\DateTime::class);
        $dateTimeMock->method('gmtDate')->willReturn('2026-05-19 12:00:00');

        $this->service = new FulfillmentService(
            $this->orderRepositoryMock,
            $this->shipOrderMock,
            $this->trackCreationFactoryMock,
            $this->itemCreationFactoryMock,
            $this->searchCriteriaBuilderMock,
            $this->trackFactoryMock,
            $this->apiConfigMock,
            $this->loggerMock,
            $dateTimeMock
        );
    }

    /**
     * Helper to mock order lookup via findOrderByIncrementId (SearchCriteriaBuilder + getList).
     *
     * @param \PHPUnit\Framework\MockObject\MockObject $orderMock
     */
    private function mockOrderLookupByIncrementId($orderMock): void
    {
        $searchCriteriaMock = $this->createMock(SearchCriteria::class);
        $this->searchCriteriaBuilderMock->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilderMock->method('create')->willReturn($searchCriteriaMock);

        $searchResultMock = $this->createMock(OrderSearchResultInterface::class);
        $searchResultMock->method('getItems')->willReturn([$orderMock]);
        $this->orderRepositoryMock->method('getList')->willReturn($searchResultMock);
    }

    public function testProcessFulfillmentCreatesShipment(): void
    {
        $orderId = 42;
        $incrementId = '000000042';
        $data = [
            'channel_order_number' => $incrementId,
            'id' => 'ful_123',
            'method_reference' => 'TRACK001',
            'order_items' => [],
        ];

        $orderMock = $this->createMock(\Magento\Sales\Model\Order::class);
        $orderMock->method('getEntityId')->willReturn($orderId);
        $orderMock->method('canShip')->willReturn(true);

        // No existing shipments (for idempotency check)
        $shipmentCollectionMock = $this->createMock(ShipmentCollection::class);
        $shipmentCollectionMock->method('getSize')->willReturn(0);
        $orderMock->method('getShipmentsCollection')->willReturn($shipmentCollectionMock);

        $this->mockOrderLookupByIncrementId($orderMock);

        // Track creation
        $trackMock = $this->createMock(ShipmentTrackCreationInterface::class);
        $trackMock->expects($this->once())->method('setTrackNumber')->with('TRACK001');
        $trackMock->expects($this->once())->method('setCarrierCode')->with('bobgo');
        $trackMock->expects($this->once())->method('setTitle')->with('Bob Go');
        $this->trackCreationFactoryMock->method('create')->willReturn($trackMock);

        $this->apiConfigMock->method('shouldNotifyCustomer')->willReturn(false);

        // Expect shipOrder to be called
        $this->shipOrderMock->expects($this->once())
            ->method('execute')
            ->with($orderId, [], false, false, null, [$trackMock]);

        $this->service->processFulfillment($data);
    }

    public function testProcessFulfillmentSkipsWhenOrderNotShippable(): void
    {
        $orderId = 42;
        $incrementId = '000000042';
        $data = [
            'channel_order_number' => $incrementId,
            'id' => 'ful_123',
            'method_reference' => '',
            'order_items' => [],
        ];

        $orderMock = $this->createMock(\Magento\Sales\Model\Order::class);
        $orderMock->method('getEntityId')->willReturn($orderId);
        $orderMock->method('getState')->willReturn('complete');
        $orderMock->method('canShip')->willReturn(false);

        $this->mockOrderLookupByIncrementId($orderMock);

        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with(
                'Bob Go fulfillment: order cannot be shipped',
                $this->callback(function ($context) use ($orderId) {
                    return $context['order_id'] === $orderId && $context['state'] === 'complete';
                })
            );

        // shipOrder should never be called
        $this->shipOrderMock->expects($this->never())->method('execute');

        $this->service->processFulfillment($data);
    }

    public function testProcessFulfillmentIdempotency(): void
    {
        $orderId = 42;
        $incrementId = '000000042';
        $data = [
            'channel_order_number' => $incrementId,
            'id' => 'ful_123',
            'method_reference' => 'TRACK001',
            'order_items' => [],
        ];

        $orderMock = $this->createMock(\Magento\Sales\Model\Order::class);
        $orderMock->method('getEntityId')->willReturn($orderId);
        $orderMock->method('canShip')->willReturn(true);

        // Existing shipment with matching tracking number
        $existingTrackMock = $this->createMock(Track::class);
        $existingTrackMock->method('getTrackNumber')->willReturn('TRACK001');

        $shipmentMock = $this->createMock(Shipment::class);
        $shipmentMock->method('getAllTracks')->willReturn([$existingTrackMock]);

        $shipmentCollectionMock = $this->createMock(ShipmentCollection::class);
        $shipmentCollectionMock->method('getSize')->willReturn(1);
        $shipmentCollectionMock->method('getIterator')->willReturn(new \ArrayIterator([$shipmentMock]));

        $orderMock->method('getShipmentsCollection')->willReturn($shipmentCollectionMock);

        $this->mockOrderLookupByIncrementId($orderMock);

        // shipOrder should never be called (duplicate)
        $this->shipOrderMock->expects($this->never())->method('execute');

        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with(
                'Bob Go fulfillment: shipment already exists for tracking number',
                $this->callback(function ($context) use ($orderId) {
                    return $context['order_id'] === $orderId;
                })
            );

        $this->service->processFulfillment($data);
    }

    public function testProcessFulfillmentMissingChannelOrderNumber(): void
    {
        $data = [
            'id' => 'ful_123',
            'method_reference' => '',
        ];

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with('Bob Go fulfillment missing channel_order_number', ['data' => $data]);

        // Should not attempt to find order
        $this->orderRepositoryMock->expects($this->never())->method('getList');
        $this->shipOrderMock->expects($this->never())->method('execute');

        $this->service->processFulfillment($data);
    }

    public function testProcessTrackingUpdateAddsTrack(): void
    {
        $orderId = 42;
        $incrementId = '000000042';
        $data = [
            'channel_order_number' => $incrementId,
            'shipment_tracking_reference' => 'TRACK002',
            'status_friendly' => 'In Transit',
            'courier_name' => 'FastShip',
        ];

        $orderMock = $this->createMock(\Magento\Sales\Model\Order::class);
        $orderMock->method('getEntityId')->willReturn($orderId);

        // Existing shipment with no tracks
        $shipmentMock = $this->createMock(Shipment::class);
        $shipmentMock->method('getAllTracks')->willReturn([]);

        $shipmentCollectionMock = $this->createMock(ShipmentCollection::class);
        $shipmentCollectionMock->method('getSize')->willReturn(1);
        $shipmentCollectionMock->method('getLastItem')->willReturn($shipmentMock);

        $orderMock->method('getShipmentsCollection')->willReturn($shipmentCollectionMock);

        $this->mockOrderLookupByIncrementId($orderMock);

        // Track model creation
        $trackModelMock = $this->createMock(Track::class);
        $trackModelMock->expects($this->once())->method('setTrackNumber')->with('TRACK002');
        $trackModelMock->expects($this->once())->method('setCarrierCode')->with('bobgo');
        $trackModelMock->expects($this->once())->method('setTitle')->with('FastShip');
        $this->trackFactoryMock->method('create')->willReturn($trackModelMock);

        $shipmentMock->expects($this->once())->method('addTrack')->with($trackModelMock);
        $shipmentMock->expects($this->once())->method('save');

        // Expect order comment with status
        $orderMock->expects($this->once())
            ->method('addCommentToStatusHistory')
            ->with('Bob Go tracking update: In Transit (ref: TRACK002)');
        $orderMock->expects($this->once())->method('save');

        $this->service->processTrackingUpdate($data);
    }

    public function testProcessTrackingUpdateSkipsDuplicates(): void
    {
        $orderId = 42;
        $incrementId = '000000042';
        $data = [
            'channel_order_number' => $incrementId,
            'shipment_tracking_reference' => 'EXISTING001',
            'status_friendly' => 'Delivered',
            'courier_name' => 'CourierCo',
        ];

        $orderMock = $this->createMock(\Magento\Sales\Model\Order::class);
        $orderMock->method('getEntityId')->willReturn($orderId);

        // Existing shipment with the same tracking number already present
        $existingTrackMock = $this->createMock(Track::class);
        $existingTrackMock->method('getTrackNumber')->willReturn('EXISTING001');

        $shipmentMock = $this->createMock(Shipment::class);
        $shipmentMock->method('getAllTracks')->willReturn([$existingTrackMock]);

        $shipmentCollectionMock = $this->createMock(ShipmentCollection::class);
        $shipmentCollectionMock->method('getSize')->willReturn(1);
        $shipmentCollectionMock->method('getLastItem')->willReturn($shipmentMock);

        $orderMock->method('getShipmentsCollection')->willReturn($shipmentCollectionMock);

        $this->mockOrderLookupByIncrementId($orderMock);

        // addTrack and save on shipment should never be called (duplicate skipped)
        $shipmentMock->expects($this->never())->method('addTrack');
        $shipmentMock->expects($this->never())->method('save');

        // But order comment with status should still be added
        $orderMock->expects($this->once())
            ->method('addCommentToStatusHistory')
            ->with('Bob Go tracking update: Delivered (ref: EXISTING001)');
        $orderMock->expects($this->once())->method('save');

        $this->service->processTrackingUpdate($data);
    }
}
