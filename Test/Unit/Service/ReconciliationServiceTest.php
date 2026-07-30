<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Service;

use BobGroup\BobGo\Model\Config\ApiConfig;
use BobGroup\BobGo\Service\FulfilmentSyncService;
use BobGroup\BobGo\Service\ReconciliationService;
use BobGroup\BobGo\Service\WebhookSubscriptionService;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Reconciliation now owns only the batch — which orders to look at and how many.
 * The per-order work is FulfilmentSyncService, deliberately the same code path
 * the webhook handlers use, which is what makes this a genuine safety net rather
 * than a display refresh.
 */
class ReconciliationServiceTest extends TestCase
{
    private $orderRepositoryMock;
    private $searchCriteriaBuilderMock;
    private $fulfilmentSyncMock;
    private $apiConfigMock;
    private $webhookSubscriptionsMock;
    private $loggerMock;
    /** @var ReconciliationService */
    private $service;

    protected function setUp(): void
    {
        $this->orderRepositoryMock = $this->createMock(OrderRepositoryInterface::class);
        $this->searchCriteriaBuilderMock = $this->createMock(SearchCriteriaBuilder::class);
        $this->fulfilmentSyncMock = $this->createMock(FulfilmentSyncService::class);
        $this->apiConfigMock = $this->createMock(ApiConfig::class);
        $this->webhookSubscriptionsMock = $this->createMock(WebhookSubscriptionService::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);

        $this->searchCriteriaBuilderMock->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilderMock->method('setPageSize')->willReturnSelf();
        $this->searchCriteriaBuilderMock->method('create')
            ->willReturn($this->createMock(SearchCriteria::class));

        $this->service = new ReconciliationService(
            $this->orderRepositoryMock,
            $this->searchCriteriaBuilderMock,
            $this->fulfilmentSyncMock,
            $this->apiConfigMock,
            $this->webhookSubscriptionsMock,
            $this->loggerMock
        );
    }

    public function testRunSkipsWhenFulfillmentSyncDisabled(): void
    {
        $this->apiConfigMock->method('isFulfillmentSyncEnabled')->willReturn(false);
        $this->apiConfigMock->expects($this->never())->method('isConfigured');
        $this->orderRepositoryMock->expects($this->never())->method('getList');
        $this->webhookSubscriptionsMock->expects($this->never())->method('verifyAndRepair');

        $this->service->run();
    }

    public function testRunSkipsWhenNotConfigured(): void
    {
        $this->apiConfigMock->method('isFulfillmentSyncEnabled')->willReturn(true);
        $this->apiConfigMock->method('isConfigured')->willReturn(false);
        $this->orderRepositoryMock->expects($this->never())->method('getList');

        $this->service->run();
    }

    /**
     * The subscription health check rides along on this job. It is the only way
     * we ever discover that Bob Go disabled our subscription after three days of
     * failed deliveries — nothing notifies the integration.
     */
    public function testRunPerformsTheSubscriptionHealthCheck(): void
    {
        $this->enable();
        $this->stubOrderList([]);

        $this->webhookSubscriptionsMock->expects($this->once())->method('verifyAndRepair');

        $this->service->run();
    }

    public function testRunSyncsEachCandidateOrder(): void
    {
        $this->enable();
        $orders = [$this->order(41), $this->order(42)];
        $this->stubOrderList($orders);

        $this->fulfilmentSyncMock->expects($this->exactly(2))->method('syncOrder');

        $this->service->run();
    }

    public function testCandidateOrdersAreDedupedAcrossTheTwoQueries(): void
    {
        $this->enable();
        // Same order returned by both the active-state and complete-lookback
        // queries; it must be synced once, not twice.
        $order = $this->order(42);
        $this->stubOrderList([$order]);

        $this->fulfilmentSyncMock->expects($this->once())->method('syncOrder');

        $this->service->run();
    }

    /**
     * One bad order must not end the batch.
     */
    public function testReconcileOrderIsolatesFailures(): void
    {
        $order = $this->order(42);
        $this->fulfilmentSyncMock->method('syncOrder')
            ->willThrowException(new \RuntimeException('shipment creation blew up'));

        $this->loggerMock->expects($this->once())->method('error');

        // Must not propagate.
        $this->service->reconcileOrder($order);
    }

    // --- helpers ---------------------------------------------------------

    private function enable(): void
    {
        $this->apiConfigMock->method('isFulfillmentSyncEnabled')->willReturn(true);
        $this->apiConfigMock->method('isConfigured')->willReturn(true);
    }

    /**
     * @param array<int,OrderInterface> $orders
     */
    private function stubOrderList(array $orders): void
    {
        $searchResult = $this->createMock(OrderSearchResultInterface::class);
        $searchResult->method('getItems')->willReturn($orders);
        $this->orderRepositoryMock->method('getList')->willReturn($searchResult);
    }

    /**
     * @return OrderInterface
     */
    private function order(int $entityId): OrderInterface
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn($entityId);
        return $order;
    }
}
