<?php

namespace BobGroup\BobGo\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\HTTP\Client\Curl;
use BobGroup\BobGo\Model\Carrier\UData;
use Psr\Log\LoggerInterface;
use Magento\Store\Model\StoreManagerInterface;

class OrderCreateWebhook implements ObserverInterface
{
    protected Curl $curl;
    protected LoggerInterface $logger;
    protected StoreManagerInterface $storeManager;

    public function __construct(LoggerInterface $logger, Curl $curl, StoreManagerInterface $storeManager)
    {
        $this->logger = $logger;
        $this->curl = $curl; // Initialize the Curl instance
        $this->storeManager = $storeManager;
    }

    public function execute(Observer $observer)
    {
        $this->logger->info('OrderCreateWebhook: execute method started');

        $order = $observer->getEvent()->getOrder();
        if (!$order) {
            $this->logger->error('OrderCreateWebhook: No order object found in observer');
            return;
        }

        // Log order creation data
        $this->logger->info('Order Created:', ['order_id' => $order->getId(), 'order_data' => $order->getData()]);

        // Extract order data and send to the webhook URL
        $this->sendWebhook($order, 'order_created');

        $this->logger->info('OrderCreateWebhook: execute method finished');
    }

    private function sendWebhook($order, $eventType)
    {
        $this->logger->info('OrderCreateWebhook: sendWebhook method started');

        // Webhook URL
        $url = $this->getWebhookUrl();
        $this->logger->info('OrderCreateWebhook: Webhook URL', ['url' => $url]);

        // Get Store UUID and add to query parameters
        $storeUuid = $this->getStoreUuid();
        $this->logger->info('UUID: ', ['uuid' => $storeUuid]);
        $url .= '?channel=' . urlencode($storeUuid);
        $this->logger->info('Webhook URL', ['url' => $url]);

        // Prepare payload
        $data = [
            'event' => $eventType,
            'order_id' => $order->getId(),
            'order_data' => $order->getData()
        ];

        // Log webhook payload
        $this->logger->info('Sending Webhook:', ['url' => $url, 'payload' => $data]);

        // Send the webhook
        $this->makeHttpPostRequest($url, $data);

        $this->logger->info('OrderCreateWebhook: sendWebhook method finished');
    }


    private function makeHttpPostRequest($url, $data)
    {
        $this->logger->info('URL:', ['url' => $url]);
        $this->logger->info('Data:', ['data' => $data]);

        // Perform the API request
        $payloadJson = json_encode($data);
        if ($payloadJson === false) {
            $this->logger->error('Payload Webhook failed: Unable to encode JSON.');
            throw new \RuntimeException('Failed to encode payload to JSON.');
        }

        $this->logger->info('Payload Webhook:', ['response' => $payloadJson]);

        // Set headers and post the data
        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->post($url, $payloadJson);
        $statusCode = $this->curl->getStatus();
        $responseBody = $this->curl->getBody();

        // Log status code and response body
        $this->logger->info('Webhook Response Status:', ['status' => $statusCode]);
        $this->logger->info('Webhook Response Body:', ['response' => $responseBody]);

        // Decode the response
        $response = json_decode($responseBody, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('Failed to decode webhook response:', ['error' => json_last_error_msg()]);
        } else {
            $this->logger->info('Response Webhook:', ['response' => $response]);
        }
    }

    private function getWebhookUrl(): string
    {
        return UData::WEBHOOK_URL;
    }

    private function getStoreUuid(): string
    {
        $storeId = $this->storeManager->getStore()->getId();
        return $storeId;
        //return $this->storeManager->getStore()->getConfig('general/store_information/store_id');
    }
}
