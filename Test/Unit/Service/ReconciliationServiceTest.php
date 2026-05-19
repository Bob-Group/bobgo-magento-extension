<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Service;

use BobGroup\BobGo\Api\BobGoApiClient;
use BobGroup\BobGo\Api\BobGoApiException;
use BobGroup\BobGo\Model\Config\ApiConfig;
use BobGroup\BobGo\Service\ReconciliationService;
use BobGroup\BobGo\Service\SyncLogger;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ReconciliationServiceTest extends TestCase
{
    private $orderRepositoryMock;
    private $searchCriteriaBuilderMock;
    private $apiClientMock;
    private $apiConfigMock;
    private $syncLoggerMock;
    private $loggerMock;
    private $dateTimeMock;
    /** @var ReconciliationService */
    private $service;

    protected function setUp(): void
    {
        $this->orderRepositoryMock = $this->createMock(OrderRepositoryInterface::class);
        $this->searchCriteriaBuilderMock = $this->createMock(SearchCriteriaBuilder::class);
        $this->apiClientMock = $this->createMock(BobGoApiClient::class);
        $this->apiConfigMock = $this->createMock(ApiConfig::class);
        $this->syncLoggerMock = $this->createMock(SyncLogger::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->dateTimeMock = $this->createMock(DateTime::class);
        $this->dateTimeMock->method('gmtDate')->willReturn('2026-05-19 12:00:00');

        $this->searchCriteriaBuilderMock->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilderMock->method('setPageSize')->willReturnSelf();
        $this->searchCriteriaBuilderMock->method('create')
            ->willReturn($this->createMock(SearchCriteria::class));

        $this->service = new ReconciliationService(
            $this->orderRepositoryMock,
            $this->searchCriteriaBuilderMock,
            $this->apiClientMock,
            $this->apiConfigMock,
            $this->syncLoggerMock,
            $this->loggerMock,
            $this->dateTimeMock
        );
    }

    public function testRunSkipsWhenFulfillmentSyncDisabled(): void
    {
        $this->apiConfigMock->method('isFulfillmentSyncEnabled')->willReturn(false);
        $this->apiConfigMock->expects($this->never())->method('isConfigured');
        $this->orderRepositoryMock->expects($this->never())->method('getList');

        $this->service->run();
    }

    public function testRunSkipsWhenNotConfigured(): void
    {
        $this->apiConfigMock->method('isFulfillmentSyncEnabled')->willReturn(true);
        $this->apiConfigMock->method('isConfigured')->willReturn(false);
        $this->orderRepositoryMock->expects($this->never())->method('getList');

        $this->service->run();
    }

    public function testRunIteratesCandidateOrders(): void
    {
        $this->apiConfigMock->method('isFulfillmentSyncEnabled')->willReturn(true);
        $this->apiConfigMock->method('isConfigured')->willReturn(true);

        $order = $this->makeOrder(42, 'bobgo_ord_xyz', '');
        $this->stubOrderList([$order]);

        $this->apiClientMock->expects($this->once())
            ->method('get')
            ->with('order-fulfillments', ['order_id' => 'bobgo_ord_xyz'])
            ->willReturn(['order_fulfillments' => [['tracking_number' => 'T1']]]);

        $this->orderRepositoryMock->expects($this->once())->method('save')->with($order);
        $this->syncLoggerMock->expects($this->once())->method('logOutbound');

        $this->service->run();
    }

    public function testReconcileOrderPersistsShipmentsWhenChanged(): void
    {
        $writes = [];
        $order = $this->makeOrder(7, 'bobgo_ord_a', '[]', $writes);

        $this->apiClientMock->method('get')
            ->willReturn(['order_fulfillments' => [['tracking_number' => 'NEW']]]);

        $this->orderRepositoryMock->expects($this->once())->method('save');
        $this->service->reconcileOrder($order);

        $this->assertArrayHasKey('bobgo_shipments', $writes);
        $this->assertStringContainsString('NEW', (string) $writes['bobgo_shipments']);
        $this->assertSame('2026-05-19 12:00:00', $writes['bobgo_last_synced']);
    }

    public function testReconcileOrderIsNoOpWhenShipmentsUnchanged(): void
    {
        $existing = json_encode([['tracking_number' => 'SAME']]);
        $order = $this->makeOrder(7, 'bobgo_ord_a', $existing);

        $this->apiClientMock->method('get')
            ->willReturn(['order_fulfillments' => [['tracking_number' => 'SAME']]]);

        // Nothing changed → no write to the repository.
        $this->orderRepositoryMock->expects($this->never())->method('save');
        $this->syncLoggerMock->expects($this->once())->method('logOutbound');

        $this->service->reconcileOrder($order);
    }

    public function testReconcileOrderSkipsWhenBobgoOrderIdMissing(): void
    {
        $order = $this->makeOrder(7, '', '');

        $this->apiClientMock->expects($this->never())->method('get');
        $this->orderRepositoryMock->expects($this->never())->method('save');

        $this->service->reconcileOrder($order);
    }

    public function testReconcileOrderHandlesApiError(): void
    {
        $order = $this->makeOrder(7, 'bobgo_ord_a', '');

        $this->apiClientMock->method('get')
            ->willThrowException(new BobGoApiException('boom', 500, '', 'order-fulfillments'));

        $this->loggerMock->expects($this->once())->method('warning');
        $this->syncLoggerMock->expects($this->once())->method('logOutbound')
            ->with($this->anything(), $this->anything(), $this->anything(), 500, false);

        $this->service->reconcileOrder($order);
    }

    public function testExtractShipmentsHandlesPlainArrayResponse(): void
    {
        $this->apiConfigMock->method('isFulfillmentSyncEnabled')->willReturn(true);
        $this->apiConfigMock->method('isConfigured')->willReturn(true);

        $order = $this->makeOrder(42, 'bobgo_ord_xyz', '');
        $this->stubOrderList([$order]);

        // Response is itself the shipments list (no wrapper key).
        $this->apiClientMock->method('get')
            ->willReturn([['tracking_number' => 'A'], ['tracking_number' => 'B']]);

        $this->orderRepositoryMock->expects($this->once())->method('save');
        $this->service->run();
    }

    // --- helpers ---------------------------------------------------------

    private function stubOrderList(array $orders): void
    {
        $searchResult = $this->createMock(OrderSearchResultInterface::class);
        $searchResult->method('getItems')->willReturn($orders);
        $this->orderRepositoryMock->method('getList')->willReturn($searchResult);
    }

    private function makeOrder(int $entityId, string $bobgoOrderId, string $bobgoShipments, ?array &$writes = null): OrderInterface
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn($entityId);

        $store = [
            'bobgo_order_id'  => $bobgoOrderId,
            'bobgo_shipments' => $bobgoShipments,
        ];
        $order->method('getData')->willReturnCallback(function ($key = null) use (&$store) {
            return $store[$key] ?? null;
        });
        $order->method('setData')->willReturnCallback(function ($k, $v = null) use (&$store, &$writes) {
            $store[$k] = $v;
            if ($writes !== null) {
                $writes[$k] = $v;
            }
            return null;
        });
        return $order;
    }
}
