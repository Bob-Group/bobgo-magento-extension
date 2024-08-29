<?php

namespace BobGroup\BobGo\Observer;

use BobGroup\BobGo\Model\Carrier\UData;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

abstract class OrderWebhookBase implements ObserverInterface
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

    protected function sendWebhook($order)
    {
        // Webhook URL
        $url = $this->getWebhookUrl();

        $storeId = $this->getStoreId();

        $orderId = $order->getId();

        // Get the order creation time
        $createdAt = strtotime($order->getCreatedAt());
        $currentTime = time();

        // Define a time threshold  to consider the order as newly created
        $threshold = 5; // 5  seconds

        // Determine the event type based on the creation time
        if (($currentTime - $createdAt) < $threshold) {
            $eventType = 'order_created';
        } else {
            $eventType = 'order_updated';
        }

        // Log the event type for debugging purposes
        $this->logger->info('event: ' . $eventType);

        // Prepare payload
        $data = [
            'event' => $eventType,
            'order_id' => $orderId,
            'channel_identifier' => $this->getStoreUrl(),
            'store_id' => $storeId,
        ];

        // Send the webhook
        $this->makeHttpPostRequest($url, $data, $storeId);
    }

    private function makeHttpPostRequest($url, $data, $storeId)
    {
        // Generate the signature using a secret key and the payload (example using HMAC SHA256)
        $secretKey = 'KaJGW2cxx1-6z_qjGhSq5Hj4qh_OXl0R1tUPurVs66A';
        // Generate the HMAC-SHA256 hash as raw binary data
        $rawSignature = hash_hmac('sha256', $storeId, $secretKey, true);

        // Encode the binary data in Base64
        $signature = base64_encode($rawSignature);

        // Set headers and post the data
        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->addHeader('X-M-Webhook-Signature', $signature);

        // Perform the API request
        $payloadJson = json_encode($data);
        if ($payloadJson === false) {
            throw new \RuntimeException('Failed to encode payload to JSON.');
        }

        // Set headers and post the data
        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->post($url, $payloadJson);
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
