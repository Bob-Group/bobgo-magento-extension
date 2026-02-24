<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Observer;

use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface;
use Psr\Log\LoggerInterface;
use BobGroup\BobGo\Api\BobGoApiClient;
use BobGroup\BobGo\Api\BobGoApiException;
use BobGroup\BobGo\Model\Config\ApiConfig;
use BobGroup\BobGo\Service\WebhookSubscriptionService;

/**
 * Reacts to admin configuration changes in the carriers section.
 *
 * When the Bob Go API key, environment, active toggle, or fulfillment sync
 * settings change, this observer tests API connectivity, validates rates,
 * and manages webhook subscriptions accordingly. Results are displayed as
 * admin success/error messages.
 */
class ConfigChangeObserver implements ObserverInterface
{
    /**
     * @var BobGoApiClient
     */
    private BobGoApiClient $apiClient;

    /**
     * @var ApiConfig
     */
    private ApiConfig $apiConfig;

    /**
     * @var WebhookSubscriptionService
     */
    private WebhookSubscriptionService $webhookSubscriptionService;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var ManagerInterface
     */
    private ManagerInterface $messageManager;

    /**
     * @var ReinitableConfigInterface
     */
    private ReinitableConfigInterface $reinitableConfig;

    public function __construct(
        BobGoApiClient $apiClient,
        ApiConfig $apiConfig,
        WebhookSubscriptionService $webhookSubscriptionService,
        LoggerInterface $logger,
        ManagerInterface $messageManager,
        ReinitableConfigInterface $reinitableConfig
    ) {
        $this->apiClient = $apiClient;
        $this->apiConfig = $apiConfig;
        $this->webhookSubscriptionService = $webhookSubscriptionService;
        $this->logger = $logger;
        $this->messageManager = $messageManager;
        $this->reinitableConfig = $reinitableConfig;
    }

    /**
     * Handle carrier config section save event.
     *
     * Checks which config paths changed and triggers appropriate actions:
     * - API key or environment change → test API connectivity
     * - Active toggle enabled → test rates-at-checkout connectivity
     * - Fulfillment sync, environment, or API key change → manage webhook subscriptions
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        // Reinitialize config to pick up freshly saved values (in-memory cache is stale during save)
        $this->reinitableConfig->reinit();

        $changedPaths = $observer->getEvent()->getData('changed_paths');
        if (!is_array($changedPaths)) {
            return;
        }

        $apiKeyChanged = in_array(ApiConfig::XML_PATH_API_KEY, $changedPaths);
        $environmentChanged = in_array(ApiConfig::XML_PATH_ENVIRONMENT, $changedPaths);
        $activeChanged = in_array(ApiConfig::XML_PATH_ACTIVE, $changedPaths);
        $fulfillmentSyncChanged = in_array(ApiConfig::XML_PATH_ENABLE_FULFILLMENT_SYNC, $changedPaths);

        // Test connectivity when API key or environment changes
        if ($apiKeyChanged || $environmentChanged) {
            $this->testConnectivity();
        }

        // Test RAC connectivity when active toggle changes
        if ($activeChanged && $this->apiConfig->isActive()) {
            $this->testRacConnectivity();
        }

        // Manage webhook subscriptions when fulfillment sync is toggled
        if ($fulfillmentSyncChanged || $environmentChanged || $apiKeyChanged) {
            $this->manageWebhookSubscriptions();
        }
    }

    /**
     * Test basic API connectivity by fetching webhook subscriptions.
     *
     * @return void
     */
    private function testConnectivity(): void
    {
        if (!$this->apiConfig->isConfigured()) {
            return;
        }

        try {
            $this->apiClient->get('webhooks');
            $this->messageManager->addSuccessMessage(
                __('Bob Go API connection successful (%1 environment).', $this->apiConfig->getEnvironment())
            );
        } catch (BobGoApiException $e) {
            $this->logger->error('Bob Go connectivity test failed', ['error' => $e->getMessage()]);
            $this->messageManager->addErrorMessage(
                __('Failed to connect to Bob Go API: %1', $e->getMessage())
            );
        }
    }

    /**
     * Test rates-at-checkout connectivity by posting a sample rate request.
     *
     * @return void
     */
    private function testRacConnectivity(): void
    {
        if (!$this->apiConfig->isConfigured()) {
            $this->messageManager->addErrorMessage(
                __('Bob Go API key is not configured. Please enter your API key to enable rates at checkout.')
            );
            return;
        }

        try {
            $payload = [
                'collection_address' => [
                    'company' => 'Test',
                    'street_address' => '1 Test Street',
                    'local_area' => 'Cape Town',
                    'city' => 'Cape Town',
                    'zone' => 'WC',
                    'country' => 'ZA',
                    'code' => '8001',
                ],
                'delivery_address' => [
                    'company' => 'Test',
                    'street_address' => '1 Test Avenue',
                    'local_area' => 'Sandton',
                    'city' => 'Johannesburg',
                    'zone' => 'GP',
                    'country' => 'ZA',
                    'code' => '2196',
                ],
                'items' => [
                    [
                        'description' => 'Test Product',
                        'quantity' => 1,
                        'price' => 100.00,
                        'length_cm' => 0,
                        'width_cm' => 0,
                        'height_cm' => 0,
                        'weight_kg' => 1.0,
                    ],
                ],
                'declared_value' => 0,
            ];

            $response = $this->apiClient->post('rates-at-checkout', $payload);

            if (isset($response['rates']) && is_array($response['rates']) && !empty($response['rates'])) {
                $this->messageManager->addSuccessMessage(
                    __('Bob Go rates at checkout connected successfully.')
                );
            } else {
                $this->messageManager->addErrorMessage(
                    __('Connected to Bob Go but no rates were returned. Please check your rates at checkout configuration on Bob Go.')
                );
            }
        } catch (BobGoApiException $e) {
            $this->logger->error('Bob Go RAC test failed', ['error' => $e->getMessage()]);
            $this->messageManager->addErrorMessage(
                __('Failed to connect to Bob Go rates at checkout: %1', $e->getMessage())
            );
        }
    }

    /**
     * Subscribe or unsubscribe webhooks based on the fulfillment sync toggle.
     *
     * @return void
     */
    private function manageWebhookSubscriptions(): void
    {
        if (!$this->apiConfig->isConfigured()) {
            return;
        }

        try {
            if ($this->apiConfig->isFulfillmentSyncEnabled()) {
                $this->webhookSubscriptionService->subscribe();
                $this->messageManager->addSuccessMessage(
                    __('Bob Go webhook subscriptions activated for fulfillment sync.')
                );
            } else {
                $this->webhookSubscriptionService->unsubscribe();
            }
        } catch (BobGoApiException $e) {
            $this->logger->error('Bob Go webhook subscription management failed', ['error' => $e->getMessage()]);
            $this->messageManager->addErrorMessage(
                __('Failed to manage Bob Go webhook subscriptions. Please try again.')
            );
        }
    }
}
