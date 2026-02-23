<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Service;

use BobGroup\BobGo\Api\BobGoApiClient;
use BobGroup\BobGo\Api\BobGoApiException;
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

    protected function setUp(): void
    {
        $this->apiClientMock = $this->createMock(BobGoApiClient::class);
        $this->storeManagerMock = $this->createMock(StoreManagerInterface::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);

        $storeMock = $this->createMock(Store::class);
        $storeMock->method('getBaseUrl')
            ->with(UrlInterface::URL_TYPE_WEB)
            ->willReturn('https://example.com/');
        $this->storeManagerMock->method('getStore')->willReturn($storeMock);

        $this->service = new WebhookSubscriptionService(
            $this->apiClientMock,
            $this->storeManagerMock,
            $this->loggerMock
        );
    }

    public function testSubscribePostsWebhookSubscriptions(): void
    {
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
                    $this->assertEquals('https://example.com/rest/V1/bobgo/webhook', $subs[0]['delivery_url']);
                    $this->assertEquals('active', $subs[0]['status']);
                    return true;
                })
            )
            ->willReturn([]);

        $this->service->subscribe();
    }

    public function testSubscribeThrowsOnApiError(): void
    {
        $this->apiClientMock->method('post')
            ->willThrowException(new BobGoApiException('Failed', 500));

        $this->expectException(BobGoApiException::class);
        $this->service->subscribe();
    }

    public function testUnsubscribeDeletesEachSubscription(): void
    {
        $this->apiClientMock->method('get')
            ->with('webhooks')
            ->willReturn([
                ['id' => 'sub-1'],
                ['id' => 'sub-2'],
            ]);

        $this->apiClientMock->expects($this->exactly(2))
            ->method('delete')
            ->willReturnCallback(function ($endpoint) {
                $this->assertContains($endpoint, ['webhooks/sub-1', 'webhooks/sub-2']);
                return [];
            });

        $this->service->unsubscribe();
    }

    public function testUnsubscribeHandlesEmptySubscriptions(): void
    {
        $this->apiClientMock->method('get')
            ->with('webhooks')
            ->willReturn([]);

        $this->apiClientMock->expects($this->never())->method('delete');

        $this->service->unsubscribe();
    }

    public function testUnsubscribeSkipsSubscriptionsWithoutId(): void
    {
        $this->apiClientMock->method('get')
            ->with('webhooks')
            ->willReturn([
                ['topic' => 'fulfillment/created'],
                ['id' => 'sub-1'],
            ]);

        $this->apiClientMock->expects($this->once())
            ->method('delete')
            ->with('webhooks/sub-1')
            ->willReturn([]);

        $this->service->unsubscribe();
    }

    public function testUnsubscribeHandlesDeleteFailureGracefully(): void
    {
        $this->apiClientMock->method('get')
            ->with('webhooks')
            ->willReturn([
                ['id' => 'sub-1'],
                ['id' => 'sub-2'],
            ]);

        $callCount = 0;
        $this->apiClientMock->method('delete')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    throw new BobGoApiException('Delete failed', 500);
                }
                return [];
            });

        // Should not throw, should log error and continue
        $this->service->unsubscribe();
    }

    public function testGetSubscriptionsReturnsApiResponse(): void
    {
        $expected = [
            ['id' => 'sub-1', 'topic' => 'fulfillment/created'],
        ];

        $this->apiClientMock->method('get')
            ->with('webhooks')
            ->willReturn($expected);

        $result = $this->service->getSubscriptions();

        $this->assertEquals($expected, $result);
    }
}
