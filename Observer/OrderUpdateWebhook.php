<?php

namespace BobGroup\BobGo\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\HTTP\Client\Curl;
use BobGroup\BobGo\Model\Carrier\UData;
use Psr\Log\LoggerInterface;
use Magento\Store\Model\StoreManagerInterface;

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

        // Extract order data and send to the webhook URL
        $this->sendWebhook($order, 'order_updated');
    }

    private function sendWebhook($order, $eventType)
    {
        // Webhook URL
        $url = $this->getWebhookUrl();

        // Extract order items
        $itemsData = [];
        foreach ($order->getAllItems() as $item) {
            $itemsData[] = $item->getData();
        }

        // Extract shipping address
        $shippingAddress = $order->getShippingAddress();
        $shippingAddressData = $shippingAddress ? $shippingAddress->getData() : [];

        // Extract billing address
        $billingAddress = $order->getBillingAddress();
        $billingAddressData = $billingAddress ? $billingAddress->getData() : [];

        // Prepare payload
        $data = [
            'event' => $eventType,
            'order_id' => $order->getId(),
            'channel_identifier' => $this->getStoreUrl(),
            'store_id' => $this->getStoreId(),
            'order_data' => $order->getData(),
            'items' => $itemsData,
            'shipping_address' => $shippingAddressData,
            'billing_address'  => $billingAddressData,
        ];

        // Send the webhook
        $this->makeHttpPostRequest($url, $data);
    }

    private function makeHttpPostRequest($url, $data)
    {
        // Generate the signature using a secret key and the payload (example using HMAC SHA256)
        $secretKey = 'your_secret_key';
        $payloadJson = json_encode($data);
        $signature = hash_hmac('sha256', $payloadJson, $secretKey);

        // Set headers and post the data
        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->addHeader('X-M-Webhook-Signature', $signature); // Add your custom header here

        // Perform the API request
        $payloadJson = json_encode($data);
        if ($payloadJson === false) {
            //$this->logger->error('Payload Webhook failed: Unable to encode JSON.');
            throw new \RuntimeException('Failed to encode payload to JSON.');
        }

        // Set headers and post the data
        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->post($url, $payloadJson);
        $statusCode = $this->curl->getStatus();
        $responseBody = $this->curl->getBody();

        // Decode the response
        $response = json_decode($responseBody, true);
    }

    private function getWebhookUrl(): string
    {
        return UData::WEBHOOK_URL;
    }

    private function getStoreId(): string
    {
        $storeId = $this->storeManager->getStore()->getId();
        return $storeId;
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
