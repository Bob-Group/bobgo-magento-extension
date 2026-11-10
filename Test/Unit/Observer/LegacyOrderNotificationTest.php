<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Observer;

use BobGroup\BobGo\Model\Config\ApiConfig;
use BobGroup\BobGo\Observer\LegacyOrderNotification;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Stores upgraded from 1.0.x have no API key and no order push, only the 1.0.x
 * notification settings. Their orders must keep reaching Bob Go.
 */
class LegacyOrderNotificationTest extends TestCase
{
    private const KEY = 'consumer-secret';

    private $scopeConfig;
    private $apiConfig;
    private $curl;
    private $logger;
    /** @var LegacyOrderNotification */
    private $observer;

    /** @var array<string,string> */
    private $headers = [];
    /** @var string|null */
    private $postedUrl;
    /** @var array<string,mixed>|null */
    private $postedBody;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->apiConfig = $this->createMock(ApiConfig::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->curl = $this->createMock(Curl::class);
        $this->curl->method('addHeader')->willReturnCallback(function ($name, $value) {
            $this->headers[$name] = $value;
        });
        $this->curl->method('post')->willReturnCallback(function ($url, $body) {
            $this->postedUrl = $url;
            $this->postedBody = json_decode($body, true);
        });
        $this->curl->method('getStatus')->willReturn(200);
        $curlFactory = $this->createMock(CurlFactory::class);
        $curlFactory->method('create')->willReturn($this->curl);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getBaseUrl')->willReturn('https://www.shop.co.za/index.php/');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $this->observer = new LegacyOrderNotification(
            $this->scopeConfig,
            $this->apiConfig,
            $storeManager,
            $curlFactory,
            $this->logger
        );
    }

    public function testSendsThe10xNoticeForAnUpgradedStore(): void
    {
        $this->legacySettings(true, self::KEY);

        $this->observer->execute($this->eventFor($this->order(42, 3)));

        $this->assertSame('https://api.bobgo.co.za/webhook/channel/magento', $this->postedUrl);
        $this->assertSame([
            'event' => 'order_updated',
            'order_id' => 42,
            'channel_identifier' => 'shop.co.za',
            'store_id' => '3',
            'webhooks_enabled' => true,
        ], $this->postedBody);
        $this->assertSame(
            base64_encode(hash_hmac('sha256', '3', self::KEY, true)),
            $this->headers['x-m-webhook-signature']
        );
    }

    public function testDoesNothingWhenThe10xNoticesWereOff(): void
    {
        $this->legacySettings(false, self::KEY);
        $this->curl->expects($this->never())->method('post');

        $this->observer->execute($this->eventFor($this->order(42, 1)));
    }

    public function testDoesNothingWithoutThe10xKey(): void
    {
        $this->legacySettings(true, '');
        $this->curl->expects($this->never())->method('post');

        $this->observer->execute($this->eventFor($this->order(42, 1)));
    }

    /**
     * Once order push is running, Bob Go gets the order from it. Telling it
     * the 1.0.x way as well would announce the same order twice.
     */
    public function testStepsAsideOnceOrderPushIsOn(): void
    {
        $this->legacySettings(true, self::KEY);
        $this->apiConfig->method('isConfigured')->willReturn(true);
        $this->apiConfig->method('isOrderPushEnabled')->willReturn(true);
        $this->curl->expects($this->never())->method('post');

        $this->observer->execute($this->eventFor($this->order(42, 1)));
    }

    /**
     * A key alone does not push orders, so the 1.0.x notice is still the only
     * way Bob Go hears about them.
     */
    public function testKeepsNotifyingWhenAKeyIsSavedButPushIsOff(): void
    {
        $this->legacySettings(true, self::KEY);
        $this->apiConfig->method('isConfigured')->willReturn(true);
        $this->apiConfig->method('isOrderPushEnabled')->willReturn(false);
        $this->curl->expects($this->once())->method('post');

        $this->observer->execute($this->eventFor($this->order(42, 1)));
    }

    public function testAFailedNoticeNeverBreaksTheOrderSave(): void
    {
        $this->legacySettings(true, self::KEY);
        $curl = $this->createMock(Curl::class);
        $curl->method('post')->willThrowException(new \Exception('Operation timed out'));
        $curlFactory = $this->createMock(CurlFactory::class);
        $curlFactory->method('create')->willReturn($curl);
        $store = $this->createMock(StoreInterface::class);
        $store->method('getBaseUrl')->willReturn('https://shop.co.za/');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $this->logger->expects($this->once())->method('warning');

        $observer = new LegacyOrderNotification(
            $this->scopeConfig,
            $this->apiConfig,
            $storeManager,
            $curlFactory,
            $this->logger
        );
        $observer->execute($this->eventFor($this->order(42, 1)));
    }

    private function legacySettings(bool $enabled, string $key): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturn($enabled);
        $this->scopeConfig->method('getValue')->willReturn($key);
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    private function order(int $entityId, int $storeId)
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn($entityId);
        $order->method('getStoreId')->willReturn($storeId);
        return $order;
    }

    private function eventFor($order): Observer
    {
        $event = $this->getMockBuilder(Event::class)->addMethods(['getOrder'])->getMock();
        $event->method('getOrder')->willReturn($order);
        $observer = new Observer();
        $observer->setEvent($event);
        return $observer;
    }
}
