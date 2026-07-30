<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Service;

use BobGroup\BobGo\Model\ResourceModel\SyncLog as SyncLogResource;
use Psr\Log\LoggerInterface;

/**
 * Prunes old rows from `bobgo_sync_log`.
 *
 * The sync log captures every inbound webhook and outbound API call. On a
 * busy store this grows quickly; rows older than RETENTION_DAYS aren't
 * useful for live debugging (and aren't a system of record — the order
 * itself is). A daily cron tail-cuts the table to keep size bounded.
 *
 * Retention is intentionally aggressive (30 days) because:
 *   - Recent rows are what operators reach for when triaging.
 *   - The order row still carries `bobgo_last_synced` / `bobgo_last_webhook`
 *     for long-term audit.
 *   - Anything older has either been resolved or won't be re-triaged from
 *     the sync log alone.
 */
class SyncLogRetentionService
{
    public const RETENTION_DAYS = 30;
    private const BATCH_SIZE = 5000;

    private SyncLogResource $syncLogResource;
    private LoggerInterface $logger;

    public function __construct(
        SyncLogResource $syncLogResource,
        LoggerInterface $logger
    ) {
        $this->syncLogResource = $syncLogResource;
        $this->logger = $logger;
    }

    public function prune(): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - (self::RETENTION_DAYS * 86400));
        $totalDeleted = 0;

        try {
            $connection = $this->syncLogResource->getConnection();
            $table = $this->syncLogResource->getMainTable();

            // Genuinely batched: select a page of ids, delete by id, repeat until
            // a page comes back short. A single unbounded DELETE holds a lock for
            // as long as it takes, and on a busy store this table is the busiest
            // thing the extension owns.
            do {
                $ids = $connection->fetchCol(
                    $connection->select()
                        ->from($table, ['entity_id'])
                        ->where('created_at < ?', $cutoff)
                        // Keep claim rows the controller is actively using —
                        // they're tiny and self-clean on completion.
                        ->where("event_type <> 'webhook_claim'")
                        ->limit(self::BATCH_SIZE)
                );

                if (empty($ids)) {
                    break;
                }

                $totalDeleted += (int) $connection->delete($table, ['entity_id IN (?)' => $ids]);
            } while (count($ids) === self::BATCH_SIZE);
        } catch (\Throwable $e) {
            $this->logger->error('Bob Go: sync log prune failed', [
                'error' => $e->getMessage(),
            ]);
            return 0;
        }

        if ($totalDeleted > 0) {
            $this->logger->info('Bob Go: pruned old sync log rows', [
                'cutoff' => $cutoff,
                'deleted' => $totalDeleted,
            ]);
        }
        return $totalDeleted;
    }
}
