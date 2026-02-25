<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Observer;

use BobGroup\BobGo\Model\Config\ApiConfig;
use BobGroup\BobGo\Observer\OrderSaveObserver;
use BobGroup\BobGo\Service\OrderPushService;
use Magento\Framework\Event\Observer;
use Magento\Sales\Api\Data\OrderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OrderSaveObserverTest extends TestCase
{
    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $orderPushServiceMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $apiConfigMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $loggerMock;

    /**
     * @var OrderSaveObserver
     */
    private $observer;

    protected function setUp(): void
    {
        $this->orderPushServiceMock = $this->createMock(OrderPushService::class);
        $this->apiConfigMock = $this->createMock(ApiConfig::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);

        $this->observer = new OrderSaveObserver(
            $this->orderPushServiceMock,
            $this->apiConfigMock,
            $this->loggerMock
        );
    }

    public function testExecutePushesNewOrder(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getData')
            ->with('bobgo_order_id')
            ->willReturn(null);

        $eventObserver = $this->createObserverWithOrder($order);

        $this->apiConfigMock->method('isOrderPushEnabled')->willReturn(true);
        $this->apiConfigMock->method('isConfigured')->willReturn(true);

        $this->orderPushServiceMock->expects($this->once())
            ->method('pushOrder')
            ->with($order);

        $this->orderPushServiceMock->expects($this->never())
            ->method('updateOrder');

        $this->observer->execute($eventObserver);
    }

    public function testExecuteUpdatesExistingOrder(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getData')
            ->with('bobgo_order_id')
            ->willReturn('bg-order-abc-123');

        $eventObserver = $this->createObserverWithOrder($order);

        $this->apiConfigMock->method('isOrderPushEnabled')->willReturn(true);
        $this->apiConfigMock->method('isConfigured')->willReturn(true);

        $this->orderPushServiceMock->expects($this->never())
            ->method('pushOrder');

        $this->orderPushServiceMock->expects($this->once())
            ->method('updateOrder')
            ->with($order);

        $this->observer->execute($eventObserver);
    }

    public function testExecuteSkipsWhenDisabled(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $eventObserver = $this->createObserverWithOrder($order);

        $this->apiConfigMock->method('isOrderPushEnabled')->willReturn(false);
        $this->apiConfigMock->method('isConfigured')->willReturn(true);

        $this->orderPushServiceMock->expects($this->never())
            ->method('pushOrder');
        $this->orderPushServiceMock->expects($this->never())
            ->method('updateOrder');

        $this->observer->execute($eventObserver);
    }

    public function testExecuteSkipsWhenNotConfigured(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $eventObserver = $this->createObserverWithOrder($order);

        $this->apiConfigMock->method('isOrderPushEnabled')->willReturn(true);
        $this->apiConfigMock->method('isConfigured')->willReturn(false);

        $this->orderPushServiceMock->expects($this->never())
            ->method('pushOrder');
        $this->orderPushServiceMock->expects($this->never())
            ->method('updateOrder');

        $this->observer->execute($eventObserver);
    }

    public function testExecuteSkipsReentrantCall(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getData')
            ->with('bobgo_order_id')
            ->willReturn(null);

        $eventObserver = $this->createObserverWithOrder($order);

        $this->apiConfigMock->method('isOrderPushEnabled')->willReturn(true);
        $this->apiConfigMock->method('isConfigured')->willReturn(true);

        // Simulate pushOrder() saving the order, which re-triggers the observer.
        // The re-entrant call should be skipped entirely.
        $this->orderPushServiceMock->expects($this->once())
            ->method('pushOrder')
            ->with($order)
            ->willReturnCallback(function () use ($eventObserver) {
                // This simulates the nested sales_order_save_after event
                $this->observer->execute($eventObserver);
            });

        $this->orderPushServiceMock->expects($this->never())
            ->method('updateOrder');

        $this->observer->execute($eventObserver);
    }

    public function testExecuteHandlesException(): void
    {
        $eventObserver = $this->createMock(Observer::class);
        $eventMock = $this->getMockBuilder(\Magento\Framework\Event::class)
            ->disableOriginalConstructor()
            ->addMethods(['getOrder'])
            ->getMock();
        $eventMock->method('getOrder')->willThrowException(new \Exception('Unexpected error'));
        $eventObserver->method('getEvent')->willReturn($eventMock);

        $this->loggerMock->expects($this->once())
            ->method('error');

        // Should not throw
        $this->observer->execute($eventObserver);
    }

    /**
     * Helper: create an Observer mock that returns an order from getEvent()->getOrder().
     */
    private function createObserverWithOrder(OrderInterface $order): Observer
    {
        $eventObserver = $this->createMock(Observer::class);
        $eventMock = $this->getMockBuilder(\Magento\Framework\Event::class)
            ->disableOriginalConstructor()
            ->addMethods(['getOrder'])
            ->getMock();
        $eventMock->method('getOrder')->willReturn($order);
        $eventObserver->method('getEvent')->willReturn($eventMock);

        return $eventObserver;
    }
}
