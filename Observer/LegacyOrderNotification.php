<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Observer;

use BobGroup\BobGo\Model\Config\ApiConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tells Bob Go about order changes the way 1.0.x did, for stores upgraded from
 * 1.0.x that have not moved to order push.
 *
 * 1.0.x posted a small signed "order_updated" notice on every order save, and Bob
 * Go then fetched the order itself through the merchant's Magento integration. It
 * was switched on by carriers/bobgo/enable_webhooks and signed with
 * carriers/bobgo/webhook_key. 1.2.0 no longer shows those settings, but every
 * upgraded store still has them saved, so this keeps its orders reaching Bob Go.
 *
 * Steps aside once order push is on with an API key, so Bob Go never hears about
 * one order both ways.
 */
class LegacyOrderNotification implements ObserverInterface
{
    private const URL = 'https://api.bobgo.co.za/webhook/channel/magento';
    private const XML_PATH_ENABLED = 'carriers/bobgo/enable_webhooks';
    private const XML_PATH_KEY = 'carriers/bobgo/webhook_key';
    private const SIGNATURE_HEADER = 'x-m-webhook-signature';

    /**
     * This runs on the order save, at checkout among other places. 1.0.x set no
     * timeout at all; a notice this small needs a few seconds at most.
     */
    private const TIMEOUT_SECONDS = 5;
    private const CONNECT_TIMEOUT_SECONDS = 3;

    private ScopeConfigInterface $scopeConfig;
    private ApiConfig $apiConfig;
    private StoreManagerInterface $storeManager;
    private CurlFactory $curlFactory;
    private LoggerInterface $logger;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ApiConfig $apiConfig,
        StoreManagerInterface $storeManager,
        CurlFactory $curlFactory,
        LoggerInterface $logger
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->apiConfig = $apiConfig;
        $this->storeManager = $storeManager;
        $this->curlFactory = $curlFactory;
        $this->logger = $logger;
    }

    public function execute(Observer $observer): void
    {
        try {
            $order = $observer->getEvent()->getOrder();
            if (!$order instanceof OrderInterface) {
                return;
            }

            $storeId = (int) $order->getStoreId();
            if ($this->apiConfig->isConfigured($storeId) && $this->apiConfig->isOrderPushEnabled($storeId)) {
                return;
            }

            if (!$this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId)) {
                return;
            }

            $key = $this->scopeConfig->getValue(self::XML_PATH_KEY, ScopeInterface::SCOPE_STORE, $storeId);
            if (!is_string($key) || $key === '') {
                return;
            }

            $this->notify($order, $storeId, $key);
        } catch (\Throwable $e) {
            // Saving an order must never fail because Bob Go could not be told.
            $this->logger->warning('Bob Go: 1.0.x order notification failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function notify(OrderInterface $order, int $storeId, string $key): void
    {
        $storeIdText = (string) $storeId;

        $body = json_encode([
            'event' => 'order_updated',
            'order_id' => $order->getEntityId(),
            'channel_identifier' => $this->channelIdentifier($storeId),
            'store_id' => $storeIdText,
            'webhooks_enabled' => true,
        ]);
        if ($body === false) {
            return;
        }

        // 1.0.x signed the store id, not the body, and Bob Go checks it that way.
        $signature = base64_encode(hash_hmac('sha256', $storeIdText, $key, true));

        $curl = $this->curlFactory->create();
        $curl->addHeader('Content-Type', 'application/json');
        $curl->addHeader(self::SIGNATURE_HEADER, $signature);
        $curl->setOption(CURLOPT_TIMEOUT, self::TIMEOUT_SECONDS);
        $curl->setOption(CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT_SECONDS);
        $curl->post(self::URL, $body);

        $status = (int) $curl->getStatus();
        if ($status < 200 || $status >= 300) {
            $this->logger->warning('Bob Go: 1.0.x order notification was not accepted', [
                'order_id' => $order->getEntityId(),
                'status' => $status,
            ]);
        }
    }

    /**
     * The order's store host, without scheme, path or "www.", which is how 1.0.x
     * identified the store and how Bob Go knows its Magento channel.
     */
    private function channelIdentifier(int $storeId): string
    {
        $baseUrl = (string) $this->storeManager->getStore($storeId)->getBaseUrl();
        $host = explode('/', (string) preg_replace('#^https?://#i', '', $baseUrl))[0];
        return strpos($host, 'www.') === 0 ? substr($host, 4) : $host;
    }
}
