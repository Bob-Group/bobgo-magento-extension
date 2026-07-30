<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Service;

use BobGroup\BobGo\Service\OrderSyncQueue;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The outbox between the save observer and the push cron.
 *
 * Two properties matter: enqueueing can never break the save it runs inside, and
 * a failing order backs off instead of being retried every minute forever.
 */
class OrderSyncQueueTest extends TestCase
{
    private $resource;
    private $connection;
    private $logger;
    /** @var OrderSyncQueue */
    private $queue;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(ResourceConnection::class);
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->resource->method('getConnection')->willReturn($this->connection);
        $this->resource->method('getTableName')->willReturnArgument(0);

        $this->queue = new OrderSyncQueue($this->resource, $this->logger);
    }

    public function testEnqueueInsertsOnDuplicate(): void
    {
        $this->connection->expects($this->once())
            ->method('insertOnDuplicate')
            ->with(
                OrderSyncQueue::TABLE,
                ['order_id' => 42, 'attempts' => 0, 'next_attempt_at' => null],
                // Re-queueing an order that was already waiting clears its
                // backoff: the payload just changed again, so try promptly.
                ['attempts' => 0, 'next_attempt_at' => null]
            );

        $this->queue->enqueue(42);
    }

    public function testEnqueueIgnoresAnInvalidOrderId(): void
    {
        $this->connection->expects($this->never())->method('insertOnDuplicate');

        $this->queue->enqueue(0);
    }

    /**
     * This runs inside the order's own save. A queueing problem must never be
     * able to break checkout or an admin save.
     */
    public function testEnqueueSwallowsDatabaseErrors(): void
    {
        $this->connection->method('insertOnDuplicate')
            ->willThrowException(new \RuntimeException('table is gone'));
        $this->logger->expects($this->once())->method('error');

        $this->queue->enqueue(42);
    }

    public function testReleaseDeletesTheRow(): void
    {
        $this->connection->expects($this->once())
            ->method('delete')
            ->with(OrderSyncQueue::TABLE, ['order_id = ?' => 42]);

        $this->queue->release(42);
    }

    public function testDeferBacksOffAndCountsTheAttempt(): void
    {
        $this->connection->method('select')->willReturn($this->select());
        $this->connection->method('fetchOne')->willReturn('0');

        $update = null;
        $this->connection->expects($this->once())
            ->method('update')
            ->willReturnCallback(function ($table, $bind, $where) use (&$update) {
                $update = $bind;
                return 1;
            });

        $this->queue->defer(42);

        $this->assertSame(1, $update['attempts']);
        $this->assertNotNull($update['next_attempt_at']);
        $this->assertGreaterThan(gmdate('Y-m-d H:i:s'), $update['next_attempt_at']);
    }

    /**
     * A permanently-rejected order (bad address, unmappable item) would otherwise
     * be retried every minute until someone noticed. The failure is still on the
     * order and in the sync log, and Resync re-queues it.
     */
    public function testDeferGivesUpAfterMaxAttempts(): void
    {
        $this->connection->method('select')->willReturn($this->select());
        $this->connection->method('fetchOne')->willReturn((string) (OrderSyncQueue::MAX_ATTEMPTS - 1));

        $this->logger->expects($this->once())->method('error');
        $this->connection->expects($this->never())->method('update');
        $this->connection->expects($this->once())->method('delete');

        $this->queue->defer(42);
    }

    public function testClaimReturnsDueOrderIds(): void
    {
        $this->connection->method('select')->willReturn($this->select());
        $this->connection->method('fetchCol')->willReturn(['7', '42']);

        $this->assertSame([7, 42], $this->queue->claim(50));
    }

    public function testClaimReturnsNothingWhenTheQueryFails(): void
    {
        $this->connection->method('select')->willThrowException(new \RuntimeException('down'));
        $this->logger->expects($this->once())->method('error');

        $this->assertSame([], $this->queue->claim(50));
    }

    /**
     * A fluent Zend_Db_Select stand-in.
     *
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    private function select()
    {
        $select = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['from', 'where', 'order', 'limit'])
            ->getMock();
        foreach (['from', 'where', 'order', 'limit'] as $method) {
            $select->method($method)->willReturnSelf();
        }
        return $select;
    }
}
