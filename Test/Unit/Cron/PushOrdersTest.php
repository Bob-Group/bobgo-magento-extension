<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Cron;

use BobGroup\BobGo\Cron\PushOrders;
use BobGroup\BobGo\Model\Config\ApiConfig;
use BobGroup\BobGo\Service\OrderPushService;
use BobGroup\BobGo\Service\OrderSyncPolicy;
use BobGroup\BobGo\Service\OrderSyncQueue;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The job that actually talks to Bob Go, now that the observer only queues.
 *
 * The behaviour worth pinning down is the retry contract: a failed push must stay
 * queued (with a backoff) rather than being dropped, and a succeeded one must
 * leave the queue so it isn't pushed again every minute forever.
 */
class PushOrdersTest extends TestCase
{
    private $queue;
    private $orderRepository;
    private $orderPushService;
    private $apiConfig;
    private $logger;
    /** @var PushOrders */
    private $cron;

    protected function setUp(): void
    {
        $this->queue = $this->createMock(OrderSyncQueue::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->orderPushService = $this->createMock(OrderPushService::class);
        $this->apiConfig = $this->createMock(ApiConfig::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->cron = new PushOrders(
            $this->queue,
            $this->orderRepository,
            $this->orderPushService,
            new OrderSyncPolicy(),
            $this->apiConfig,
            $this->logger
        );
    }

    public function testDoesNothingWhenOrderPushIsDisabled(): void
    {
        $this->apiConfig->method('isOrderPushEnabled')->willReturn(false);
        $this->queue->expects($this->never())->method('claim');

        $this->cron->execute();
    }

    public function testDoesNothingWithoutAnApiKey(): void
    {
        $this->apiConfig->method('isOrderPushEnabled')->willReturn(true);
        $this->apiConfig->method('isConfigured')->willReturn(false);
        $this->queue->expects($this->never())->method('claim');

        $this->cron->execute();
    }

    public function testPostsAnUnlinkedOrderAndDequeuesIt(): void
    {
        $this->enable();
        $this->queue->method('claim')->willReturn([42]);
        $this->orderRepository->method('get')->with(42)->willReturn($this->order(Order::STATE_PROCESSING));

        $this->orderPushService->expects($this->once())->method('pushOrder')->willReturn(true);
        $this->orderPushService->expects($this->never())->method('updateOrder');
        $this->queue->expects($this->once())->method('release')->with(42);
        $this->queue->expects($this->never())->method('defer');

        $this->cron->execute();
    }

    public function testPatchesALinkedOrder(): void
    {
        $this->enable();
        $this->queue->method('claim')->willReturn([42]);
        $this->orderRepository->method('get')
            ->willReturn($this->order(Order::STATE_PROCESSING, ['bobgo_order_id' => '987']));

        $this->orderPushService->expects($this->once())->method('updateOrder')->willReturn(true);
        $this->orderPushService->expects($this->never())->method('pushOrder');
        $this->queue->expects($this->once())->method('release');

        $this->cron->execute();
    }

    /**
     * A failure must not silently disappear from the queue — that would leave the
     * order permanently out of sync with nothing re-driving it.
     */
    public function testDefersOnPushFailure(): void
    {
        $this->enable();
        $this->queue->method('claim')->willReturn([42]);
        $this->orderRepository->method('get')->willReturn($this->order(Order::STATE_PROCESSING));

        $this->orderPushService->method('pushOrder')->willReturn(false);
        $this->queue->expects($this->once())->method('defer')->with(42);
        $this->queue->expects($this->never())->method('release');

        $this->cron->execute();
    }

    public function testDefersWhenThePushThrows(): void
    {
        $this->enable();
        $this->queue->method('claim')->willReturn([42]);
        $this->orderRepository->method('get')->willReturn($this->order(Order::STATE_PROCESSING));

        $this->orderPushService->method('pushOrder')
            ->willThrowException(new \RuntimeException('unexpected'));

        $this->logger->expects($this->once())->method('error');
        $this->queue->expects($this->once())->method('defer')->with(42);

        $this->cron->execute();
    }

    /**
     * The policy is re-checked inside the job, not just at enqueue time: this
     * runs after the save that queued it, and the order may have moved on.
     */
    public function testDropsAnOrderThatNoLongerQualifies(): void
    {
        $this->enable();
        $this->queue->method('claim')->willReturn([42]);
        // Queued while processing, cancelled before the job ran, never linked —
        // so there is nothing to create on Bob Go.
        $this->orderRepository->method('get')->willReturn($this->order(Order::STATE_CANCELED));

        $this->orderPushService->expects($this->never())->method('pushOrder');
        $this->orderPushService->expects($this->never())->method('updateOrder');
        $this->queue->expects($this->once())->method('release')->with(42);

        $this->cron->execute();
    }

    public function testDropsAnOrderThatNoLongerExists(): void
    {
        $this->enable();
        $this->queue->method('claim')->willReturn([42]);
        $this->orderRepository->method('get')
            ->willThrowException(new \Magento\Framework\Exception\NoSuchEntityException(__('gone')));

        $this->queue->expects($this->once())->method('release')->with(42);
        $this->queue->expects($this->never())->method('defer');

        $this->cron->execute();
    }

    // ------------------------------------------------------------ status forwarding

    public function testForwardsATerminalStatusAfterSyncingTheOrder(): void
    {
        $this->enable();
        $this->queue->method('claim')->willReturn([42]);
        $order = $this->order(Order::STATE_CANCELED, ['bobgo_order_id' => '987']);
        $this->orderRepository->method('get')->willReturn($order);

        $this->orderPushService->method('updateOrder')->willReturn(true);
        $this->orderPushService->expects($this->once())
            ->method('pushStatus')->with($order, 'cancelled')->willReturn(true);
        $this->queue->expects($this->once())->method('release');

        $this->cron->execute();
    }

    public function testStaysQueuedWhenStatusForwardingFails(): void
    {
        $this->enable();
        $this->queue->method('claim')->willReturn([42]);
        $this->orderRepository->method('get')
            ->willReturn($this->order(Order::STATE_COMPLETE, ['bobgo_order_id' => '987']));

        $this->orderPushService->method('updateOrder')->willReturn(true);
        $this->orderPushService->method('pushStatus')->willReturn(false);

        $this->queue->expects($this->once())->method('defer');
        $this->queue->expects($this->never())->method('release');

        $this->cron->execute();
    }

    public function testDoesNotForwardAStatusForAnIntermediateState(): void
    {
        $this->enable();
        $this->queue->method('claim')->willReturn([42]);
        $this->orderRepository->method('get')
            ->willReturn($this->order(Order::STATE_PROCESSING, ['bobgo_order_id' => '987']));

        $this->orderPushService->method('updateOrder')->willReturn(true);
        $this->orderPushService->expects($this->never())->method('pushStatus');

        $this->cron->execute();
    }

    // ----------------------------------------------------------------------- helpers

    private function enable(): void
    {
        $this->apiConfig->method('isOrderPushEnabled')->willReturn(true);
        $this->apiConfig->method('isConfigured')->willReturn(true);
    }

    /**
     * @param array<string,mixed> $data
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    private function order(string $state, array $data = [])
    {
        $order = $this->createMock(Order::class);
        $order->method('getEntityId')->willReturn(42);
        $order->method('getState')->willReturn($state);
        $order->method('getIsVirtual')->willReturn(false);
        $order->method('getData')->willReturnCallback(static function ($key = null) use ($data) {
            return $key === null ? $data : ($data[$key] ?? null);
        });
        return $order;
    }
}
