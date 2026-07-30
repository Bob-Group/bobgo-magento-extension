<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Service;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Outbox of orders waiting to be pushed to Bob Go.
 *
 * WHY A TABLE AND NOT THE MESSAGE QUEUE
 *
 * Magento's message queue would be the idiomatic choice, but it needs consumer
 * processes running (or cron_consumers_runner left enabled) and a stuck or
 * disabled consumer means order push silently stops with nothing to look at.
 * Cron is already a hard requirement for Magento, and this table is inspectable
 * with one SELECT, so a merchant or support engineer can see exactly what is
 * pending and why. If throughput ever justifies it, swapping the drain loop for
 * a consumer is a contained change — the enqueue side wouldn't move.
 *
 * WHY NOT A FLAG ON sales_order
 *
 * The producer is an observer on sales_order_save_after, i.e. it runs inside the
 * order's own save. Setting a column there means saving the order again from
 * within its afterSave and re-entering every other module's observers with it.
 * A single INSERT into a table nobody else watches avoids that entirely.
 *
 * Enqueueing is idempotent (UNIQUE on order_id), so an order saved several times
 * in one request is pushed once.
 */
class OrderSyncQueue
{
    public const TABLE = 'bobgo_order_sync_queue';

    /**
     * Give up after this many failures. A permanently-rejected order (bad
     * address, unmappable item) would otherwise be retried every minute until
     * someone notices. The failure is on the order as bobgo_sync_status and in
     * bobgo_sync_log either way; the admin Resync button re-queues it.
     */
    public const MAX_ATTEMPTS = 10;

    /** Backoff schedule in seconds, indexed by attempt count. */
    private const BACKOFF_SECONDS = [60, 300, 900, 3600];

    private ResourceConnection $resource;
    private LoggerInterface $logger;

    public function __construct(ResourceConnection $resource, LoggerInterface $logger)
    {
        $this->resource = $resource;
        $this->logger = $logger;
    }

    /**
     * Queue an order for pushing. Safe to call on every save.
     *
     * Failures are swallowed: this runs inside the order's save, and a queueing
     * problem must never be able to break checkout or an admin save.
     */
    public function enqueue(int $orderId): void
    {
        if ($orderId <= 0) {
            return;
        }

        try {
            $connection = $this->resource->getConnection();
            $connection->insertOnDuplicate(
                $this->table(),
                ['order_id' => $orderId, 'attempts' => 0, 'next_attempt_at' => null],
                // Re-queue an order that was already waiting: the payload just
                // changed again, so clear any backoff and try promptly.
                ['attempts' => 0, 'next_attempt_at' => null]
            );
        } catch (\Throwable $e) {
            $this->logger->error('Bob Go: failed to queue an order for push', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Order ids that are due to be pushed, oldest first.
     *
     * @return int[]
     */
    public function claim(int $limit): array
    {
        try {
            $connection = $this->resource->getConnection();
            $select = $connection->select()
                ->from($this->table(), ['order_id'])
                ->where('next_attempt_at IS NULL OR next_attempt_at <= ?', $this->now())
                ->order('entity_id ASC')
                ->limit($limit);

            return array_map('intval', $connection->fetchCol($select));
        } catch (\Throwable $e) {
            $this->logger->error('Bob Go: failed to read the order push queue', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Done with this order — drop it from the queue.
     */
    public function release(int $orderId): void
    {
        try {
            $this->resource->getConnection()->delete($this->table(), ['order_id = ?' => $orderId]);
        } catch (\Throwable $e) {
            $this->logger->error('Bob Go: failed to dequeue an order', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The push failed. Back off, or give up after MAX_ATTEMPTS.
     */
    public function defer(int $orderId): void
    {
        try {
            $connection = $this->resource->getConnection();
            $attempts = (int) $connection->fetchOne(
                $connection->select()->from($this->table(), ['attempts'])->where('order_id = ?', $orderId)
            ) + 1;

            if ($attempts >= self::MAX_ATTEMPTS) {
                $this->logger->error('Bob Go: giving up on an order push after repeated failures', [
                    'order_id' => $orderId,
                    'attempts' => $attempts,
                ]);
                $this->release($orderId);
                return;
            }

            $connection->update(
                $this->table(),
                [
                    'attempts' => $attempts,
                    'next_attempt_at' => $this->now($this->backoffFor($attempts)),
                ],
                ['order_id = ?' => $orderId]
            );
        } catch (\Throwable $e) {
            $this->logger->error('Bob Go: failed to defer an order push', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function backoffFor(int $attempts): int
    {
        $index = min($attempts - 1, count(self::BACKOFF_SECONDS) - 1);
        return self::BACKOFF_SECONDS[max(0, $index)];
    }

    private function now(int $plusSeconds = 0): string
    {
        return gmdate('Y-m-d H:i:s', time() + $plusSeconds);
    }

    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }
}
