<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Service;

use BobGroup\BobGo\Model\Config\ApiConfig;
use BobGroup\BobGo\Service\FulfillmentService;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\Data\OrderInterface;
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

        $this->service = new FulfillmentService(
            $this->orderRepositoryMock,
            $this->shipOrderMock,
            $this->trackCreationFactoryMock,
            $this->itemCreationFactoryMock,
            $this->searchCriteriaBuilderMock,
            $this->trackFactoryMock,
            $this->apiConfigMock,
            $this->loggerMock
        );
    }

    public function testProcessFulfillmentCreatesShipment(): void
    {
        $orderId = 42;
        $data = [
            'channel_ref_id' => (string) $orderId,
            'fulfillment_id' => 'ful_123',
            'tracking_numbers' => [
                ['number' => 'TRACK001', 'carrier' => 'CourierCo'],
            ],
            'line_items' => [],
        ];

        $orderMock = $this->createMock(\Magento\Sales\Model\Order::class);
        $orderMock->method('getEntityId')->willReturn($orderId);
        $orderMock->method('canShip')->willReturn(true);

        // No existing shipments
        $shipmentCollectionMock = $this->createMock(ShipmentCollection::class);
        $shipmentCollectionMock->method('getSize')->willReturn(0);
        $orderMock->method('getShipmentsCollection')->willReturn($shipmentCollectionMock);

        $this->orderRepositoryMock->method('get')->with($orderId)->willReturn($orderMock);

        // Track creation
        $trackMock = $this->createMock(ShipmentTrackCreationInterface::class);
        $trackMock->expects($this->once())->method('setTrackNumber')->with('TRACK001');
        $trackMock->expects($this->once())->method('setCarrierCode')->with('bobgo');
        $trackMock->expects($this->once())->method('setTitle')->with('CourierCo');
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
        $data = [
            'channel_ref_id' => (string) $orderId,
            'fulfillment_id' => 'ful_123',
            'tracking_numbers' => [],
            'line_items' => [],
        ];

        $orderMock = $this->createMock(\Magento\Sales\Model\Order::class);
        $orderMock->method('getEntityId')->willReturn($orderId);
        $orderMock->method('getState')->willReturn('complete');
        $orderMock->method('canShip')->willReturn(false);

        $this->orderRepositoryMock->method('get')->with($orderId)->willReturn($orderMock);

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
        $data = [
            'channel_ref_id' => (string) $orderId,
            'fulfillment_id' => 'ful_123',
            'tracking_numbers' => [
                ['number' => 'TRACK001', 'carrier' => 'CourierCo'],
            ],
            'line_items' => [],
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

        $this->orderRepositoryMock->method('get')->with($orderId)->willReturn($orderMock);

        // shipOrder should never be called (duplicate)
        $this->shipOrderMock->expects($this->never())->method('execute');

        $this->loggerMock->expects($this->once())
            ->method('info')
            ->with(
                'Bob Go fulfillment: shipment already exists',
                $this->callback(function ($context) use ($orderId) {
                    return $context['order_id'] === $orderId;
                })
            );

        $this->service->processFulfillment($data);
    }

    public function testProcessFulfillmentMissingChannelRefId(): void
    {
        $data = [
            'fulfillment_id' => 'ful_123',
            'tracking_numbers' => [],
        ];

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with('Bob Go fulfillment missing channel_ref_id', ['data' => $data]);

        // Should not attempt to find order
        $this->orderRepositoryMock->expects($this->never())->method('get');
        $this->shipOrderMock->expects($this->never())->method('execute');

        $this->service->processFulfillment($data);
    }

    public function testProcessTrackingUpdateAddsTrack(): void
    {
        $orderId = 42;
        $data = [
            'channel_ref_id' => (string) $orderId,
            'tracking_numbers' => [
                ['number' => 'TRACK002', 'carrier' => 'FastShip'],
            ],
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

        $this->orderRepositoryMock->method('get')->with($orderId)->willReturn($orderMock);

        // Track model creation
        $trackModelMock = $this->createMock(Track::class);
        $trackModelMock->expects($this->once())->method('setTrackNumber')->with('TRACK002');
        $trackModelMock->expects($this->once())->method('setCarrierCode')->with('bobgo');
        $trackModelMock->expects($this->once())->method('setTitle')->with('FastShip');
        $this->trackFactoryMock->method('create')->willReturn($trackModelMock);

        $shipmentMock->expects($this->once())->method('addTrack')->with($trackModelMock);
        $shipmentMock->expects($this->once())->method('save');

        $this->service->processTrackingUpdate($data);
    }

    public function testProcessTrackingUpdateSkipsDuplicates(): void
    {
        $orderId = 42;
        $data = [
            'channel_ref_id' => (string) $orderId,
            'tracking_numbers' => [
                ['number' => 'EXISTING001', 'carrier' => 'CourierCo'],
            ],
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

        $this->orderRepositoryMock->method('get')->with($orderId)->willReturn($orderMock);

        // addTrack and save should never be called (duplicate skipped)
        $shipmentMock->expects($this->never())->method('addTrack');
        $shipmentMock->expects($this->never())->method('save');

        $this->service->processTrackingUpdate($data);
    }
}
