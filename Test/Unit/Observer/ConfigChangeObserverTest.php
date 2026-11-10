<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Observer;

use BobGroup\BobGo\Api\BobGoApiClient;
use BobGroup\BobGo\Api\BobGoApiException;
use BobGroup\BobGo\Model\Config\ApiConfig;
use BobGroup\BobGo\Observer\ConfigChangeObserver;
use BobGroup\BobGo\Service\WebhookSubscriptionService;
use Magento\Framework\Event\Observer;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\Message\ManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ConfigChangeObserverTest extends TestCase
{
    /** @var ConfigChangeObserver */
    private $observer;

    /** @var BobGoApiClient|\PHPUnit\Framework\MockObject\MockObject */
    private $apiClientMock;

    /** @var ApiConfig|\PHPUnit\Framework\MockObject\MockObject */
    private $apiConfigMock;

    /** @var WebhookSubscriptionService|\PHPUnit\Framework\MockObject\MockObject */
    private $webhookServiceMock;

    /** @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $loggerMock;

    /** @var ManagerInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $messageManagerMock;

    /** @var ReinitableConfigInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $reinitableConfigMock;

    protected function setUp(): void
    {
        $this->apiClientMock = $this->createMock(BobGoApiClient::class);
        $this->apiConfigMock = $this->createMock(ApiConfig::class);
        $this->webhookServiceMock = $this->createMock(WebhookSubscriptionService::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->messageManagerMock = $this->createMock(ManagerInterface::class);
        $this->reinitableConfigMock = $this->createMock(ReinitableConfigInterface::class);

        $this->observer = new ConfigChangeObserver(
            $this->apiClientMock,
            $this->apiConfigMock,
            $this->webhookServiceMock,
            $this->loggerMock,
            $this->messageManagerMock,
            $this->reinitableConfigMock
        );
    }

    /**
     * Create an Observer mock with an Event that returns given changed_paths.
     */
    private function createObserverWithChangedPaths(?array $changedPaths): Observer
    {
        $eventMock = $this->createMock(\Magento\Framework\Event::class);
        $eventMock->method('getData')
            ->with('changed_paths')
            ->willReturn($changedPaths);

        $observerMock = $this->createMock(Observer::class);
        $observerMock->method('getEvent')->willReturn($eventMock);

        return $observerMock;
    }

    public function testApiKeyChangeTestsConnectivity(): void
    {
        $observerMock = $this->createObserverWithChangedPaths(['carriers/bobgo/api_key']);

        $this->apiConfigMock->method('isConfigured')->willReturn(true);
        $this->apiConfigMock->method('getEnvironment')->willReturn('sandbox');
        $this->apiConfigMock->method('isFulfillmentSyncEnabled')->willReturn(false);

        $this->apiClientMock->expects($this->once())
            ->method('get')
            ->with('webhooks')
            ->willReturn([]);

        $this->messageManagerMock->expects($this->once())
            ->method('addSuccessMessage');

        $this->observer->execute($observerMock);
    }

    public function testApiKeyChangeConnectivityFailure(): void
    {
        $observerMock = $this->createObserverWithChangedPaths(['carriers/bobgo/api_key']);

        $this->apiConfigMock->method('isConfigured')->willReturn(true);
        $this->apiConfigMock->method('isFulfillmentSyncEnabled')->willReturn(false);

        $this->apiClientMock->method('get')
            ->willThrowException(new BobGoApiException('Unauthorized', 401));

        $this->messageManagerMock->expects($this->once())
            ->method('addErrorMessage');

        $this->observer->execute($observerMock);
    }

    public function testActiveToggleTestsRac(): void
    {
        $observerMock = $this->createObserverWithChangedPaths(['carriers/bobgo/active']);

        $this->apiConfigMock->method('isActive')->willReturn(true);
        $this->apiConfigMock->method('isConfigured')->willReturn(true);
        $this->apiConfigMock->method('isFulfillmentSyncEnabled')->willReturn(false);

        $this->apiClientMock->method('post')
            ->with('rates-at-checkout', $this->anything())
            ->willReturn(['rates' => [['id' => 'test']]]);

        $this->messageManagerMock->expects($this->once())
            ->method('addSuccessMessage');

        $this->observer->execute($observerMock);
    }

    public function testActiveToggleNoApiKey(): void
    {
        $observerMock = $this->createObserverWithChangedPaths(['carriers/bobgo/active']);

        $this->apiConfigMock->method('isActive')->willReturn(true);
        $this->apiConfigMock->method('isConfigured')->willReturn(false);
        $this->apiConfigMock->method('isFulfillmentSyncEnabled')->willReturn(false);

        $this->messageManagerMock->expects($this->once())
            ->method('addErrorMessage');

        $this->observer->execute($observerMock);
    }

    public function testFulfillmentSyncEnableSubscribesWebhooks(): void
    {
        $observerMock = $this->createObserverWithChangedPaths(['carriers/bobgo/enable_fulfillment_sync']);

        $this->apiConfigMock->method('isConfigured')->willReturn(true);
        $this->apiConfigMock->method('isFulfillmentSyncEnabled')->willReturn(true);

        $this->webhookServiceMock->expects($this->once())
            ->method('subscribe');

        $this->messageManagerMock->expects($this->once())
            ->method('addSuccessMessage');

        $this->observer->execute($observerMock);
    }

    public function testFulfillmentSyncDisableUnsubscribesWebhooks(): void
    {
        $observerMock = $this->createObserverWithChangedPaths(['carriers/bobgo/enable_fulfillment_sync']);

        $this->apiConfigMock->method('isConfigured')->willReturn(true);
        $this->apiConfigMock->method('isFulfillmentSyncEnabled')->willReturn(false);

        $this->webhookServiceMock->expects($this->once())
            ->method('unsubscribe');

        $this->observer->execute($observerMock);
    }

    public function testNoChangedPathsDoesNothing(): void
    {
        $observerMock = $this->createObserverWithChangedPaths(null);

        $this->apiClientMock->expects($this->never())->method('get');
        $this->apiClientMock->expects($this->never())->method('post');
        $this->webhookServiceMock->expects($this->never())->method('subscribe');
        $this->webhookServiceMock->expects($this->never())->method('unsubscribe');

        $this->observer->execute($observerMock);
    }
}
