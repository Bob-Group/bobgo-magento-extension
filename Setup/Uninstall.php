<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Setup;

use BobGroup\BobGo\Service\WebhookSubscriptionService;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UninstallInterface;
use Psr\Log\LoggerInterface;

/**
 * Cleans up on `bin/magento module:uninstall`.
 *
 * The part that matters is deregistering the webhooks. Leaving them behind means
 * Bob Go keeps POSTing to a URL that no longer resolves, which fails every
 * delivery and burns the account-wide three-day window that disables
 * subscriptions — including any the merchant sets up later, on a different
 * platform. Removing the module cannot remove them for us, because they live on
 * Bob Go.
 *
 * Cron entries need no attention: they exist only while crontab.xml does.
 */
class Uninstall implements UninstallInterface
{
    private WebhookSubscriptionService $webhookSubscriptions;
    private ResourceConnection $resource;
    private LoggerInterface $logger;

    public function __construct(
        WebhookSubscriptionService $webhookSubscriptions,
        ResourceConnection $resource,
        LoggerInterface $logger
    ) {
        $this->webhookSubscriptions = $webhookSubscriptions;
        $this->resource = $resource;
        $this->logger = $logger;
    }

    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context): void
    {
        $setup->startSetup();

        $this->deregisterWebhooks();
        $this->removeConfiguration();
        $this->removeFlags();

        $setup->endSetup();
    }

    private function deregisterWebhooks(): void
    {
        try {
            $this->webhookSubscriptions->unsubscribe();
        } catch (\Throwable $e) {
            // Uninstall must complete regardless — the merchant can remove them
            // from the Bob Go dashboard.
            $this->logger->warning(
                'Bob Go: could not deregister webhooks during uninstall; remove them in the Bob Go dashboard',
                ['error' => $e->getMessage()]
            );
        }
    }

    private function removeConfiguration(): void
    {
        try {
            $connection = $this->resource->getConnection();
            $connection->delete(
                $this->resource->getTableName('core_config_data'),
                ['path LIKE ?' => 'carriers/bobgo/%']
            );
            // The suburb field we added to Store Information.
            $connection->delete(
                $this->resource->getTableName('core_config_data'),
                ['path = ?' => 'general/store_information/suburb']
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Bob Go: could not remove configuration during uninstall', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function removeFlags(): void
    {
        try {
            $connection = $this->resource->getConnection();
            $connection->delete(
                $this->resource->getTableName('flag'),
                ['flag_code LIKE ?' => 'bobgo_%']
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Bob Go: could not remove flags during uninstall', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
