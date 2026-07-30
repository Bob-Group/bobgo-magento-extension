<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Service;

use BobGroup\BobGo\Api\BobGoApiClient;
use BobGroup\BobGo\Api\OrderMapperInterface;
use BobGroup\BobGo\Service\OrderPushService;
use BobGroup\BobGo\Service\SyncLogger;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OrderPushServiceTest extends TestCase
{
    private $apiClientMock;
    private $orderMapperMock;
    private $loggerMock;
    private $orderRepositoryMock;
    private $syncLoggerMock;
    private $dateTimeMock;
    /** @var OrderPushService */
    private $service;

    protected function setUp(): void
    {
        $this->apiClientMock = $this->createMock(BobGoApiClient::class);
        $this->orderMapperMock = $this->createMock(OrderMapperInterface::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->orderRepositoryMock = $this->createMock(OrderRepositoryInterface::class);
        $this->syncLoggerMock = $this->createMock(SyncLogger::class);
        $this->dateTimeMock = $this->createMock(DateTime::class);
        $this->dateTimeMock->method('gmtDate')->willReturn('2026-05-19 12:00:00');

        $this->service = new OrderPushService(
            $this->apiClientMock,
            $this->orderMapperMock,
            $this->loggerMock,
            $this->orderRepositoryMock,
            $this->syncLoggerMock,
            $this->dateTimeMock
        );
    }

    /**
     * Build a minimal order mock — `setData` is collected into the supplied
     * by-ref array, `getData` returns whatever is in $initialData.
     */
    private function makeOrder(int $entityId, string $incrementId, array $initialData = [], array &$writes = []): OrderInterface
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn($entityId);
        $order->method('getIncrementId')->willReturn($incrementId);

        $store = array_merge($initialData, []);
        $order->method('getData')->willReturnCallback(function ($key = null) use (&$store) {
            if ($key === null) {
                return $store;
            }
            return $store[$key] ?? null;
        });
        $order->method('setData')->willReturnCallback(function ($key, $value = null) use (&$store, &$writes) {
            $store[$key] = $value;
            $writes[$key] = $value;
            return null;
        });
        return $order;
    }

    public function testPushOrderCallsPostAndSavesBobGoId(): void
    {
        $writes = [];
        $order = $this->makeOrder(100, '000000100', [], $writes);

        $payload = ['channel_ref_id' => '100'];
        $this->orderMapperMock->method('mapOrderToPayload')->willReturn($payload);
        $this->apiClientMock->expects($this->once())
            ->method('post')
            ->with('orders', $payload)
            // The API returns the id as a JSON number; we normalise to string
            // because that's what the varchar column holds.
            ->willReturn(['id' => 987]);

        $this->orderRepositoryMock->expects($this->once())->method('save')->with($order);
        $this->syncLoggerMock->expects($this->once())->method('logOutbound');

        $this->assertTrue($this->service->pushOrder($order));

        $this->assertSame('987', $writes['bobgo_order_id']);
        $this->assertSame('success', $writes['bobgo_sync_status']);
        $this->assertNotEmpty($writes['bobgo_sync_hash']);
    }

    public function testPushOrderHandlesApiError(): void
    {
        $writes = [];
        $order = $this->makeOrder(100, '000000100', [], $writes);

        $this->orderMapperMock->method('mapOrderToPayload')->willReturn([]);
        $this->apiClientMock->method('post')
            ->willThrowException(new \Exception('API connection failed'));

        $this->loggerMock->expects($this->once())->method('error');
        $this->syncLoggerMock->expects($this->once())->method('logOutbound')
            ->with($this->anything(), $this->anything(), $this->anything(), $this->anything(), false);

        $this->service->pushOrder($order);

        $this->assertSame('failed', $writes['bobgo_sync_status']);
    }

    public function testUpdateOrderCallsPatch(): void
    {
        $writes = [];
        $order = $this->makeOrder(100, '000000100', [
            'bobgo_order_id'   => '987',
            'bobgo_sync_hash'  => 'stale-hash',
        ], $writes);

        $payload = ['id' => '987', 'channel_ref_id' => '100'];
        $this->orderMapperMock->method('mapOrderToUpdatePayload')->willReturn($payload);

        $this->apiClientMock->expects($this->once())->method('patch')
            ->with('orders', $payload)->willReturn([]);
        $this->syncLoggerMock->expects($this->once())->method('logOutbound');

        $this->service->updateOrder($order);

        $this->assertSame('success', $writes['bobgo_sync_status']);
    }

    public function testUpdateOrderSkipsWhenHashMatches(): void
    {
        $payload = ['channel_ref_id' => '100'];
        $expectedHash = md5((string) json_encode($payload));

        $writes = [];
        $order = $this->makeOrder(100, '000000100', [
            'bobgo_order_id'  => '987',
            'bobgo_sync_hash' => $expectedHash,
        ], $writes);

        $this->orderMapperMock->method('mapOrderToUpdatePayload')->willReturn($payload);

        $this->apiClientMock->expects($this->never())->method('patch');
        $this->syncLoggerMock->expects($this->never())->method('logOutbound');

        $this->service->updateOrder($order);
    }

    public function testUpdateOrderHandlesApiError(): void
    {
        $writes = [];
        $order = $this->makeOrder(100, '000000100', [
            'bobgo_order_id' => '987',
        ], $writes);

        $this->orderMapperMock->method('mapOrderToUpdatePayload')->willReturn([]);
        $this->apiClientMock->method('patch')
            ->willThrowException(new \Exception('API timeout'));

        $this->loggerMock->expects($this->once())->method('error');

        $this->service->updateOrder($order);

        $this->assertSame('failed', $writes['bobgo_sync_status']);
    }

    public function testPushOrderSavesItemIds(): void
    {
        $item1 = $this->createMock(OrderItemInterface::class);
        $item1->method('getParentItemId')->willReturn(null);
        $item1->method('getSku')->willReturn('SKU-A');
        $item1->expects($this->once())->method('setData')
            ->with('bobgo_order_item_id', '456');

        $item2 = $this->createMock(OrderItemInterface::class);
        $item2->method('getParentItemId')->willReturn(null);
        $item2->method('getSku')->willReturn('SKU-B');
        $item2->expects($this->once())->method('setData')
            ->with('bobgo_order_item_id', '789');

        $writes = [];
        $order = $this->makeOrder(100, '000000100', [], $writes);
        $order->method('getItems')->willReturn([$item1, $item2]);

        $this->orderMapperMock->method('mapOrderToPayload')->willReturn([]);
        $this->apiClientMock->method('post')->willReturn([
            'id' => '987',
            'order_items' => [
                ['id' => 456, 'sku' => 'SKU-A'],
                ['id' => 789, 'sku' => 'SKU-B'],
            ],
        ]);

        $this->service->pushOrder($order);
    }

    public function testPushOrderHandlesEmptyOrderItemsInResponse(): void
    {
        $writes = [];
        $order = $this->makeOrder(100, '000000100', [], $writes);
        $order->method('getItems')->willReturn([]);

        $this->orderMapperMock->method('mapOrderToPayload')->willReturn([]);
        $this->apiClientMock->method('post')->willReturn(['id' => '987']);

        $this->orderRepositoryMock->expects($this->once())->method('save');
        $this->service->pushOrder($order);
    }

    public function testPushOrderHandlesDuplicateSkus(): void
    {
        $item1 = $this->createMock(OrderItemInterface::class);
        $item1->method('getParentItemId')->willReturn(null);
        $item1->method('getSku')->willReturn('SAME-SKU');
        $item1->expects($this->once())->method('setData')
            ->with('bobgo_order_item_id', '100');

        $item2 = $this->createMock(OrderItemInterface::class);
        $item2->method('getParentItemId')->willReturn(null);
        $item2->method('getSku')->willReturn('SAME-SKU');
        $item2->expects($this->once())->method('setData')
            ->with('bobgo_order_item_id', '101');

        $writes = [];
        $order = $this->makeOrder(100, '000000100', [], $writes);
        $order->method('getItems')->willReturn([$item1, $item2]);

        $this->orderMapperMock->method('mapOrderToPayload')->willReturn([]);
        $this->apiClientMock->method('post')->willReturn([
            'id' => '987',
            'order_items' => [
                ['id' => 100, 'sku' => 'SAME-SKU'],
                ['id' => 101, 'sku' => 'SAME-SKU'],
            ],
        ]);

        $this->service->pushOrder($order);
    }

    /**
     * A 2xx with no order id in the body must NOT be recorded as a success.
     *
     * Marking it synced orphans the order: reconciliation only looks at orders
     * that have a bobgo_order_id, fulfilment webhooks can't resolve to it, and
     * the sync-hash dirty check suppresses every future PATCH. It would sit
     * there invisible and never retried.
     */
    public function testPushOrderTreatsResponseWithoutOrderIdAsFailure(): void
    {
        $writes = [];
        $order = $this->makeOrder(100, '000000100', [], $writes);

        $this->orderMapperMock->method('mapOrderToPayload')->willReturn(['channel_ref_id' => '100']);
        $this->apiClientMock->method('post')->willReturn(['message' => 'accepted']);

        $this->loggerMock->expects($this->once())->method('error');
        $this->syncLoggerMock->expects($this->once())->method('logOutbound')
            ->with($this->anything(), $this->anything(), $this->anything(), 200, false);

        $this->assertFalse($this->service->pushOrder($order));

        $this->assertSame('failed', $writes['bobgo_sync_status']);
        // Crucially: no hash written, so the next save retries instead of
        // short-circuiting on a dirty check that thinks we're in sync.
        $this->assertArrayNotHasKey('bobgo_sync_hash', $writes);
        $this->assertArrayNotHasKey('bobgo_order_id', $writes);
    }

    /**
     * Bob Go order ids are numeric (mapOrderToUpdatePayload casts to int, and
     * the API's own by-id lookup is numeric). A zero or non-numeric id would
     * PATCH as `id: 0` forever, so refuse it loudly instead.
     *
     * @dataProvider unusableOrderIdProvider
     * @param mixed $unusableId
     */
    public function testPushOrderRejectsUnusableOrderId($unusableId): void
    {
        $writes = [];
        $order = $this->makeOrder(100, '000000100', [], $writes);

        $this->orderMapperMock->method('mapOrderToPayload')->willReturn([]);
        $this->apiClientMock->method('post')->willReturn(['id' => $unusableId]);

        $this->assertFalse($this->service->pushOrder($order));
        $this->assertSame('failed', $writes['bobgo_sync_status']);
    }

    /**
     * @return array<string,array{0:mixed}>
     */
    public function unusableOrderIdProvider(): array
    {
        return [
            'zero int' => [0],
            'zero string' => ['0'],
            'negative' => [-5],
            'empty string' => [''],
            'null' => [null],
            'non-numeric' => ['bg-order-abc'],
        ];
    }

    /**
     * An update whose order somehow has no stored link and whose PATCH response
     * carries no id is the same orphan case — fail, don't claim success.
     */
    public function testUpdateOrderWithoutAnyOrderIdIsAFailure(): void
    {
        $writes = [];
        $order = $this->makeOrder(100, '000000100', [], $writes);

        $this->orderMapperMock->method('mapOrderToUpdatePayload')->willReturn(['channel_ref_id' => '100']);
        $this->apiClientMock->method('patch')->willReturn([]);

        $this->assertFalse($this->service->updateOrder($order));
        $this->assertSame('failed', $writes['bobgo_sync_status']);
        $this->assertArrayNotHasKey('bobgo_sync_hash', $writes);
    }

    public function testPushOrderSavesIdOnSimpleChildNotConfigurableParent(): void
    {
        // Configurable parent — must NOT receive the bobgo id; we map the simple
        // child so the id needs to live there for PATCH to round-trip cleanly.
        $parentItem = $this->createMock(OrderItemInterface::class);
        $parentItem->method('getProductType')->willReturn('configurable');
        $parentItem->method('getSku')->willReturn('WS12-M-Orange');
        $parentItem->expects($this->never())->method('setData');

        $childItem = $this->createMock(OrderItemInterface::class);
        $childItem->method('getProductType')->willReturn('simple');
        $childItem->method('getSku')->willReturn('WS12-M-Orange');
        $childItem->expects($this->once())->method('setData')
            ->with('bobgo_order_item_id', '500');

        $writes = [];
        $order = $this->makeOrder(100, '000000100', [], $writes);
        $order->method('getItems')->willReturn([$parentItem, $childItem]);

        $this->orderMapperMock->method('mapOrderToPayload')->willReturn([]);
        $this->apiClientMock->method('post')->willReturn([
            'id' => '987',
            'order_items' => [
                ['id' => 500, 'sku' => 'WS12-M-Orange'],
            ],
        ]);

        $this->service->pushOrder($order);
    }
}
