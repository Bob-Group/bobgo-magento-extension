<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Service;

use BobGroup\BobGo\Api\BobGoApiClient;
use BobGroup\BobGo\Api\BobGoApiException;
use BobGroup\BobGo\Model\Config\ApiConfig;
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
 * The topic is sent by Bob Go in an HTTP header.
 */
class WebhookSubscriptionService
{
    private const WEBHOOK_TOPICS = [
        'fulfillment/created',
        'tracking/updated',
    ];

    private const WEBHOOK_PATH = '/bobgo/webhook/receive';

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

    public function __construct(
        BobGoApiClient $apiClient,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger,
        ApiConfig $apiConfig
    ) {
        $this->apiClient = $apiClient;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
        $this->apiConfig = $apiConfig;
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
        $existing = $this->getExistingTopicsForUrl($deliveryUrl);

        $missing = [];
        foreach (self::WEBHOOK_TOPICS as $topic) {
            if (!in_array($topic, $existing, true)) {
                $missing[] = [
                    'delivery_url' => $deliveryUrl,
                    'topic' => $topic,
                    'status' => 'active',
                ];
            }
        }

        if (empty($missing)) {
            $this->logger->info('Bob Go: all webhook subscriptions already exist', [
                'delivery_url' => $deliveryUrl,
                'topics' => self::WEBHOOK_TOPICS,
            ]);
            return;
        }

        try {
            $this->apiClient->post('webhooks', [
                'webhook_subscriptions' => $missing,
            ]);

            $createdTopics = array_column($missing, 'topic');
            $this->logger->info('Bob Go webhook subscriptions created', [
                'delivery_url' => $deliveryUrl,
                'topics' => $createdTopics,
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

            $baseUrl = rtrim(
                $this->storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB),
                '/'
            );
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
     * Get list of topics already subscribed for a given delivery URL.
     *
     * @param string $deliveryUrl
     * @return string[]
     */
    private function getExistingTopicsForUrl(string $deliveryUrl): array
    {
        try {
            $subscriptions = $this->getSubscriptions();
        } catch (BobGoApiException $e) {
            $this->logger->error('Bob Go: failed to list existing webhooks for duplicate check', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        $topics = [];
        foreach ($subscriptions as $subscription) {
            $url = $subscription['delivery_url'] ?? '';
            $topic = $subscription['topic'] ?? '';
            if ($url === $deliveryUrl && $topic !== '') {
                $topics[] = $topic;
            }
        }

        return $topics;
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
