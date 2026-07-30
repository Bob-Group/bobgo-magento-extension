<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Service;

use BobGroup\BobGo\Api\BobGoApiClient;
use BobGroup\BobGo\Api\BobGoApiException;
use BobGroup\BobGo\Model\Config\ApiConfig;
use BobGroup\BobGo\Service\WebhookSubscriptionService;
use Magento\Framework\FlagManager;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class WebhookSubscriptionServiceTest extends TestCase
{
    /** @var WebhookSubscriptionService */
    private $service;

    /** @var BobGoApiClient|\PHPUnit\Framework\MockObject\MockObject */
    private $apiClientMock;

    /** @var StoreManagerInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $storeManagerMock;

    /** @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $loggerMock;

    /** @var ApiConfig|\PHPUnit\Framework\MockObject\MockObject */
    private $apiConfigMock;

    /** @var FlagManager|\PHPUnit\Framework\MockObject\MockObject */
    private $flagManagerMock;

    protected function setUp(): void
    {
        $this->apiClientMock = $this->createMock(BobGoApiClient::class);
        $this->storeManagerMock = $this->createMock(StoreManagerInterface::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->apiConfigMock = $this->createMock(ApiConfig::class);
        $this->flagManagerMock = $this->createMock(FlagManager::class);

        // Tests assume an API key is configured unless they say otherwise.
        $this->apiConfigMock->method('isConfigured')->willReturn(true);

        $storeMock = $this->createMock(Store::class);
        $storeMock->method('getBaseUrl')
            ->with(UrlInterface::URL_TYPE_WEB)
            ->willReturn('https://example.com/');
        $this->storeManagerMock->method('getStore')->willReturn($storeMock);

        $dateTimeMock = $this->createMock(DateTime::class);
        $dateTimeMock->method('gmtDate')->willReturn('2026-07-30 12:00:00');

        $this->service = new WebhookSubscriptionService(
            $this->apiClientMock,
            $this->storeManagerMock,
            $this->loggerMock,
            $this->apiConfigMock,
            $this->flagManagerMock,
            $dateTimeMock
        );
    }

    public function testSubscribeCreatesAllMissingSubscriptions(): void
    {
        // No existing subscriptions
        $this->apiClientMock->method('get')
            ->with('webhooks')
            ->willReturn(['webhook_subscriptions' => []]);

        $this->apiClientMock->expects($this->once())
            ->method('post')
            ->with(
                'webhooks',
                $this->callback(function ($payload) {
                    $this->assertArrayHasKey('webhook_subscriptions', $payload);
                    $subs = $payload['webhook_subscriptions'];
                    $this->assertCount(3, $subs);
                    $this->assertEquals('fulfillment/created', $subs[0]['topic']);
                    $this->assertEquals('tracking/updated', $subs[1]['topic']);
                    $this->assertEquals('order/updated', $subs[2]['topic']);
                    $this->assertEquals('https://example.com/bobgo/webhook/receive', $subs[0]['delivery_url']);
                    $this->assertEquals('https://example.com/bobgo/webhook/receive', $subs[1]['delivery_url']);
                    $this->assertEquals('https://example.com/bobgo/webhook/receive', $subs[2]['delivery_url']);
                    $this->assertEquals('active', $subs[0]['status']);
                    return true;
                })
            )
            ->willReturn([]);

        $this->service->subscribe();
    }

    public function testSubscribeSkipsExistingSubscriptions(): void
    {
        $this->apiClientMock->method('get')
            ->with('webhooks')
            ->willReturn(['webhook_subscriptions' => [
                [
                    'id' => 1,
                    'topic' => 'fulfillment/created',
                    'delivery_url' => 'https://example.com/bobgo/webhook/receive',
                ],
            ]]);

        $this->apiClientMock->expects($this->once())
            ->method('post')
            ->with(
                'webhooks',
                $this->callback(function ($payload) {
                    $subs = $payload['webhook_subscriptions'];
                    $this->assertCount(2, $subs);
                    $this->assertEquals('tracking/updated', $subs[0]['topic']);
                    $this->assertEquals('order/updated', $subs[1]['topic']);
                    return true;
                })
            )
            ->willReturn([]);

        $this->service->subscribe();
    }

    public function testSubscribeSkipsWhenAllExist(): void
    {
        $this->apiClientMock->method('get')
            ->with('webhooks')
            ->willReturn(['webhook_subscriptions' => [
                [
                    'id' => 1,
                    'topic' => 'fulfillment/created',
                    'delivery_url' => 'https://example.com/bobgo/webhook/receive',
                ],
                [
                    'id' => 2,
                    'topic' => 'tracking/updated',
                    'delivery_url' => 'https://example.com/bobgo/webhook/receive',
                ],
                [
                    'id' => 3,
                    'topic' => 'order/updated',
                    'delivery_url' => 'https://example.com/bobgo/webhook/receive',
                ],
            ]]);

        $this->apiClientMock->expects($this->never())->method('post');

        $this->service->subscribe();
    }

    public function testSubscribeThrowsOnApiError(): void
    {
        $this->apiClientMock->method('get')
            ->with('webhooks')
            ->willReturn(['webhook_subscriptions' => []]);

        $this->apiClientMock->method('post')
            ->willThrowException(new BobGoApiException('Failed', 500));

        $this->expectException(BobGoApiException::class);
        $this->service->subscribe();
    }

    public function testUnsubscribeBatchDeletesStoreSubscriptions(): void
    {
        $this->apiClientMock->method('get')
            ->with('webhooks')
            ->willReturn(['webhook_subscriptions' => [
                [
                    'id' => 10,
                    'delivery_url' => 'https://example.com/bobgo/webhook/receive',
                    'topic' => 'fulfillment/created',
                ],
                [
                    'id' => 11,
                    'delivery_url' => 'https://example.com/bobgo/webhook/receive',
                    'topic' => 'tracking/updated',
                ],
                [
                    'id' => 99,
                    'delivery_url' => 'https://other-store.com/bobgo/webhook/receive',
                    'topic' => 'fulfillment/created',
                ],
            ]]);

        $this->apiClientMock->expects($this->once())
            ->method('delete')
            ->with('webhooks', ['ids' => [10, 11]])
            ->willReturn([]);

        $this->service->unsubscribe();
    }

    public function testUnsubscribeHandlesEmptySubscriptions(): void
    {
        $this->apiClientMock->method('get')
            ->with('webhooks')
            ->willReturn(['webhook_subscriptions' => []]);

        $this->apiClientMock->expects($this->never())->method('delete');

        $this->service->unsubscribe();
    }

    public function testUnsubscribeHandlesDeleteFailureGracefully(): void
    {
        $this->apiClientMock->method('get')
            ->with('webhooks')
            ->willReturn(['webhook_subscriptions' => [
                [
                    'id' => 10,
                    'delivery_url' => 'https://example.com/bobgo/webhook/receive',
                    'topic' => 'fulfillment/created',
                ],
            ]]);

        $this->apiClientMock->method('delete')
            ->willThrowException(new BobGoApiException('Delete failed', 500));

        // Should not throw, should log error
        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with($this->stringContains('failed to remove webhook subscriptions'), $this->anything());

        $this->service->unsubscribe();
    }

    public function testGetSubscriptionsReturnsParsedResponse(): void
    {
        $expected = [
            ['id' => 1, 'topic' => 'fulfillment/created'],
        ];

        $this->apiClientMock->method('get')
            ->with('webhooks')
            ->willReturn(['webhook_subscriptions' => $expected]);

        $result = $this->service->getSubscriptions();

        $this->assertEquals($expected, $result);
    }

    public function testGetSubscriptionsReturnsEmptyOnMissingKey(): void
    {
        $this->apiClientMock->method('get')
            ->with('webhooks')
            ->willReturn([]);

        $result = $this->service->getSubscriptions();

        $this->assertEquals([], $result);
    }

    // ----------------------------------------------- daily health check (self-heal)

    /**
     * Bob Go disables a subscription after three days of failed deliveries and
     * emails only the merchant. This check is the only way the integration ever
     * finds out, so it has to actually re-register.
     */
    public function testHealthCheckReRegistersAnInactiveSubscription(): void
    {
        $this->apiConfigMock->method('isFulfillmentSyncEnabled')->willReturn(true);
        $this->flagManagerMock->method('getFlagData')->willReturn(null);

        $this->apiClientMock->method('get')->willReturn(['webhook_subscriptions' => [
            ['id' => 1, 'delivery_url' => 'https://example.com/bobgo/webhook/receive', 'topic' => 'fulfillment/created', 'status' => 'inactive'],
            ['id' => 2, 'delivery_url' => 'https://example.com/bobgo/webhook/receive', 'topic' => 'tracking/updated', 'status' => 'active'],
            ['id' => 3, 'delivery_url' => 'https://example.com/bobgo/webhook/receive', 'topic' => 'order/updated', 'status' => 'active'],
        ]]);

        // Delete-before-create, or repairs stack duplicates forever.
        $this->apiClientMock->expects($this->once())->method('delete')
            ->with('webhooks', ['ids' => [1]]);

        $created = null;
        $this->apiClientMock->expects($this->once())->method('post')
            ->willReturnCallback(function ($endpoint, $payload) use (&$created) {
                $created = $payload;
                return [];
            });

        $this->assertTrue($this->service->verifyAndRepair());
        $this->assertSame(['fulfillment/created'], array_column($created['webhook_subscriptions'], 'topic'));
    }

    /**
     * A row with no `status` field at all counts as active. Treating an absent
     * field as inactive makes the repair churn on every single run.
     */
    public function testHealthCheckTreatsAMissingStatusFieldAsActive(): void
    {
        $this->apiConfigMock->method('isFulfillmentSyncEnabled')->willReturn(true);
        $this->flagManagerMock->method('getFlagData')->willReturn(null);

        $this->apiClientMock->method('get')->willReturn(['webhook_subscriptions' => [
            ['id' => 1, 'delivery_url' => 'https://example.com/bobgo/webhook/receive', 'topic' => 'fulfillment/created'],
            ['id' => 2, 'delivery_url' => 'https://example.com/bobgo/webhook/receive', 'topic' => 'tracking/updated'],
            ['id' => 3, 'delivery_url' => 'https://example.com/bobgo/webhook/receive', 'topic' => 'order/updated'],
        ]]);

        $this->apiClientMock->expects($this->never())->method('post');
        $this->apiClientMock->expects($this->never())->method('delete');

        $this->assertTrue($this->service->verifyAndRepair());
    }

    /**
     * 1.0.x registered a Magento_Webapi REST route. Those subscriptions 404 on
     * every delivery, and Bob Go counts that against the same three-day window
     * that disables the ones we do want.
     */
    public function testHealthCheckRemovesSubscriptionsForADeadDeliveryUrl(): void
    {
        $this->apiConfigMock->method('isFulfillmentSyncEnabled')->willReturn(true);
        $this->flagManagerMock->method('getFlagData')->willReturn(null);

        $this->apiClientMock->method('get')->willReturn(['webhook_subscriptions' => [
            ['id' => 9, 'delivery_url' => 'https://example.com/rest/V1/bobgo/webhook', 'topic' => 'fulfillment/created', 'status' => 'active'],
            ['id' => 1, 'delivery_url' => 'https://example.com/bobgo/webhook/receive', 'topic' => 'fulfillment/created', 'status' => 'active'],
            ['id' => 2, 'delivery_url' => 'https://example.com/bobgo/webhook/receive', 'topic' => 'tracking/updated', 'status' => 'active'],
            ['id' => 3, 'delivery_url' => 'https://example.com/bobgo/webhook/receive', 'topic' => 'order/updated', 'status' => 'active'],
        ]]);

        $this->apiClientMock->expects($this->once())->method('delete')
            ->with('webhooks', ['ids' => [9]]);
        $this->apiClientMock->expects($this->never())->method('post');

        $this->assertTrue($this->service->verifyAndRepair());
    }

    /**
     * A merchant who turned fulfilment sync off has deliberately disconnected.
     * Conflating that with "registration failed" is what permanently disabled
     * self-healing on the WooCommerce integration.
     */
    public function testHealthCheckRespectsADeliberateDisconnect(): void
    {
        $this->apiConfigMock->method('isFulfillmentSyncEnabled')->willReturn(false);

        $this->apiClientMock->expects($this->never())->method('get');

        $this->assertFalse($this->service->verifyAndRepair());
    }

    /**
     * A failed list must NOT stamp the flag, or one transient error costs a
     * whole day of self-healing.
     */
    public function testHealthCheckDoesNotStampTheFlagWhenInconclusive(): void
    {
        $this->apiConfigMock->method('isFulfillmentSyncEnabled')->willReturn(true);
        $this->flagManagerMock->method('getFlagData')->willReturn(null);
        $this->apiClientMock->method('get')
            ->willThrowException(new BobGoApiException('unreachable', 0, '', 'webhooks'));

        $this->flagManagerMock->expects($this->never())->method('saveFlag');

        $this->assertFalse($this->service->verifyAndRepair());
    }

    public function testHealthCheckRunsAtMostOncePerDay(): void
    {
        $this->apiConfigMock->method('isFulfillmentSyncEnabled')->willReturn(true);
        $this->flagManagerMock->method('getFlagData')->willReturn('2026-07-30 06:00:00');

        $this->apiClientMock->expects($this->never())->method('get');

        $this->assertFalse($this->service->verifyAndRepair());
    }
}
