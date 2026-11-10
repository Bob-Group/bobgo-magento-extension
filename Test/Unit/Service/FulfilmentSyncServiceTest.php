<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Service;

use BobGroup\BobGo\Api\BobGoApiClient;
use BobGroup\BobGo\Api\BobGoApiException;
use BobGroup\BobGo\Model\Config\ApiConfig;
use BobGroup\BobGo\Service\FulfilmentSyncService;
use BobGroup\BobGo\Service\InboundGuard;
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
    /** @var InboundGuard */
    private $inboundGuard;
    /** @var array<int,\stdClass> */
    private $shipmentItems = [];
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

        // Real, not mocked: the guard is the thing under test in the loop-protection
        // cases below, and its nesting behaviour is the part that has to be right.
        $this->inboundGuard = new InboundGuard();

        $this->trackCreationFactory->method('create')
            ->willReturnCallback(function () {
                return $this->createMock(ShipmentTrackCreationInterface::class);
            });
        // Recording mocks: the item id and qty actually requested is the whole
        // question for the fulfilment-item matching tests.
        $this->shipmentItems = [];
        $this->itemCreationFactory->method('create')
            ->willReturnCallback(function () {
                $captured = new \stdClass();
                $captured->orderItemId = null;
                $captured->qty = null;
                $this->shipmentItems[] = $captured;

                $item = $this->createMock(ShipmentItemCreationInterface::class);
                $item->method('setOrderItemId')->willReturnCallback(
                    static function ($id) use ($captured) {
                        $captured->orderItemId = $id;
                    }
                );
                $item->method('setQty')->willReturnCallback(
                    static function ($qty) use ($captured) {
                        $captured->qty = $qty;
                    }
                );
                return $item;
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
            $this->inboundGuard,
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

    /**
     * The order save below queues an outbound push unless the order is marked
     * inbound-driven, and reconciliation — cron and the admin Resync button —
     * reached this save unmarked. The webhook controller had its own guard, so
     * only the reconcile path was exposed: every hourly pass re-queued every
     * order it touched, and the next push cron PATCHed them all back with nothing
     * changed. Observed live as six redundant 200 PATCHes in one cron run.
     *
     * Guarding inside this service rather than at each caller is what makes it
     * structural — a future caller cannot forget.
     */
    public function testMarksTheOrderInboundDrivenWhileItSaves(): void
    {
        $order = $this->order(7, '987', '[]');

        $this->apiClient->method('get')
            ->willReturn(['order_fulfillments' => [$this->fulfilment('UASDRTR3', 'Demo Couriers', 'collected')]]);

        $guardedDuringSave = null;
        $this->orderRepository->expects($this->once())->method('save')
            ->willReturnCallback(function ($saved) use (&$guardedDuringSave) {
                $guardedDuringSave = $this->inboundGuard->isActive(7);
                return $saved;
            });

        $this->service->syncOrder($order);

        $this->assertTrue($guardedDuringSave, 'the save must happen inside the guard');
        $this->assertFalse($this->inboundGuard->isActive(7), 'and the mark must be released after');
    }

    /**
     * A stuck mark would suppress every genuine push for that order for the rest
     * of the request, so the release has to survive a failure too.
     */
    public function testReleasesTheInboundMarkWhenTheRefreshThrows(): void
    {
        $order = $this->order(7, '987', '[]');

        $this->apiClient->method('get')
            ->willReturn(['order_fulfillments' => [$this->fulfilment('UASDRTR3', 'Demo Couriers', 'collected')]]);
        $this->orderRepository->method('save')
            ->willThrowException(new \RuntimeException('database went away'));

        try {
            $this->service->syncOrder($order);
        } catch (\Throwable $e) {
            // The mark, not the exception, is what this test is about.
        }

        $this->assertFalse($this->inboundGuard->isActive(7));
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
        $exception = new BobGoApiException('boom', 500, '{"message":"upstream exploded"}', 'order-fulfillments');
        $this->apiClient->method('get')->willThrowException($exception);

        $this->logger->expects($this->once())->method('warning');
        // The exception is handed over whole, so the response body reaches the row
        // instead of being flattened to "failed with status 500".
        $this->syncLogger->expects($this->once())->method('logOutboundFailure')
            ->with($this->anything(), ['order_id' => '987'], $exception, 7);

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
                $this->fulfilment('TRACK-A', 'Demo Couriers', 'collected', 2546),
                $this->fulfilment('TRACK-B', 'Demo Couriers', 'collected', 2547),
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
                $this->fulfilment('TRACK-A', 'Demo Couriers', 'collected', 2546),
                $this->fulfilment('TRACK-B', 'Demo Couriers', 'collected', 2547),
            ],
        ]);

        // One matched item, so a partial shipment rather than a refusal.
        $this->shipOrder->expects($this->exactly(2))->method('execute')
            ->with(7, $this->countOf(1), $this->anything(), false, null, $this->anything())
            ->willReturn(55);

        $this->service->syncOrder($order, [['sku' => 'SKU-A', 'fulfilled_qty' => 1]]);
    }

    // ------------------------------------------- the real shape Bob Go returns
    //
    // Everything below is built from a response captured off the sandbox on
    // 2026-07-30, after fulfilling a two-line order. It is the one thing in this
    // service no amount of code review could settle, and the shape is not what the
    // matching code assumed: the sku and our own item id sit under `order_item`,
    // and the row's own `id` is a fulfilment-item id from a separate namespace.
    //
    // The tests already here all passed against the broken matcher, because every
    // fixture used the flat shape the implementation expected.

    public function testResolvesItemsFromTheRealNestedShape(): void
    {
        $order = $this->order(7, '15891');
        $order->method('canShip')->willReturn(true);
        $this->noShipmentsYet($order);
        $order->method('getAllItems')->willReturn([
            $this->orderItem(22, 'WS01-S-Green', '19795'),
            $this->orderItem(24, 'WS01-XS-Black', '19796'),
        ]);

        $this->apiClient->method('get')->willReturn([
            'order_fulfillments' => [$this->fulfilmentWithItems([
                $this->realItemRow(3398, 19795, 22, 'WS01-S-Green'),
                $this->realItemRow(3399, 19796, 24, 'WS01-XS-Black'),
            ])],
            'count' => 1,
        ]);

        $this->shipOrder->expects($this->once())->method('execute')->willReturn(55);

        $this->service->syncOrder($order);

        $this->assertSame([22, 24], array_column($this->shipmentItems, 'orderItemId'));
        $this->assertSame([1.0, 1.0], array_column($this->shipmentItems, 'qty'));
    }

    /**
     * The row's `id` (3398) is a fulfilment-item id. Reading it as an order-item id
     * matched nothing on the order that exposed this, but nothing stops it
     * colliding with a real bobgo_order_item_id on another order — and then the
     * wrong line ships.
     */
    public function testNeverTreatsTheFulfilmentItemIdAsAnOrderItemId(): void
    {
        $order = $this->order(7, '15891');
        $order->method('canShip')->willReturn(true);
        $this->noShipmentsYet($order);
        // A decoy whose Bob Go item id equals the row's fulfilment-item id.
        $order->method('getAllItems')->willReturn([
            $this->orderItem(99, 'DECOY', '3398'),
            $this->orderItem(22, 'WS01-S-Green', '19795'),
        ]);

        $this->apiClient->method('get')->willReturn([
            'order_fulfillments' => [$this->fulfilmentWithItems([
                $this->realItemRow(3398, 19795, 22, 'WS01-S-Green'),
            ])],
        ]);

        $this->shipOrder->expects($this->once())->method('execute')->willReturn(55);

        $this->service->syncOrder($order);

        $this->assertSame([22], array_column($this->shipmentItems, 'orderItemId'));
    }

    /**
     * Bob Go echoes the child's id, because the child is what we send as the order
     * line — but Magento cannot ship a configurable's child. isDummy(true) is true
     * for it, qty_to_ship is 0, and qty_shipped is recorded on the parent.
     *
     * The old SKU-based matching got this right by accident: parent and child share
     * a SKU, getAllItems() yields the parent first, and array_shift took it.
     */
    public function testShipsTheConfigurableParentWhenBobGoNamesTheChild(): void
    {
        $parent = $this->orderItem(21, 'WS01-S-Green');
        $child = $this->orderItem(22, 'WS01-S-Green', '19795', $parent);

        $order = $this->order(7, '15891');
        $order->method('canShip')->willReturn(true);
        $this->noShipmentsYet($order);
        $order->method('getAllItems')->willReturn([$parent, $child]);

        $this->apiClient->method('get')->willReturn([
            'order_fulfillments' => [$this->fulfilmentWithItems([
                $this->realItemRow(3398, 19795, 22, 'WS01-S-Green'),
            ])],
        ]);

        $this->shipOrder->expects($this->once())->method('execute')->willReturn(55);

        $this->service->syncOrder($order);

        $this->assertSame([21], array_column($this->shipmentItems, 'orderItemId'), 'the parent ships, not the child');
    }

    /**
     * `order_item.fulfilled_qty` is the running total across every fulfilment, so
     * a second partial fulfilment would ship the whole line again if it were used.
     * Only the row's own qty describes this fulfilment.
     */
    public function testUsesTheRowQtyNotTheRunningFulfilledTotal(): void
    {
        $order = $this->order(7, '15891');
        $order->method('canShip')->willReturn(true);
        $this->noShipmentsYet($order);
        $order->method('getAllItems')->willReturn([$this->orderItem(22, 'SKU-A', '19795')]);

        $this->apiClient->method('get')->willReturn([
            'order_fulfillments' => [$this->fulfilmentWithItems([
                // This fulfilment covers 1; 3 have been fulfilled in total.
                $this->realItemRow(3398, 19795, 22, 'SKU-A', 1, 3),
            ])],
        ]);

        $this->shipOrder->expects($this->once())->method('execute')->willReturn(55);

        $this->service->syncOrder($order);

        $this->assertSame([1.0], array_column($this->shipmentItems, 'qty'));
    }

    /**
     * Webhook bodies have carried these fields flat, and that path still works.
     */
    public function testStillAcceptsAFlatItemShape(): void
    {
        $order = $this->order(7, '15891');
        $order->method('canShip')->willReturn(true);
        $this->noShipmentsYet($order);
        $order->method('getAllItems')->willReturn([$this->orderItem(22, 'SKU-A', '19795')]);

        $this->apiClient->method('get')->willReturn([
            'order_fulfillments' => [$this->fulfilmentWithItems([
                ['channel_ref_id' => 22, 'sku' => 'SKU-A', 'fulfilled_qty' => 2],
            ])],
        ]);

        $this->shipOrder->expects($this->once())->method('execute')->willReturn(55);

        $this->service->syncOrder($order);

        $this->assertSame([22], array_column($this->shipmentItems, 'orderItemId'));
        $this->assertSame([2.0], array_column($this->shipmentItems, 'qty'));
    }

    /**
     * Two rows collapsing onto one shippable parent must not ship it twice, and the
     * total is clamped by what Magento says is left on the line.
     */
    public function testCollapsedRowsAccumulateOnceAndAreClamped(): void
    {
        $parent = $this->orderItem(21, 'BUNDLE');   // getQtyToShip() => 5.0
        $childA = $this->orderItem(22, 'PART-A', '19795', $parent);
        $childB = $this->orderItem(23, 'PART-B', '19796', $parent);

        $order = $this->order(7, '15891');
        $order->method('canShip')->willReturn(true);
        $this->noShipmentsYet($order);
        $order->method('getAllItems')->willReturn([$parent, $childA, $childB]);

        $this->apiClient->method('get')->willReturn([
            'order_fulfillments' => [$this->fulfilmentWithItems([
                $this->realItemRow(3398, 19795, 22, 'PART-A', 4),
                $this->realItemRow(3399, 19796, 23, 'PART-B', 4),
            ])],
        ]);

        $this->shipOrder->expects($this->once())->method('execute')->willReturn(55);

        $this->service->syncOrder($order);

        $this->assertCount(1, $this->shipmentItems, 'one line, not two');
        $this->assertSame(21, $this->shipmentItems[0]->orderItemId);
        $this->assertSame(5.0, $this->shipmentItems[0]->qty, 'clamped from 8 to what the line holds');
    }

    /**
     * The refusal is still a refusal — but the log now names the SKUs, which it
     * could not do while it read them from the wrong level. An empty list made a
     * genuine mismatch look like an empty fulfilment.
     */
    public function testTheRefusalLogNamesTheSkusItCouldNotMatch(): void
    {
        $order = $this->order(7, '15891');
        $order->method('canShip')->willReturn(true);
        $this->noShipmentsYet($order);
        $order->method('getAllItems')->willReturn([$this->orderItem(22, 'SOMETHING-ELSE', '11111')]);

        $this->apiClient->method('get')->willReturn([
            'order_fulfillments' => [$this->fulfilmentWithItems([
                $this->realItemRow(3398, 19795, 88, 'NOT-ON-THIS-ORDER'),
            ])],
        ]);

        $logged = null;
        $this->logger->method('error')->willReturnCallback(
            static function ($message, $context = []) use (&$logged) {
                $logged = $context;
            }
        );
        $this->shipOrder->expects($this->never())->method('execute');

        $this->service->syncOrder($order);

        $this->assertSame(['NOT-ON-THIS-ORDER'], $logged['skus']);
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
    private function fulfilment(string $tracking, string $courier, string $status, int $id = 2546): array
    {
        return [
            'order_fulfillment' => ['id' => $id, 'channel_ref_id' => ''],
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
    private function orderItem(int $itemId, string $sku, ?string $bobgoItemId = null, $parent = null)
    {
        $item = $this->createMock(\Magento\Sales\Model\Order\Item::class);
        $item->method('getItemId')->willReturn($itemId);
        $item->method('getSku')->willReturn($sku);
        $item->method('getData')->willReturnCallback(
            static function ($key = null) use ($bobgoItemId) {
                return $key === 'bobgo_order_item_id' ? $bobgoItemId : null;
            }
        );
        $item->method('getParentItem')->willReturn($parent);
        // Magento's own rule: a child of a configurable is a dummy for shipment
        // purposes, so qty_to_ship is 0 and qty_shipped lands on the parent.
        $item->method('isDummy')->willReturn($parent !== null);
        $item->method('getQtyToShip')->willReturn($parent !== null ? 0.0 : 5.0);
        return $item;
    }

    /**
     * A fulfilment record carrying items, in the shape
     * GET /v2/order-fulfillments actually returns — captured from the sandbox.
     *
     * @param array<int,array<string,mixed>> $itemRows
     * @return array<string,mixed>
     */
    private function fulfilmentWithItems(array $itemRows, string $tracking = 'UASD9KL8', int $id = 2814): array
    {
        $record = $this->fulfilment($tracking, 'Demo Couriers', 'pending-collection', $id);
        $record['order_fulfillment']['items'] = $itemRows;
        return $record;
    }

    /**
     * One fulfilment-item row, exactly as Bob Go returns it. Note that `id` is the
     * fulfilment-item id — a different namespace from any order-item id — and that
     * the sku and our own item id sit under `order_item`.
     *
     * @return array<string,mixed>
     */
    private function realItemRow(
        int $fulfilmentItemId,
        int $bobgoOrderItemId,
        int $magentoItemId,
        string $sku,
        int $qty = 1,
        int $totalFulfilledQty = 1
    ): array {
        return [
            'id'                   => $fulfilmentItemId,
            'order_fulfillment_id' => 2814,
            'order_id'             => 15891,
            'order_item_id'        => $bobgoOrderItemId,
            'order_item'           => [
                'id'            => $bobgoOrderItemId,
                'sku'           => $sku,
                'channel_ref_id' => $magentoItemId,
                // The total across every fulfilment, not this one's share.
                'fulfilled_qty' => $totalFulfilledQty,
                'qty'           => 1,
            ],
            'qty'                  => $qty,
        ];
    }

    /**
     * Magento loads and caches the order's shipment collection on first access, so
     * a shipment created for the first record is invisible to the dedup check for
     * the second. Two records sharing a tracking number would then produce two
     * Magento shipments for one parcel.
     */
    public function testDoesNotCreateTwoShipmentsForRecordsSharingATrackingNumber(): void
    {
        $order = $this->order(7, '987');
        $order->method('canShip')->willReturn(true);
        $this->noShipmentsYet($order);

        // Item detail on both, so the scope guard doesn't refuse them before the
        // dedup check is even reached — that guard is a separate concern, covered
        // above.
        $order->method('getAllItems')->willReturn([$this->orderItem(11, 'SKU-A')]);
        $first = $this->fulfilment('SAME-TRACK', 'Demo Couriers', 'collected', 2546);
        $first['items'] = [['sku' => 'SKU-A', 'fulfilled_qty' => 1]];
        $second = $this->fulfilment('SAME-TRACK', 'Demo Couriers', 'collected', 2547);
        $second['items'] = [['sku' => 'SKU-A', 'fulfilled_qty' => 1]];

        $this->apiClient->method('get')->willReturn(['order_fulfillments' => [$first, $second]]);

        $this->shipOrder->expects($this->once())->method('execute')->willReturn(55);

        $this->service->syncOrder($order);
    }
}
