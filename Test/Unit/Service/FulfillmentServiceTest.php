<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Service;

use BobGroup\BobGo\Model\Config\ApiConfig;
use BobGroup\BobGo\Service\FulfillmentService;
use Magento\Sales\Api\Data\ShipmentItemCreationInterface;
use Magento\Sales\Api\Data\ShipmentItemCreationInterfaceFactory;
use Magento\Sales\Api\Data\ShipmentTrackCreationInterface;
use Magento\Sales\Api\Data\ShipmentTrackCreationInterfaceFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\ShipOrderInterface;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\Order\Shipment\Track;
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
        $this->apiConfigMock = $this->createMock(ApiConfig::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $dateTimeMock = $this->createMock(\Magento\Framework\Stdlib\DateTime\DateTime::class);
        $dateTimeMock->method('gmtDate')->willReturn('2026-05-19 12:00:00');

        $shipmentRepositoryMock = $this->createMock(ShipmentRepositoryInterface::class);

        $this->service = new FulfillmentService(
            $this->orderRepositoryMock,
            $this->shipOrderMock,
            $this->trackCreationFactoryMock,
            $this->itemCreationFactoryMock,
            $this->apiConfigMock,
            $this->loggerMock,
            $dateTimeMock,
            $shipmentRepositoryMock
        );
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

        $this->service->processFulfillment($orderMock, $data);
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

        // Warning, not info: canShip() is also false for held and
        // payment-review orders, where the merchant HAS fulfilled in Bob Go and
        // silently getting no Magento shipment is a real problem.
        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with(
                'Bob Go fulfillment: order cannot be shipped',
                $this->callback(function ($context) use ($orderId) {
                    return $context['order_id'] === $orderId && $context['state'] === 'complete';
                })
            );

        // shipOrder should never be called
        $this->shipOrderMock->expects($this->never())->method('execute');

        $this->service->processFulfillment($orderMock, $data);
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

        $this->service->processFulfillment($orderMock, $data);
    }

    // Payloads that carry no usable order reference no longer reach this
    // service at all — the webhook controller acknowledges them with 200 and
    // never resolves an order. See OrderResolverTest and ReceiveTest.

    public function testProcessTrackingUpdateBackfillsPlaceholderTitle(): void
    {
        $orderId = 42;
        $incrementId = '000000042';
        $data = [
            'channel_order_number' => $incrementId,
            'shipment_tracking_reference' => 'TRACK002',
            'status_friendly' => 'In Transit',
            'courier_name' => 'Sandbox Couriers',
        ];

        $orderMock = $this->createMock(\Magento\Sales\Model\Order::class);
        $orderMock->method('getEntityId')->willReturn($orderId);

        // Matching track on the shipment, but with the generic "Bob Go"
        // placeholder title left by fulfillment/created.
        $existingTrackMock = $this->createMock(Track::class);
        $existingTrackMock->method('getTrackNumber')->willReturn('TRACK002');
        $existingTrackMock->method('getTitle')->willReturn('Bob Go');
        $existingTrackMock->expects($this->once())->method('setTitle')->with('Sandbox Couriers');
        $existingTrackMock->expects($this->once())->method('save');

        $shipmentMock = $this->createMock(Shipment::class);
        $shipmentMock->method('getAllTracks')->willReturn([$existingTrackMock]);

        $shipmentCollectionMock = $this->createMock(ShipmentCollection::class);
        $shipmentCollectionMock->method('getSize')->willReturn(1);
        $shipmentCollectionMock->method('getIterator')
            ->willReturn(new \ArrayIterator([$shipmentMock]));

        $orderMock->method('getShipmentsCollection')->willReturn($shipmentCollectionMock);
        // Order comment with status should still be added.
        $orderMock->expects($this->once())
            ->method('addCommentToStatusHistory')
            ->with('Bob Go tracking update: In Transit (ref: TRACK002)');
        $orderMock->expects($this->once())->method('save');

        $this->service->processTrackingUpdate($orderMock, $data);
    }

    public function testProcessTrackingUpdateLeavesRealTitleAlone(): void
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

        // Matching track already has a real courier title — backfill must not run.
        $existingTrackMock = $this->createMock(Track::class);
        $existingTrackMock->method('getTrackNumber')->willReturn('EXISTING001');
        $existingTrackMock->method('getTitle')->willReturn('CourierCo');
        $existingTrackMock->expects($this->never())->method('setTitle');
        $existingTrackMock->expects($this->never())->method('save');

        $shipmentMock = $this->createMock(Shipment::class);
        $shipmentMock->method('getAllTracks')->willReturn([$existingTrackMock]);
        $shipmentMock->expects($this->never())->method('addTrack');
        $shipmentMock->expects($this->never())->method('save');

        $shipmentCollectionMock = $this->createMock(ShipmentCollection::class);
        $shipmentCollectionMock->method('getSize')->willReturn(1);
        $shipmentCollectionMock->method('getIterator')
            ->willReturn(new \ArrayIterator([$shipmentMock]));

        $orderMock->method('getShipmentsCollection')->willReturn($shipmentCollectionMock);
        $orderMock->expects($this->once())
            ->method('addCommentToStatusHistory')
            ->with('Bob Go tracking update: Delivered (ref: EXISTING001)');
        $orderMock->expects($this->once())->method('save');

        $this->service->processTrackingUpdate($orderMock, $data);
    }

    public function testProcessTrackingUpdateThrowsTransientWhenNoMatchingShipment(): void
    {
        $orderId = 42;
        $incrementId = '000000042';
        $data = [
            'channel_order_number' => $incrementId,
            'shipment_tracking_reference' => 'UASS4ZW6',
            'status_friendly' => 'In Transit',
            'courier_name' => 'Sandbox Couriers',
        ];

        $orderMock = $this->createMock(\Magento\Sales\Model\Order::class);
        $orderMock->method('getEntityId')->willReturn($orderId);

        // Order already has a shipment, but for a DIFFERENT tracking number —
        // simulates the race where tracking/updated for fulfillment #2 arrives
        // before fulfillment/created for #2 has committed shipment #7.
        $otherTrackMock = $this->createMock(Track::class);
        $otherTrackMock->method('getTrackNumber')->willReturn('UASSCK7G');

        $otherShipmentMock = $this->createMock(Shipment::class);
        $otherShipmentMock->method('getAllTracks')->willReturn([$otherTrackMock]);
        // Critically: must NOT have anything added to it.
        $otherShipmentMock->expects($this->never())->method('addTrack');
        $otherShipmentMock->expects($this->never())->method('save');

        $shipmentCollectionMock = $this->createMock(ShipmentCollection::class);
        $shipmentCollectionMock->method('getSize')->willReturn(1);
        $shipmentCollectionMock->method('getIterator')
            ->willReturn(new \ArrayIterator([$otherShipmentMock]));

        $orderMock->method('getShipmentsCollection')->willReturn($shipmentCollectionMock);
        // No comment should be added — we're throwing so Bob Go retries.
        $orderMock->expects($this->never())->method('addCommentToStatusHistory');

        $this->expectException(\BobGroup\BobGo\Service\TransientWebhookException::class);
        $this->expectExceptionMessageMatches('/No shipment carries tracking UASS4ZW6 yet/');

        $this->service->processTrackingUpdate($orderMock, $data);
    }
}
