<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Service;

use BobGroup\BobGo\Api\BobGoApiClient;
use BobGroup\BobGo\Api\BobGoApiException;
use BobGroup\BobGo\Model\Config\ApiConfig;
use Magento\Framework\FlagManager;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Manages Bob Go webhook subscriptions for fulfillment and tracking events.
 *
 * When fulfillment sync is enabled, this service subscribes the Magento store's
 * webhook endpoint to receive real-time notifications from Bob Go. When disabled,
 * it cleans up by deleting all existing subscriptions.
 *
 * All topics share a single delivery URL: {base_url}/bobgo/webhook/receive
 * The topic is sent by Bob Go in the body, or in an HTTP header.
 *
 * There is also a daily self-heal (verifyAndRepair) because Bob Go disables a
 * subscription after three days of failed deliveries and tells only the
 * merchant. Without it, a transient outage or an upgrade that changed the
 * delivery URL silently ends fulfilment sync for good.
 */
class WebhookSubscriptionService
{
    private const WEBHOOK_TOPICS = [
        'fulfillment/created',
        'tracking/updated',
        'order/updated',
    ];

    private const WEBHOOK_PATH = '/bobgo/webhook/receive';

    /** One conclusive health check per day. */
    private const HEALTH_FLAG = 'bobgo_webhook_health_checked_at';
    private const HEALTH_INTERVAL_SECONDS = 86400;

    /**
     * @var BobGoApiClient
     */
    private BobGoApiClient $apiClient;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var ApiConfig
     */
    private ApiConfig $apiConfig;

    /**
     * @var FlagManager
     */
    private FlagManager $flagManager;

    /**
     * @var DateTime
     */
    private DateTime $dateTime;

    public function __construct(
        BobGoApiClient $apiClient,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger,
        ApiConfig $apiConfig,
        FlagManager $flagManager,
        DateTime $dateTime
    ) {
        $this->apiClient = $apiClient;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
        $this->apiConfig = $apiConfig;
        $this->flagManager = $flagManager;
        $this->dateTime = $dateTime;
    }

    /**
     * Daily health check: are our subscriptions still registered and active?
     *
     * Bob Go's delivery layer disables a subscription after three days without a
     * successful delivery, emailing the merchant and telling the integration
     * nothing. This is the only way we ever find out. It also cleans up
     * subscriptions left behind by older versions of this extension, whose
     * delivery URL no longer resolves — those 404 on every delivery and count
     * against the very window that disables everything else.
     *
     * @return bool Whether a conclusive check was performed
     */
    public function verifyAndRepair(): bool
    {
        // A merchant who turned fulfilment sync off has deliberately
        // disconnected. Re-registering would override that intent — and
        // conflating "disconnected" with "registration failed" is what
        // permanently disabled self-healing on the WooCommerce integration.
        if (!$this->apiConfig->isFulfillmentSyncEnabled() || !$this->apiConfig->isConfigured()) {
            return false;
        }

        if (!$this->isHealthCheckDue()) {
            return false;
        }

        try {
            $subscriptions = $this->getSubscriptions();
        } catch (BobGoApiException $e) {
            // Inconclusive: do NOT stamp the flag, so we retry on the next tick
            // rather than waiting a day after a transient failure.
            $this->logger->warning('Bob Go: webhook health check could not list subscriptions', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        $this->stampHealthCheck();

        $deliveryUrl = $this->getWebhookDeliveryUrl();
        $stale = $this->findStaleSubscriptionIds($subscriptions, $deliveryUrl);
        if (!empty($stale)) {
            $this->logger->warning('Bob Go: removing webhook subscriptions for a dead delivery URL', [
                'ids' => $stale,
            ]);
            $this->deleteSubscriptions($stale);
        }

        $healthy = $this->activeTopicsForUrl($subscriptions, $deliveryUrl);
        $missing = array_values(array_diff(self::WEBHOOK_TOPICS, $healthy));
        if (empty($missing)) {
            return true;
        }

        $this->logger->warning('Bob Go: webhook subscriptions missing or inactive, re-registering', [
            'delivery_url' => $deliveryUrl,
            'missing' => $missing,
        ]);

        // Delete before create, or duplicates accumulate on every repair.
        $replaceable = $this->subscriptionIdsForTopics($subscriptions, $deliveryUrl, $missing);
        if (!empty($replaceable)) {
            $this->deleteSubscriptions($replaceable);
        }

        try {
            $this->createSubscriptions($deliveryUrl, $missing);
            $this->logger->info('Bob Go: webhook subscriptions re-registered', ['topics' => $missing]);
        } catch (BobGoApiException $e) {
            $this->logger->error('Bob Go: webhook re-registration failed', ['error' => $e->getMessage()]);
        }

        return true;
    }

    private function isHealthCheckDue(): bool
    {
        $last = $this->flagManager->getFlagData(self::HEALTH_FLAG);
        if (!is_scalar($last) || (string) $last === '') {
            return true;
        }
        $lastTs = strtotime((string) $last);
        if ($lastTs === false) {
            return true;
        }
        return (strtotime($this->dateTime->gmtDate()) - $lastTs) >= self::HEALTH_INTERVAL_SECONDS;
    }

    private function stampHealthCheck(): void
    {
        try {
            $this->flagManager->saveFlag(self::HEALTH_FLAG, $this->dateTime->gmtDate());
        } catch (\Throwable $e) {
            $this->logger->warning('Bob Go: could not stamp webhook health check flag', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Subscriptions that point at this store but at a path we no longer serve —
     * e.g. the Magento_Webapi REST route that 1.0.x registered.
     *
     * @param array<int,array<string,mixed>> $subscriptions
     * @return array<int,mixed>
     */
    private function findStaleSubscriptionIds(array $subscriptions, string $deliveryUrl): array
    {
        $baseUrl = $this->getStoreBaseUrl();
        $ids = [];
        foreach ($subscriptions as $subscription) {
            $id = $subscription['id'] ?? null;
            $url = (string) ($subscription['delivery_url'] ?? '');
            if ($id === null || $url === '' || $url === $deliveryUrl) {
                continue;
            }
            if (strpos($url, $baseUrl) === 0) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * Topics that are subscribed for this URL *and* usable.
     *
     * A row with no `status` field at all counts as active — treating an absent
     * field as "inactive" makes the repair churn on every run.
     *
     * @param array<int,array<string,mixed>> $subscriptions
     * @return string[]
     */
    private function activeTopicsForUrl(array $subscriptions, string $deliveryUrl): array
    {
        $topics = [];
        foreach ($subscriptions as $subscription) {
            if ((string) ($subscription['delivery_url'] ?? '') !== $deliveryUrl) {
                continue;
            }
            $topic = (string) ($subscription['topic'] ?? '');
            if ($topic === '') {
                continue;
            }
            if (!array_key_exists('status', $subscription)) {
                $topics[] = $topic;
                continue;
            }
            if (strtolower((string) $subscription['status']) === 'active') {
                $topics[] = $topic;
            }
        }
        return $topics;
    }

    /**
     * @param array<int,array<string,mixed>> $subscriptions
     * @param string[] $topics
     * @return array<int,mixed>
     */
    private function subscriptionIdsForTopics(array $subscriptions, string $deliveryUrl, array $topics): array
    {
        $ids = [];
        foreach ($subscriptions as $subscription) {
            $id = $subscription['id'] ?? null;
            if ($id === null || (string) ($subscription['delivery_url'] ?? '') !== $deliveryUrl) {
                continue;
            }
            if (in_array((string) ($subscription['topic'] ?? ''), $topics, true)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * @param array<int,mixed> $ids
     */
    private function deleteSubscriptions(array $ids): void
    {
        try {
            $this->apiClient->delete('webhooks', ['ids' => $ids]);
        } catch (BobGoApiException $e) {
            $this->logger->error('Bob Go: failed to delete webhook subscriptions', [
                'ids' => $ids,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param string[] $topics
     * @throws BobGoApiException
     */
    private function createSubscriptions(string $deliveryUrl, array $topics): void
    {
        $payload = [];
        foreach ($topics as $topic) {
            $payload[] = [
                'delivery_url' => $deliveryUrl,
                'topic' => $topic,
                'status' => 'active',
            ];
        }
        $this->apiClient->post('webhooks', ['webhook_subscriptions' => $payload]);
    }

    private function getStoreBaseUrl(): string
    {
        return rtrim(
            $this->storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB),
            '/'
        );
    }

    /**
     * Subscribe to Bob Go webhooks for fulfillment and tracking updates.
     * Checks for existing subscriptions first to prevent duplicates.
     *
     * @throws BobGoApiException
     */
    public function subscribe(): void
    {
        if (!$this->apiConfig->isConfigured()) {
            return;
        }
        $deliveryUrl = $this->getWebhookDeliveryUrl();

        try {
            $subscriptions = $this->getSubscriptions();
        } catch (BobGoApiException $e) {
            $this->logger->error('Bob Go: failed to list existing webhooks before subscribing', [
                'error' => $e->getMessage(),
            ]);
            $subscriptions = [];
        }

        // Clean up anything pointing at a delivery URL this store no longer
        // serves (1.0.x used a Magento_Webapi REST route). Those 404 on every
        // delivery, and Bob Go counts that against the same three-day window
        // that disables the subscriptions we do want.
        $stale = $this->findStaleSubscriptionIds($subscriptions, $deliveryUrl);
        if (!empty($stale)) {
            $this->logger->warning('Bob Go: removing webhook subscriptions for a dead delivery URL', [
                'ids' => $stale,
            ]);
            $this->deleteSubscriptions($stale);
        }

        // Compare against *active* topics, not merely present ones — an
        // inactive subscription would otherwise look like a healthy one and
        // never be repaired.
        $active = $this->activeTopicsForUrl($subscriptions, $deliveryUrl);
        $missing = array_values(array_diff(self::WEBHOOK_TOPICS, $active));

        if (empty($missing)) {
            $this->logger->info('Bob Go: all webhook subscriptions already active', [
                'delivery_url' => $deliveryUrl,
                'topics' => self::WEBHOOK_TOPICS,
            ]);
            return;
        }

        // Delete before create so a re-subscribe doesn't stack duplicates.
        $replaceable = $this->subscriptionIdsForTopics($subscriptions, $deliveryUrl, $missing);
        if (!empty($replaceable)) {
            $this->deleteSubscriptions($replaceable);
        }

        try {
            $this->createSubscriptions($deliveryUrl, $missing);
            $this->logger->info('Bob Go webhook subscriptions created', [
                'delivery_url' => $deliveryUrl,
                'topics' => $missing,
            ]);
        } catch (BobGoApiException $e) {
            $this->logger->error('Bob Go webhook subscription failed', [
                'error' => $e->getMessage(),
                'status_code' => $e->getStatusCode(),
            ]);
            throw $e;
        }
    }

    /**
     * Unsubscribe from all Bob Go webhooks for this store.
     * Matches by store base URL prefix to also clean up old webhook URLs.
     */
    public function unsubscribe(): void
    {
        if (!$this->apiConfig->isConfigured()) {
            return;
        }
        try {
            $subscriptions = $this->getSubscriptions();

            if (empty($subscriptions)) {
                $this->logger->info('Bob Go: no webhook subscriptions to remove');
                return;
            }

            $baseUrl = $this->getStoreBaseUrl();
            $idsToDelete = [];

            foreach ($subscriptions as $subscription) {
                $id = $subscription['id'] ?? null;
                $url = $subscription['delivery_url'] ?? '';

                // Delete any subscription whose URL belongs to this store (by base URL prefix)
                if ($id !== null && strpos($url, $baseUrl) === 0) {
                    $idsToDelete[] = $id;
                }
            }

            if (empty($idsToDelete)) {
                $this->logger->info('Bob Go: no webhook subscriptions for this store to remove');
                return;
            }

            $this->apiClient->delete('webhooks', ['ids' => $idsToDelete]);

            $this->logger->info('Bob Go webhook subscriptions removed', [
                'ids' => $idsToDelete,
            ]);
        } catch (BobGoApiException $e) {
            $this->logger->error('Bob Go: failed to remove webhook subscriptions', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get current webhook subscriptions from Bob Go.
     *
     * @return array<int,array<string,mixed>>
     * @throws BobGoApiException
     */
    public function getSubscriptions(): array
    {
        $response = $this->apiClient->get('webhooks');
        return $response['webhook_subscriptions'] ?? [];
    }

    /**
     * Build the webhook delivery URL for this Magento store.
     *
     * @return string
     */
    private function getWebhookDeliveryUrl(): string
    {
        $baseUrl = $this->storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB);
        return rtrim($baseUrl, '/') . self::WEBHOOK_PATH;
    }
}
