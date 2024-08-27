<?php

namespace BobGroup\BobGo\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Store\Model\StoreManagerInterface;
use BobGroup\BobGo\Model\Carrier\UData;
use Psr\Log\LoggerInterface;

class OrderUpdateWebhook implements ObserverInterface
{
    protected Curl $curl;
    protected LoggerInterface $logger;
    protected StoreManagerInterface $storeManager;

    public function __construct(LoggerInterface $logger, Curl $curl, StoreManagerInterface $storeManager)
    {
        $this->logger = $logger;
        $this->curl = $curl;
        $this->storeManager = $storeManager;
    }

    public function execute(Observer $observer)
    {
        $order = $observer->getEvent()->getOrder();
        if (!$order) {
            return;
        }

        $this->sendWebhook($order, 'order_updated');
    }

    private function sendWebhook($order, $eventType)
    {
        $url = $this->getWebhookUrl();

        // Get Store UUID and add to query parameters
        $storeUuid = $this->getStoreUuid();
        $url .= '?channel=' . urlencode($storeUuid);

        $data = [
            'event' => $eventType,
            'order_id' => $order->getId(),
            'order_data' => $order->getData()
        ];

        $this->makeHttpPostRequest($url, $data);
    }

    private function makeHttpPostRequest($url, $data)
    {
        $payloadJson = json_encode($data);
        if ($payloadJson === false) {
            throw new \RuntimeException('Failed to encode payload to JSON.');
        }

        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->post($url, $payloadJson);
        $statusCode = $this->curl->getStatus();
        $responseBody = $this->curl->getBody();

        $response = json_decode($responseBody, true);
    }

    private function getWebhookUrl(): string
    {
        return UData::WEBHOOK_URL;
    }

    private function getStoreUuid(): string
    {
        return $this->storeManager->getStore()->getConfig('general/store_information/store_id');
    }
}
