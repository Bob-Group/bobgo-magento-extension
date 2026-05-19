<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Cron;

use BobGroup\BobGo\Service\ReconciliationService;
use Psr\Log\LoggerInterface;

/**
 * Hourly cron entry point for fulfilment reconciliation. Thin wrapper —
 * all logic lives in ReconciliationService so it can be invoked from
 * the admin "Resync" button on a single order too.
 */
class Reconcile
{
    private ReconciliationService $reconciliationService;
    private LoggerInterface $logger;

    public function __construct(
        ReconciliationService $reconciliationService,
        LoggerInterface $logger
    ) {
        $this->reconciliationService = $reconciliationService;
        $this->logger = $logger;
    }

    public function execute(): void
    {
        try {
            $this->reconciliationService->run();
        } catch (\Throwable $e) {
            $this->logger->error('Bob Go reconciliation cron failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
