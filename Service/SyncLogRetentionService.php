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
            // Delete in batches so a busy table doesn't lock for the whole
            // operation. Loop until a batch returns fewer rows than the cap.
            do {
                $rowsDeleted = $connection->delete(
                    $table,
                    [
                        'created_at < ?' => $cutoff,
                        // Keep claim rows the controller is actively using —
                        // they're tiny and self-clean on completion.
                        "event_type <> 'webhook_claim'",
                    ],
                );
                if (!is_int($rowsDeleted)) {
                    break;
                }
                $totalDeleted += $rowsDeleted;
                // Without LIMIT support on the abstraction layer we run a
                // single bulk DELETE; if it succeeded we're done. Loop kept
                // for future LIMIT-aware backends.
            } while (false);
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
