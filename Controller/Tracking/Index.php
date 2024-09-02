<?php
namespace BobGroup\BobGo\Controller\Tracking;

use Psr\Log\LoggerInterface;
use Magento\Framework\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;
use BobGroup\BobGo\Model\Carrier\UData;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Store\Model\StoreManagerInterface;

class Index extends \Magento\Framework\App\Action\Action
{
    protected $resultPageFactory;
    protected $jsonFactory;
    protected $curl;
    protected $logger;
    protected StoreManagerInterface $storeManager;

    public function __construct(
        Context $context,
        PageFactory $resultPageFactory,
        JsonFactory $jsonFactory,
        LoggerInterface $logger,
        StoreManagerInterface $storeManager,
        Curl $curl
    ) {
        $this->resultPageFactory = $resultPageFactory;
        $this->jsonFactory = $jsonFactory;
        $this->logger = $logger;
        $this->storeManager = $storeManager;
        $this->curl = $curl;
        parent::__construct($context);
    }

    public function execute()
    {
        $this->logger->info('Page Controller is executed.');
        $trackingReference = $this->getRequest()->getParam('order_reference');

        $this->logger->info('Tracking reference:', [$trackingReference]);

        $channel = $this->getStoreUrl();

        $this->logger->info('Channel:', [$channel]);

//        if ($trackingReference) {
//            $trackingUrl = sprintf(UData::TRACKING, $channel, $trackingReference);
//
//            try {
//                // Make the API call
//                $this->curl->get($trackingUrl);
//                $response = $this->curl->getBody();
//
//                // Optionally, you can decode the response if it's JSON
//                $response = json_decode($response, true);
//
//                // Handle the response (e.g., display it on the page)
//                $resultJson = $this->jsonFactory->create();
//                return $resultJson->setData($response);
//
//            } catch (\Exception $e) {
//                $this->messageManager->addErrorMessage(__('Unable to track the order.'));
//            }
//        }

        if ($trackingReference) {
            $trackingUrl = sprintf(UData::TRACKING, $channel, $trackingReference);

            try {
                // Make the API call
                $this->curl->get($trackingUrl);
                $response = $this->curl->getBody();

                // Decode the response
                $this->logger->info('Response:', [$response]);

                $decodedResponse = json_decode($response, true);
                $this->logger->info('Decoded Response:', [$decodedResponse]);

                if (is_array($decodedResponse) && isset($decodedResponse[0])) {
                    $shipmentData = $decodedResponse[0];

                    // Set response in the block
                    // In your controller action or block:
                    $layout = $this->_view->getLayout();
                    $this->logger->info('Layout Handles: ' . implode(', ', $layout->getUpdate()->getHandles()));


                    $blocks = $layout->getAllBlocks();

                    $blockNames = [];
                    foreach ($blocks as $block) {
                        $blockNames[] = $block->getNameInLayout();
                    }

                    $this->logger->info('Available Blocks:', $blockNames);

                    $block = $layout->getBlock('bobgo.tracking.index');

                    $this->logger->info('BobGo block:', [$block]);

                    if ($block) {
                        $block->setResponse($shipmentData);
                        $this->logger->info('Block found and data set.');
                    } else {
                        $this->logger->info('Block not found.');
                    }
                } else {
                    $this->logger->info('Unexpected response format.');
                }

            } catch (\Exception $e) {
                $this->logger->info('Track error: ' . $e->getMessage());
                $this->messageManager->addErrorMessage(__('Unable to track the order.'));
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
