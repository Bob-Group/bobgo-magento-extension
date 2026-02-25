<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Service;

use BobGroup\BobGo\Api\BobGoApiClient;
use BobGroup\BobGo\Api\OrderMapperInterface;
use BobGroup\BobGo\Service\OrderPushService;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OrderPushServiceTest extends TestCase
{
    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $apiClientMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $orderMapperMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $loggerMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $orderRepositoryMock;

    /**
     * @var OrderPushService
     */
    private $service;

    protected function setUp(): void
    {
        $this->apiClientMock = $this->createMock(BobGoApiClient::class);
        $this->orderMapperMock = $this->createMock(OrderMapperInterface::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->orderRepositoryMock = $this->createMock(OrderRepositoryInterface::class);

        $this->service = new OrderPushService(
            $this->apiClientMock,
            $this->orderMapperMock,
            $this->loggerMock,
            $this->orderRepositoryMock
        );
    }

    public function testPushOrderCallsPostAndSavesBobGoId(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(100);
        $order->method('getIncrementId')->willReturn('000000100');

        $payload = ['ChannelRefID' => '100'];
        $this->orderMapperMock->method('mapOrderToPayload')
            ->with($order)
            ->willReturn($payload);

        $this->apiClientMock->expects($this->once())
            ->method('post')
            ->with('orders', $payload)
            ->willReturn(['id' => 'bg-order-abc-123']);

        $order->expects($this->once())
            ->method('setData')
            ->with('bobgo_order_id', 'bg-order-abc-123');

        $this->orderRepositoryMock->expects($this->once())
            ->method('save')
            ->with($order);

        $this->loggerMock->expects($this->once())
            ->method('info');

        $this->service->pushOrder($order);
    }

    public function testPushOrderHandlesApiError(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(100);
        $order->method('getIncrementId')->willReturn('000000100');

        $this->orderMapperMock->method('mapOrderToPayload')
            ->willReturn([]);

        $this->apiClientMock->method('post')
            ->willThrowException(new \Exception('API connection failed'));

        $this->loggerMock->expects($this->once())
            ->method('error');

        // Should not throw
        $this->service->pushOrder($order);
    }

    public function testUpdateOrderCallsPatch(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(100);
        $order->method('getIncrementId')->willReturn('000000100');
        $order->method('getData')
            ->with('bobgo_order_id')
            ->willReturn('bg-order-abc-123');

        $payload = ['id' => 'bg-order-abc-123', 'ChannelRefID' => '100'];
        $this->orderMapperMock->method('mapOrderToUpdatePayload')
            ->with($order)
            ->willReturn($payload);

        $this->apiClientMock->expects($this->once())
            ->method('patch')
            ->with('orders', $payload)
            ->willReturn([]);

        $this->loggerMock->expects($this->once())
            ->method('info');

        $this->service->updateOrder($order);
    }

    public function testUpdateOrderHandlesApiError(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(100);
        $order->method('getIncrementId')->willReturn('000000100');
        $order->method('getData')
            ->with('bobgo_order_id')
            ->willReturn('bg-order-abc-123');

        $this->orderMapperMock->method('mapOrderToUpdatePayload')
            ->willReturn([]);

        $this->apiClientMock->method('patch')
            ->willThrowException(new \Exception('API timeout'));

        $this->loggerMock->expects($this->once())
            ->method('error');

        // Should not throw
        $this->service->updateOrder($order);
    }

    public function testPushOrderSavesItemIds(): void
    {
        $item1 = $this->createMock(OrderItemInterface::class);
        $item1->method('getParentItemId')->willReturn(null);
        $item1->method('getSku')->willReturn('SKU-A');
        $item1->expects($this->once())
            ->method('setData')
            ->with('bobgo_order_item_id', '456');

        $item2 = $this->createMock(OrderItemInterface::class);
        $item2->method('getParentItemId')->willReturn(null);
        $item2->method('getSku')->willReturn('SKU-B');
        $item2->expects($this->once())
            ->method('setData')
            ->with('bobgo_order_item_id', '789');

        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(100);
        $order->method('getIncrementId')->willReturn('000000100');
        $order->method('getItems')->willReturn([$item1, $item2]);

        $this->orderMapperMock->method('mapOrderToPayload')->willReturn([]);

        $this->apiClientMock->method('post')
            ->willReturn([
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
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(100);
        $order->method('getIncrementId')->willReturn('000000100');

        $this->orderMapperMock->method('mapOrderToPayload')->willReturn([]);

        $this->apiClientMock->method('post')
            ->willReturn(['id' => 'bg-order-123']);

        $this->orderRepositoryMock->expects($this->once())->method('save');

        // Should not crash when order_items is missing from response
        $this->service->pushOrder($order);
    }

    public function testPushOrderHandlesDuplicateSkus(): void
    {
        $item1 = $this->createMock(OrderItemInterface::class);
        $item1->method('getParentItemId')->willReturn(null);
        $item1->method('getSku')->willReturn('SAME-SKU');
        $item1->expects($this->once())
            ->method('setData')
            ->with('bobgo_order_item_id', '100');

        $item2 = $this->createMock(OrderItemInterface::class);
        $item2->method('getParentItemId')->willReturn(null);
        $item2->method('getSku')->willReturn('SAME-SKU');
        $item2->expects($this->once())
            ->method('setData')
            ->with('bobgo_order_item_id', '101');

        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(100);
        $order->method('getIncrementId')->willReturn('000000100');
        $order->method('getItems')->willReturn([$item1, $item2]);

        $this->orderMapperMock->method('mapOrderToPayload')->willReturn([]);

        $this->apiClientMock->method('post')
            ->willReturn([
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
        $parentItem->expects($this->once())
            ->method('setData')
            ->with('bobgo_order_item_id', '500');

        $childItem = $this->createMock(OrderItemInterface::class);
        $childItem->method('getParentItemId')->willReturn(1);
        $childItem->expects($this->never())->method('setData');

        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(100);
        $order->method('getIncrementId')->willReturn('000000100');
        $order->method('getItems')->willReturn([$parentItem, $childItem]);

        $this->orderMapperMock->method('mapOrderToPayload')->willReturn([]);

        $this->apiClientMock->method('post')
            ->willReturn([
                'id' => 'bg-order-123',
                'order_items' => [
                    ['id' => 500, 'sku' => 'PARENT-SKU'],
                ],
            ]);

        $this->service->pushOrder($order);
    }
}
