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

class Index extends \Magento\Framework\App\Action\Action
{
    protected $resultPageFactory;
    protected $jsonFactory;
    protected $logger;
    protected $scopeConfig;
    protected $redirectFactory;
    protected $registry;
    protected StoreManagerInterface $storeManager;
    private BobGoApiClient $apiClient;
    private ApiConfig $apiConfig;

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
