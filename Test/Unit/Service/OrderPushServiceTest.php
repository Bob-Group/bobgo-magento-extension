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
            ->willReturn(['id' => 'bg-order-abc-123']);

        $this->orderRepositoryMock->expects($this->once())->method('save')->with($order);
        $this->syncLoggerMock->expects($this->once())->method('logOutbound');

        $this->service->pushOrder($order);

        $this->assertSame('bg-order-abc-123', $writes['bobgo_order_id']);
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
            'bobgo_order_id'   => 'bg-order-abc-123',
            'bobgo_sync_hash'  => 'stale-hash',
        ], $writes);

        $payload = ['id' => 'bg-order-abc-123', 'channel_ref_id' => '100'];
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
            'bobgo_order_id'  => 'bg-order-abc-123',
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
            'bobgo_order_id' => 'bg-order-abc-123',
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
            'id' => 'bg-order-123',
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
        $this->apiClientMock->method('post')->willReturn(['id' => 'bg-order-123']);

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
            'id' => 'bg-order-123',
            'order_items' => [
                ['id' => 100, 'sku' => 'SAME-SKU'],
                ['id' => 101, 'sku' => 'SAME-SKU'],
            ],
        ]);

        $this->service->pushOrder($order);
    }

    public function testPushOrderSkipsChildItemsForIdMatching(): void
    {
        $parentItem = $this->createMock(OrderItemInterface::class);
        $parentItem->method('getParentItemId')->willReturn(null);
        $parentItem->method('getSku')->willReturn('PARENT-SKU');
        $parentItem->expects($this->once())->method('setData')
            ->with('bobgo_order_item_id', '500');

        $childItem = $this->createMock(OrderItemInterface::class);
        $childItem->method('getParentItemId')->willReturn(1);
        $childItem->expects($this->never())->method('setData');

        $writes = [];
        $order = $this->makeOrder(100, '000000100', [], $writes);
        $order->method('getItems')->willReturn([$parentItem, $childItem]);

        $this->orderMapperMock->method('mapOrderToPayload')->willReturn([]);
        $this->apiClientMock->method('post')->willReturn([
            'id' => 'bg-order-123',
            'order_items' => [
                ['id' => 500, 'sku' => 'PARENT-SKU'],
            ],
        ]);

        $this->service->pushOrder($order);
    }
}
