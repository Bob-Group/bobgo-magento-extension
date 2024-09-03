<?php
namespace BobGroup\BobGo\Controller\Tracking;

use Magento\Framework\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\Registry;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Store\Model\StoreManagerInterface;
use BobGroup\BobGo\Model\Carrier\UData;

class Index extends \Magento\Framework\App\Action\Action
{
    protected $resultPageFactory;
    protected $jsonFactory;
    protected $curl;
    protected $logger;
    protected $scopeConfig;
    protected $redirectFactory;
    protected $registry;
    protected StoreManagerInterface $storeManager;

    public function __construct(
        Context $context,
        PageFactory $resultPageFactory,
        JsonFactory $jsonFactory,
        LoggerInterface $logger,
        ScopeConfigInterface $scopeConfig,
        RedirectFactory $redirectFactory,
        StoreManagerInterface $storeManager,
        Curl $curl,
        Registry $registry
    ) {
        $this->resultPageFactory = $resultPageFactory;
        $this->jsonFactory = $jsonFactory;
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
        $this->redirectFactory = $redirectFactory;
        $this->storeManager = $storeManager;
        $this->curl = $curl;
        $this->registry = $registry;
        parent::__construct($context);
    }

    public function execute()
    {
        // This is only an extra check after the TrackOrderLink block
        // Check if the "Track My Order" feature is enabled
        $isEnabled = $this->scopeConfig->isSetFlag(
            'carriers/bobgo/enable_track_order',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );

        if (!$isEnabled) {
            // If the feature is disabled, redirect to home page or show a 404 error
            return $this->redirectFactory->create()->setPath('noroute');
        }

        $trackingReference = $this->getRequest()->getParam('order_reference');

        $channel = $this->getStoreUrl();

        if ($trackingReference) {
            $trackingUrl = sprintf(UData::TRACKING, $channel, $trackingReference);
                $this->curl->get($trackingUrl);
                $response = $this->curl->getBody();

                $decodedResponse = json_decode($response, true);

                if (is_array($decodedResponse) && isset($decodedResponse[0])) {
                    $shipmentData = $decodedResponse[0];

                    // Save data to the registry
                    $this->registry->register('shipment_data', $shipmentData);

                } else {
                    // Return early the response is not valid
                    return $this->resultPageFactory->create();
                }
        }

        return $this->resultPageFactory->create();
    }

    private function getStoreUrl(): string
    {
        $url = $this->storeManager->getStore()->getBaseUrl();

        // Remove protocol (http:// or https://)
        $host = preg_replace('#^https?://#', '', $url);

        // Ensure $host is a string before using it in explode
        $host = $host ?? '';

        // Remove everything after the host (e.g., paths, query strings)
        $host = explode('/', $host)[0];

        // If the host starts with 'www.', remove it
        if (strpos($host, 'www.') === 0) {
            $host = substr($host, 4);
        }

        return $host;
    }
}
