<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Controller\Tracking;

use Magento\Framework\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\Registry;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\StoreManagerInterface;
use BobGroup\BobGo\Api\BobGoApiClient;
use BobGroup\BobGo\Api\BobGoApiException;
use BobGroup\BobGo\Model\Config\ApiConfig;

/**
 * Customer-facing order tracking page controller.
 *
 * Accepts an order_reference parameter, queries the Bob Go tracking API,
 * and renders the tracking results. Only accessible when the track order
 * feature is enabled in admin configuration.
 *
 * Route: /bobgo/tracking/index
 */
class Index extends \Magento\Framework\App\Action\Action
{
    /** @var PageFactory */
    protected $resultPageFactory;

    /** @var JsonFactory */
    protected $jsonFactory;

    /** @var LoggerInterface */
    protected $logger;

    /** @var ScopeConfigInterface */
    protected $scopeConfig;

    /** @var RedirectFactory */
    protected $redirectFactory;

    /** @var Registry */
    protected $registry;

    /** @var StoreManagerInterface */
    protected StoreManagerInterface $storeManager;

    /** @var BobGoApiClient */
    private BobGoApiClient $apiClient;

    /** @var ApiConfig */
    private ApiConfig $apiConfig;

    /**
     * @param Context $context
     * @param PageFactory $resultPageFactory
     * @param JsonFactory $jsonFactory
     * @param LoggerInterface $logger
     * @param ScopeConfigInterface $scopeConfig
     * @param RedirectFactory $redirectFactory
     * @param StoreManagerInterface $storeManager
     * @param BobGoApiClient $apiClient
     * @param ApiConfig $apiConfig
     * @param Registry $registry
     */
    public function __construct(
        Context $context,
        PageFactory $resultPageFactory,
        JsonFactory $jsonFactory,
        LoggerInterface $logger,
        ScopeConfigInterface $scopeConfig,
        RedirectFactory $redirectFactory,
        StoreManagerInterface $storeManager,
        BobGoApiClient $apiClient,
        ApiConfig $apiConfig,
        Registry $registry
    ) {
        $this->resultPageFactory = $resultPageFactory;
        $this->jsonFactory = $jsonFactory;
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
        $this->redirectFactory = $redirectFactory;
        $this->storeManager = $storeManager;
        $this->apiClient = $apiClient;
        $this->apiConfig = $apiConfig;
        $this->registry = $registry;
        parent::__construct($context);
    }

    /**
     * Execute the tracking page action.
     *
     * Redirects to 404 if the feature is disabled. Otherwise, fetches
     * tracking data from the Bob Go API using the order_reference param
     * and renders the tracking page template.
     *
     * @return \Magento\Framework\View\Result\Page|\Magento\Framework\Controller\Result\Redirect
     */
    public function execute()
    {
        $isEnabled = $this->scopeConfig->isSetFlag(
            'carriers/bobgo/enable_track_order',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );

        if (!$isEnabled) {
            return $this->redirectFactory->create()->setPath('noroute');
        }

        $trackingReference = $this->getRequest()->getParam('order_reference');

        if ($trackingReference && $this->apiConfig->isConfigured()) {
            try {
                $response = $this->apiClient->get('tracking', [
                    'tracking_reference' => $trackingReference,
                ]);

                if (is_array($response) && isset($response[0])) {
                    $this->registry->register('shipment_data', $response[0]);
                }
            } catch (BobGoApiException $e) {
                $this->logger->error('Bob Go tracking request failed', [
                    'tracking_reference' => $trackingReference,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->resultPageFactory->create();
    }
}
