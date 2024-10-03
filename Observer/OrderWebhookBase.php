<?php

namespace BobGroup\BobGo\Observer;

use BobGroup\BobGo\Model\Carrier\UData;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use BobGroup\BobGo\Model\Carrier\BobGo;

abstract class OrderWebhookBase implements ObserverInterface
{
    protected Curl $curl;
    protected LoggerInterface $logger;
    protected StoreManagerInterface $storeManager;
    protected ScopeConfigInterface $scopeConfig;
    protected $bobGo;

    public function __construct(LoggerInterface $logger, Curl $curl, StoreManagerInterface $storeManager, ScopeConfigInterface $scopeConfig, BobGo $bobGo)
    {
        $this->logger = $logger;
        $this->curl = $curl;
        $this->storeManager = $storeManager;
        $this->scopeConfig = $scopeConfig;
        $this->bobGo = $bobGo;
    }

    protected function sendWebhook($order)
    {
        // Return early if not enabled
        if (!$this->bobGo->isWebhookEnabled()) {
            return;
        }

        // Webhook URL
        $url = $this->getWebhookUrl();

        $storeId = $this->getStoreId();

        $orderId = $order->getId();

        // Prepare payload
        $data = [
            'event' => 'order_updated',
            'order_id' => $orderId,
            'channel_identifier' => $this->bobGo->getBaseUrl(),
            'store_id' => $storeId,
            'webhooks_enabled' => true, // If we get to this point webhooks are enabled
        ];

        // Send the webhook
        $this->makeHttpPostRequest($url, $data, $storeId);
    }

    private function makeHttpPostRequest($url, $data, $storeId)
    {
        // Generate the signature using the webhook key saved in config
        $webhookKey = $this->scopeConfig->getValue('carriers/bobgo/webhook_key', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
        // Generate the HMAC-SHA256 hash as raw binary data
        $rawSignature = hash_hmac('sha256', $storeId, $webhookKey, true);

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
}
