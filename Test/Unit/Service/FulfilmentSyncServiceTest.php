<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Service;

use BobGroup\BobGo\Api\BobGoApiClient;
use BobGroup\BobGo\Api\BobGoApiException;
use BobGroup\BobGo\Model\Config\ApiConfig;
use BobGroup\BobGo\Service\FulfilmentSyncService;
use BobGroup\BobGo\Service\SyncLogger;
use BobGroup\BobGo\Service\TransientWebhookException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Api\Data\ShipmentItemCreationInterface;
use Magento\Sales\Api\Data\ShipmentItemCreationInterfaceFactory;
use Magento\Sales\Api\Data\ShipmentTrackCreationInterface;
use Magento\Sales\Api\Data\ShipmentTrackCreationInterfaceFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Sales\Api\ShipOrderInterface;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\Order\Shipment\Track;
use Magento\Sales\Model\ResourceModel\Order\Shipment\Collection as ShipmentCollection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The refresh-from-API path that every inbound signal now funnels through.
 *
 * Two properties matter most here and neither existed before:
 *
 *  - Reconciliation creates the shipments a lost webhook never created. The old
 *    design made the webhook the *only* path that could ship an order, so a
 *    delivery dropped for any reason (order on hold at the time, unresolvable
 *    reference, a bug) left an order shipped in Bob Go and never shipped in
 *    Magento, with no alert and no recovery.
 *  - "Ship everything" is never the default for an unknown item scope. An empty
 *    item list means "ship the whole order" to ShipOrderInterface, so guessing
 *    silently closes orders that were only partly fulfilled.
 */
class FulfilmentSyncServiceTest extends TestCase
{
    private $apiClient;
    private $orderRepository;
    private $shipOrder;
    private $trackCreationFactory;
    private $itemCreationFactory;
    private $shipmentRepository;
    private $apiConfig;
    private $syncLogger;
    private $logger;
    /** @var FulfilmentSyncService */
    private $service;

    protected function setUp(): void
    {
        $this->apiClient = $this->createMock(BobGoApiClient::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->shipOrder = $this->createMock(ShipOrderInterface::class);
        $this->trackCreationFactory = $this->createMock(ShipmentTrackCreationInterfaceFactory::class);
        $this->itemCreationFactory = $this->createMock(ShipmentItemCreationInterfaceFactory::class);
        $this->shipmentRepository = $this->createMock(ShipmentRepositoryInterface::class);
        $this->apiConfig = $this->createMock(ApiConfig::class);
        $this->syncLogger = $this->createMock(SyncLogger::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtDate')->willReturn('2026-05-19 12:00:00');

        $this->trackCreationFactory->method('create')
            ->willReturnCallback(function () {
                return $this->createMock(ShipmentTrackCreationInterface::class);
            });
        $this->itemCreationFactory->method('create')
            ->willReturnCallback(function () {
                return $this->createMock(ShipmentItemCreationInterface::class);
            });

        $this->service = new FulfilmentSyncService(
            $this->apiClient,
            $this->orderRepository,
            $this->shipOrder,
            $this->trackCreationFactory,
            $this->itemCreationFactory,
            $this->shipmentRepository,
            $this->apiConfig,
            $this->syncLogger,
            $dateTime,
            $this->logger
        );
    }

    // ------------------------------------------------------------ no link, no request

    public function testDoesNothingWithoutABobGoLink(): void
    {
        $order = $this->order(7, '');

        $this->apiClient->expects($this->never())->method('get');
        $this->orderRepository->expects($this->never())->method('save');

        $this->assertFalse($this->service->syncOrder($order));
    }

    // --------------------------------------------------------------- blob persistence

    public function testPersistsShipmentsBlobWhenChanged(): void
    {
        $writes = [];
        $order = $this->order(7, '987', '[]', $writes);

        $this->apiClient->method('get')
            ->with('order-fulfillments', ['order_id' => '987'])
            ->willReturn(['order_fulfillments' => [$this->fulfilment('UASDRTR3', 'Demo Couriers', 'collected')]]);

        $this->orderRepository->expects($this->once())->method('save');

        $this->assertTrue($this->service->syncOrder($order));

        $decoded = json_decode((string) $writes['bobgo_shipments'], true);
        $this->assertSame('UASDRTR3', $decoded[0]['tracking_number']);
        $this->assertSame('Demo Couriers', $decoded[0]['courier']);
        $this->assertSame('collected', $decoded[0]['status']);
        $this->assertSame('2546', $decoded[0]['fulfillment_id']);
        $this->assertSame('2026-05-19 12:00:00', $writes['bobgo_last_synced']);
    }

    public function testIsANoOpWhenNothingChanged(): void
    {
        $order = $this->order(7, '987');
        $this->apiClient->method('get')
            ->willReturn(['order_fulfillments' => [$this->fulfilment('UASDRTR3', 'Demo Couriers', 'collected')]]);

        // First pass establishes the blob, second must not write again.
        $this->service->syncOrder($order);
        $this->orderRepository->expects($this->never())->method('save');
        $this->service->syncOrder($order);
    }

    public function testToleratesABareListResponse(): void
    {
        $writes = [];
        $order = $this->order(7, '987', '', $writes);

        $this->apiClient->method('get')->willReturn([
            ['tracking_number' => 'A', 'status' => 'collected'],
            ['tracking_number' => 'B', 'status' => 'collected'],
        ]);

        $this->service->syncOrder($order);

        $this->assertCount(2, json_decode((string) $writes['bobgo_shipments'], true));
    }

    public function testApiErrorIsLoggedAndReported(): void
    {
        $order = $this->order(7, '987');
        $this->apiClient->method('get')
            ->willThrowException(new BobGoApiException('boom', 500, '', 'order-fulfillments'));

        $this->logger->expects($this->once())->method('warning');
        $this->syncLogger->expects($this->once())->method('logOutbound')
            ->with($this->anything(), $this->anything(), $this->anything(), 500, false);

        $this->assertFalse($this->service->syncOrder($order));
    }

    // ------------------------------------------------------------ shipment creation

    /**
     * The whole point of P1-6: no webhook involved, and the missing shipment
     * still gets created.
     */
    public function testCreatesTheShipmentAuthoritativeStateSaysExists(): void
    {
        $order = $this->order(7, '987');
        $order->method('canShip')->willReturn(true);
        $this->noShipmentsYet($order);
        $this->apiConfig->method('shouldNotifyCustomer')->willReturn(true);

        $this->apiClient->method('get')
            ->willReturn(['order_fulfillments' => [$this->fulfilment('UASDRTR3', 'Demo Couriers', 'collected')]]);

        $this->shipOrder->expects($this->once())
            ->method('execute')
            ->with(7, [], true, false, null, $this->countOf(1))
            ->willReturn(55);

        // And the fulfilment id is stamped so later ticks dedup against it.
        $shipment = $this->createMock(Shipment::class);
        $shipment->expects($this->once())->method('setData')->with('bobgo_fulfillment_id', '2546');
        $this->shipmentRepository->method('get')->with(55)->willReturn($shipment);
        $this->shipmentRepository->expects($this->once())->method('save');

        $this->service->syncOrder($order);
    }

    public function testDoesNotCreateAShipmentForACancelledFulfilment(): void
    {
        $order = $this->order(7, '987');
        $order->method('canShip')->willReturn(true);
        $this->noShipmentsYet($order);

        $this->apiClient->method('get')->willReturn([
            'order_fulfillments' => [$this->fulfilment('UASDRTR3', 'Demo Couriers', 'cancelled')],
        ]);

        $this->shipOrder->expects($this->never())->method('execute');

        $this->service->syncOrder($order);
    }

    /**
     * @dataProvider dedupProvider
     */
    public function testDoesNotDuplicateAnExistingShipment(string $stampedId, string $trackNumber): void
    {
        $order = $this->order(7, '987');
        $order->method('canShip')->willReturn(true);

        $track = $this->createMock(Track::class);
        $track->method('getTrackNumber')->willReturn($trackNumber);
        $track->method('getTitle')->willReturn('Demo Couriers');

        $shipment = $this->createMock(Shipment::class);
        $shipment->method('getData')->willReturn($stampedId);
        $shipment->method('getAllTracks')->willReturn([$track]);

        $this->haveShipments($order, [$shipment]);

        $this->apiClient->method('get')->willReturn([
            'order_fulfillments' => [$this->fulfilment('UASDRTR3', 'Demo Couriers', 'collected')],
        ]);

        $this->shipOrder->expects($this->never())->method('execute');

        $this->service->syncOrder($order);
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public function dedupProvider(): array
    {
        return [
            'by stamped fulfilment id' => ['2546', 'SOMETHING-ELSE'],
            'by tracking number' => ['', 'UASDRTR3'],
        ];
    }

    public function testBackfillsThePlaceholderCourierTitleOnAnExistingShipment(): void
    {
        $order = $this->order(7, '987');
        $order->method('canShip')->willReturn(true);

        $track = $this->createMock(Track::class);
        $track->method('getTrackNumber')->willReturn('UASDRTR3');
        $track->method('getTitle')->willReturn('Bob Go');
        $track->expects($this->once())->method('setTitle')->with('Demo Couriers');
        $track->expects($this->once())->method('save');

        $shipment = $this->createMock(Shipment::class);
        $shipment->method('getData')->willReturn('');
        $shipment->method('getAllTracks')->willReturn([$track]);
        $this->haveShipments($order, [$shipment]);

        $this->apiClient->method('get')->willReturn([
            'order_fulfillments' => [$this->fulfilment('UASDRTR3', 'Demo Couriers', 'collected')],
        ]);

        $this->service->syncOrder($order);
    }

    public function testLeavesARealCourierTitleAlone(): void
    {
        $order = $this->order(7, '987');
        $order->method('canShip')->willReturn(true);

        $track = $this->createMock(Track::class);
        $track->method('getTrackNumber')->willReturn('UASDRTR3');
        $track->method('getTitle')->willReturn('The Courier Guy');
        $track->expects($this->never())->method('setTitle');

        $shipment = $this->createMock(Shipment::class);
        $shipment->method('getData')->willReturn('');
        $shipment->method('getAllTracks')->willReturn([$track]);
        $this->haveShipments($order, [$shipment]);

        $this->apiClient->method('get')->willReturn([
            'order_fulfillments' => [$this->fulfilment('UASDRTR3', 'Demo Couriers', 'collected')],
        ]);

        $this->service->syncOrder($order);
    }

    public function testSkipsWhenTheOrderCannotBeShipped(): void
    {
        $order = $this->order(7, '987');
        $order->method('canShip')->willReturn(false);
        $this->noShipmentsYet($order);

        $this->apiClient->method('get')->willReturn([
            'order_fulfillments' => [$this->fulfilment('UASDRTR3', 'Demo Couriers', 'collected')],
        ]);

        // Warning, not silence: a merchant fulfilling in Bob Go while the Magento
        // order is on hold needs to know why no shipment appeared. Unlike before,
        // reconciliation will retry hourly.
        $this->logger->expects($this->atLeastOnce())->method('warning');
        $this->shipOrder->expects($this->never())->method('execute');

        $this->service->syncOrder($order);
    }

    // -------------------------------------------------------------- item scope safety

    public function testShipsTheWholeOrderOnlyForASingleFulfilmentWithNoItemDetail(): void
    {
        $order = $this->order(7, '987');
        $order->method('canShip')->willReturn(true);
        $this->noShipmentsYet($order);

        $this->apiClient->method('get')->willReturn([
            'order_fulfillments' => [$this->fulfilment('UASDRTR3', 'Demo Couriers', 'collected')],
        ]);

        $this->shipOrder->expects($this->once())->method('execute')
            ->with(7, [], $this->anything(), false, null, $this->anything())
            ->willReturn(55);

        $this->service->syncOrder($order);
    }

    /**
     * Two fulfilments and no item detail means we cannot tell which items each
     * covers. Shipping everything would close the order on the first one.
     */
    public function testRefusesToShipWhenItemScopeIsUnknowableAcrossMultipleFulfilments(): void
    {
        $order = $this->order(7, '987');
        $order->method('canShip')->willReturn(true);
        $this->noShipmentsYet($order);

        $this->apiClient->method('get')->willReturn([
            'order_fulfillments' => [
                $this->fulfilment('TRACK-A', 'Demo Couriers', 'collected'),
                $this->fulfilment('TRACK-B', 'Demo Couriers', 'collected'),
            ],
        ]);

        $this->logger->expects($this->atLeastOnce())->method('error');
        $this->shipOrder->expects($this->never())->method('execute');

        $this->service->syncOrder($order);
    }

    public function testUsesWebhookItemsWhenTheAuthoritativeRecordHasNone(): void
    {
        $order = $this->order(7, '987');
        $order->method('canShip')->willReturn(true);
        $this->noShipmentsYet($order);
        $order->method('getAllItems')->willReturn([$this->orderItem(11, 'SKU-A')]);

        $this->apiClient->method('get')->willReturn([
            'order_fulfillments' => [
                $this->fulfilment('TRACK-A', 'Demo Couriers', 'collected'),
                $this->fulfilment('TRACK-B', 'Demo Couriers', 'collected'),
            ],
        ]);

        // One matched item, so a partial shipment rather than a refusal.
        $this->shipOrder->expects($this->exactly(2))->method('execute')
            ->with(7, $this->countOf(1), $this->anything(), false, null, $this->anything())
            ->willReturn(55);

        $this->service->syncOrder($order, [['sku' => 'SKU-A', 'fulfilled_qty' => 1]]);
    }

    public function testRefusesToShipWhenNamedItemsAreNotOnTheOrder(): void
    {
        $order = $this->order(7, '987');
        $order->method('canShip')->willReturn(true);
        $this->noShipmentsYet($order);
        $order->method('getAllItems')->willReturn([$this->orderItem(11, 'SKU-A')]);

        $fulfilment = $this->fulfilment('UASDRTR3', 'Demo Couriers', 'collected');
        $fulfilment['items'] = [['sku' => 'NOT-ON-THIS-ORDER', 'fulfilled_qty' => 1]];

        $this->apiClient->method('get')->willReturn(['order_fulfillments' => [$fulfilment]]);

        $this->logger->expects($this->atLeastOnce())->method('error');
        $this->shipOrder->expects($this->never())->method('execute');

        $this->service->syncOrder($order);
    }

    public function testShipmentCreationFailureIsTransient(): void
    {
        $order = $this->order(7, '987');
        $order->method('canShip')->willReturn(true);
        $this->noShipmentsYet($order);

        $this->apiClient->method('get')->willReturn([
            'order_fulfillments' => [$this->fulfilment('UASDRTR3', 'Demo Couriers', 'collected')],
        ]);
        $this->shipOrder->method('execute')->willThrowException(new \Exception('Deadlock found'));

        $this->expectException(TransientWebhookException::class);
        $this->service->syncOrder($order);
    }

    // ----------------------------------------------------------------------- helpers

    /**
     * @return array<string,mixed>
     */
    private function fulfilment(string $tracking, string $courier, string $status): array
    {
        return [
            'order_fulfillment' => ['id' => 2546, 'channel_ref_id' => ''],
            'buyer_collection'  => null,
            'shipment' => [
                'tracking_reference'          => $tracking,
                'provider_tracking_reference' => 'XK3VVL',
                'provider_slug'               => 'demo',
                'status'                      => $status,
                'provider'      => ['name' => $courier, 'slug' => 'demo'],
                'service_level' => ['name' => 'Bob Box', 'code' => 'BOXL-S'],
            ],
        ];
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    private function order(int $entityId, string $bobgoOrderId, string $shipments = '', ?array &$writes = null)
    {
        $order = $this->createMock(\Magento\Sales\Model\Order::class);
        $order->method('getEntityId')->willReturn($entityId);
        $order->method('getIncrementId')->willReturn('0000000' . $entityId);
        $order->method('getState')->willReturn('processing');

        $store = ['bobgo_order_id' => $bobgoOrderId, 'bobgo_shipments' => $shipments];
        $order->method('getData')->willReturnCallback(function ($key = null) use (&$store) {
            return $store[$key] ?? null;
        });
        $order->method('setData')->willReturnCallback(function ($k, $v = null) use (&$store, &$writes) {
            $store[$k] = $v;
            if ($writes !== null) {
                $writes[$k] = $v;
            }
            return null;
        });
        return $order;
    }

    /**
     * @param \PHPUnit\Framework\MockObject\MockObject $order
     */
    private function noShipmentsYet($order): void
    {
        $collection = $this->createMock(ShipmentCollection::class);
        $collection->method('getSize')->willReturn(0);
        $order->method('getShipmentsCollection')->willReturn($collection);
    }

    /**
     * @param \PHPUnit\Framework\MockObject\MockObject $order
     * @param array<int,object> $shipments
     */
    private function haveShipments($order, array $shipments): void
    {
        $collection = $this->createMock(ShipmentCollection::class);
        $collection->method('getSize')->willReturn(count($shipments));
        $collection->method('getIterator')->willReturn(new \ArrayIterator($shipments));
        $order->method('getShipmentsCollection')->willReturn($collection);
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    private function orderItem(int $itemId, string $sku)
    {
        $item = $this->createMock(\Magento\Sales\Model\Order\Item::class);
        $item->method('getItemId')->willReturn($itemId);
        $item->method('getSku')->willReturn($sku);
        $item->method('getData')->willReturn(null);
        return $item;
    }
}
