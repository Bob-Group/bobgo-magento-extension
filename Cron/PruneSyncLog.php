<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Cron;

use BobGroup\BobGo\Service\SyncLogRetentionService;
use Psr\Log\LoggerInterface;

/**
 * Daily cron entry point — prunes old rows from `bobgo_sync_log` so the
 * table doesn't grow without bound. Thin wrapper over the retention
 * service so it can be invoked from a one-off admin tool later.
 */
class PruneSyncLog
{
    private SyncLogRetentionService $retention;
    private LoggerInterface $logger;

    public function __construct(
        SyncLogRetentionService $retention,
        LoggerInterface $logger
    ) {
        $this->retention = $retention;
        $this->logger = $logger;
    }

    public function execute(): void
    {
        try {
            $this->retention->prune();
        } catch (\Throwable $e) {
            $this->logger->error('Bob Go: sync log prune cron failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
