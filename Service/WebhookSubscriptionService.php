<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Service;

use BobGroup\BobGo\Api\BobGoApiClient;
use BobGroup\BobGo\Api\BobGoApiException;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Manages Bob Go webhook subscriptions for fulfillment and tracking events.
 *
 * When fulfillment sync is enabled, this service subscribes the Magento store's
 * webhook endpoint to receive real-time notifications from Bob Go. When disabled,
 * it cleans up by deleting all existing subscriptions.
 *
 * Delivery URL format: {store_base_url}/rest/V1/bobgo/webhook
 */
class WebhookSubscriptionService
{
    private const WEBHOOK_TOPICS = [
        'fulfillment/created',
        'tracking/updated',
    ];

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

    public function __construct(
        BobGoApiClient $apiClient,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger
    ) {
        $this->apiClient = $apiClient;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
    }

    /**
     * Subscribe to Bob Go webhooks for fulfillment and tracking updates
     *
     * @throws BobGoApiException
     */
    public function subscribe(): void
    {
        $deliveryUrl = $this->getWebhookDeliveryUrl();

        $subscriptions = [];
        foreach (self::WEBHOOK_TOPICS as $topic) {
            $subscriptions[] = [
                'delivery_url' => $deliveryUrl,
                'topic' => $topic,
                'status' => 'active',
            ];
        }

        try {
            $this->apiClient->post('webhooks', [
                'webhook_subscriptions' => $subscriptions,
            ]);

            $this->logger->info('Bob Go webhook subscriptions created', [
                'delivery_url' => $deliveryUrl,
                'topics' => self::WEBHOOK_TOPICS,
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
     * Unsubscribe from all Bob Go webhooks
     */
    public function unsubscribe(): void
    {
        try {
            $subscriptions = $this->getSubscriptions();

            if (empty($subscriptions)) {
                $this->logger->info('Bob Go: no webhook subscriptions to remove');
                return;
            }

            foreach ($subscriptions as $subscription) {
                $id = $subscription['id'] ?? null;
                if ($id === null) {
                    continue;
                }

                try {
                    $this->apiClient->delete('webhooks/' . $id);
                } catch (BobGoApiException $e) {
                    $this->logger->error('Bob Go: failed to delete webhook subscription', [
                        'subscription_id' => $id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->logger->info('Bob Go webhook subscriptions removed');
        } catch (BobGoApiException $e) {
            $this->logger->error('Bob Go: failed to list webhook subscriptions for removal', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get current webhook subscriptions from Bob Go
     *
     * @return array<int,array<string,mixed>>
     * @throws BobGoApiException
     */
    public function getSubscriptions(): array
    {
        return $this->apiClient->get('webhooks');
    }

    /**
     * Build the webhook delivery URL for this Magento store
     *
     * @return string
     */
    private function getWebhookDeliveryUrl(): string
    {
        $baseUrl = $this->storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB);
        return rtrim($baseUrl, '/') . '/rest/V1/bobgo/webhook';
    }
}
