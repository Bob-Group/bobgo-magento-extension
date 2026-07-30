<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Observer;

use BobGroup\BobGo\Model\Config\ApiConfig;
use BobGroup\BobGo\Observer\OrderSaveObserver;
use BobGroup\BobGo\Service\OrderSyncPolicy;
use BobGroup\BobGo\Service\OrderSyncQueue;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The observer's whole job is now: decide cheaply, then write one queue row.
 *
 * It must not talk to Bob Go. `sales_order_save_after` fires on checkout, on
 * every admin order save, on invoice and shipment creation, and from our own
 * webhook handlers — doing the HTTP call here meant the customer placing the
 * order paid for a slow or unreachable API.
 */
class OrderSaveObserverTest extends TestCase
{
    private $queue;
    private $apiConfig;
    private $logger;
    /** @var OrderSaveObserver */
    private $observer;

    protected function setUp(): void
    {
        $this->queue = $this->createMock(OrderSyncQueue::class);
        $this->apiConfig = $this->createMock(ApiConfig::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->observer = new OrderSaveObserver(
            $this->queue,
            new OrderSyncPolicy(),
            $this->apiConfig,
            $this->logger
        );
    }

    public function testQueuesAnEligibleOrder(): void
    {
        $this->enable();

        $this->queue->expects($this->once())->method('enqueue')->with(42);

        $this->observer->execute($this->eventFor($this->order(Order::STATE_PROCESSING)));
    }

    public function testDoesNotQueueWhenOrderPushIsDisabled(): void
    {
        $this->apiConfig->method('isOrderPushEnabled')->willReturn(false);

        $this->queue->expects($this->never())->method('enqueue');

        $this->observer->execute($this->eventFor($this->order(Order::STATE_PROCESSING)));
    }

    public function testDoesNotQueueWhenNotConfigured(): void
    {
        $this->apiConfig->method('isOrderPushEnabled')->willReturn(true);
        $this->apiConfig->method('isConfigured')->willReturn(false);

        $this->queue->expects($this->never())->method('enqueue');

        $this->observer->execute($this->eventFor($this->order(Order::STATE_PROCESSING)));
    }

    /**
     * A virtual order has no shipping address, so it would fail on every single
     * save. Filtering here keeps the queue clean; the job re-checks too.
     */
    public function testDoesNotQueueAVirtualOrder(): void
    {
        $this->enable();

        $this->queue->expects($this->never())->method('enqueue');

        $this->observer->execute($this->eventFor($this->order(Order::STATE_PROCESSING, [], true)));
    }

    public function testDoesNotQueueAnUnlinkedOrderAwaitingPayment(): void
    {
        $this->enable();

        $this->queue->expects($this->never())->method('enqueue');

        $this->observer->execute($this->eventFor($this->order('pending_payment')));
    }

    public function testHandlesAnEventWithoutAnOrder(): void
    {
        $event = $this->getMockBuilder(Event::class)->addMethods(['getOrder'])->getMock();
        $event->method('getOrder')->willReturn(null);
        $observerArg = new Observer();
        $observerArg->setEvent($event);

        $this->logger->expects($this->once())->method('warning');
        $this->queue->expects($this->never())->method('enqueue');

        $this->observer->execute($observerArg);
    }

    /**
     * Order saving is never blocked by Bob Go.
     */
    public function testSwallowsUnexpectedFailures(): void
    {
        $this->enable();
        $this->queue->method('enqueue')->willThrowException(new \RuntimeException('boom'));

        $this->logger->expects($this->once())->method('error');

        $this->observer->execute($this->eventFor($this->order(Order::STATE_PROCESSING)));
    }

    // ----------------------------------------------------------------------- helpers

    private function enable(): void
    {
        $this->apiConfig->method('isOrderPushEnabled')->willReturn(true);
        $this->apiConfig->method('isConfigured')->willReturn(true);
    }

    /**
     * @param \PHPUnit\Framework\MockObject\MockObject $order
     */
    private function eventFor($order): Observer
    {
        $event = $this->getMockBuilder(Event::class)->addMethods(['getOrder'])->getMock();
        $event->method('getOrder')->willReturn($order);
        $observer = new Observer();
        $observer->setEvent($event);
        return $observer;
    }

    /**
     * @param array<string,mixed> $data
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    private function order(string $state, array $data = [], bool $isVirtual = false)
    {
        $order = $this->createMock(Order::class);
        $order->method('getEntityId')->willReturn(42);
        $order->method('getState')->willReturn($state);
        $order->method('getIsVirtual')->willReturn($isVirtual);
        $order->method('getData')->willReturnCallback(static function ($key = null) use ($data) {
            return $key === null ? $data : ($data[$key] ?? null);
        });
        return $order;
    }
}
