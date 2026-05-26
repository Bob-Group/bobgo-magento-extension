<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Service;

use BobGroup\BobGo\Api\BobGoApiClient;
use BobGroup\BobGo\Api\BobGoApiException;
use BobGroup\BobGo\Model\Config\ApiConfig;
use BobGroup\BobGo\Service\WebhookSubscriptionService;
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

    protected function setUp(): void
    {
        $this->apiClientMock = $this->createMock(BobGoApiClient::class);
        $this->storeManagerMock = $this->createMock(StoreManagerInterface::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->apiConfigMock = $this->createMock(ApiConfig::class);

        // Tests assume an API key is configured unless they say otherwise.
        $this->apiConfigMock->method('isConfigured')->willReturn(true);

        $storeMock = $this->createMock(Store::class);
        $storeMock->method('getBaseUrl')
            ->with(UrlInterface::URL_TYPE_WEB)
            ->willReturn('https://example.com/');
        $this->storeManagerMock->method('getStore')->willReturn($storeMock);

        $this->service = new WebhookSubscriptionService(
            $this->apiClientMock,
            $this->storeManagerMock,
            $this->loggerMock,
            $this->apiConfigMock
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
                    $this->assertCount(2, $subs);
                    $this->assertEquals('fulfillment/created', $subs[0]['topic']);
                    $this->assertEquals('tracking/updated', $subs[1]['topic']);
                    $this->assertEquals('https://example.com/bobgo/webhook/receive', $subs[0]['delivery_url']);
                    $this->assertEquals('https://example.com/bobgo/webhook/receive', $subs[1]['delivery_url']);
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
                    $this->assertCount(1, $subs);
                    $this->assertEquals('tracking/updated', $subs[0]['topic']);
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
}
