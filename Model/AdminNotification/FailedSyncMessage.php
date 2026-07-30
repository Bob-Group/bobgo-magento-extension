<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Model\AdminNotification;

use BobGroup\BobGo\Model\Config\ApiConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Notification\MessageInterface;
use Magento\Framework\UrlInterface;
use Psr\Log\LoggerInterface;

/**
 * Admin banner when orders have failed to reach Bob Go.
 *
 * A failed push already lands on the order (bobgo_sync_status) and in the sync
 * log, but nothing surfaced it — a merchant had to go looking. Since the push
 * moved to a background job there is no request to attach an error message to at
 * all, so this is the only place a failure becomes visible without someone
 * already suspecting it.
 *
 * Counts orders sitting in `failed`, which excludes anything the retry backoff is
 * still working through.
 */
class FailedSyncMessage implements MessageInterface
{
    private const IDENTITY = 'bobgo_failed_order_sync';

    private ResourceConnection $resource;
    private ApiConfig $apiConfig;
    private UrlInterface $urlBuilder;
    private LoggerInterface $logger;

    /** @var int|null Memoised so the banner costs one query per page, not three. */
    private $failedCount;

    public function __construct(
        ResourceConnection $resource,
        ApiConfig $apiConfig,
        UrlInterface $urlBuilder,
        LoggerInterface $logger
    ) {
        $this->resource = $resource;
        $this->apiConfig = $apiConfig;
        $this->urlBuilder = $urlBuilder;
        $this->logger = $logger;
    }

    public function getIdentity(): string
    {
        // Varies with the count, so dismissing the notice for "3 failed" doesn't
        // also suppress it when a fourth turns up.
        return md5(self::IDENTITY . ':' . $this->countFailed());
    }

    public function isDisplayed(): bool
    {
        if (!$this->apiConfig->isOrderPushEnabled()) {
            return false;
        }
        return $this->countFailed() > 0;
    }

    /**
     * @return \Magento\Framework\Phrase
     */
    public function getText()
    {
        return __(
            '%1 order(s) failed to sync to Bob Go. Check the <a href="%2">Bob Go sync log</a> for details.',
            $this->countFailed(),
            $this->urlBuilder->getUrl('bobgo/synclog/index')
        );
    }

    public function getSeverity(): int
    {
        return MessageInterface::SEVERITY_MAJOR;
    }

    private function countFailed(): int
    {
        if ($this->failedCount !== null) {
            return $this->failedCount;
        }

        try {
            $connection = $this->resource->getConnection();
            $table = $this->resource->getTableName('sales_order');
            $this->failedCount = (int) $connection->fetchOne(
                $connection->select()
                    ->from($table, ['COUNT(*)'])
                    ->where('bobgo_sync_status = ?', 'failed')
            );
        } catch (\Throwable $e) {
            // A broken banner must not break the admin.
            $this->logger->warning('Bob Go: could not count failed order syncs', [
                'error' => $e->getMessage(),
            ]);
            $this->failedCount = 0;
        }

        return $this->failedCount;
    }
}
