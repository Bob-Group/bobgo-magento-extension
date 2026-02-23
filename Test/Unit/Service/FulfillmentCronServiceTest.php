<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Service;

use BobGroup\BobGo\Api\BobGoApiClient;
use BobGroup\BobGo\Api\BobGoApiException;
use BobGroup\BobGo\Model\Config\ApiConfig;
use BobGroup\BobGo\Service\FulfillmentCronService;
use BobGroup\BobGo\Service\FulfillmentService;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FulfillmentCronServiceTest extends TestCase
{
    /**
     * @var FulfillmentCronService
     */
    private $service;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $apiClientMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $fulfillmentServiceMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $orderRepositoryMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $searchCriteriaBuilderMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $apiConfigMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $loggerMock;

    protected function setUp(): void
    {
        $this->apiClientMock = $this->createMock(BobGoApiClient::class);
        $this->fulfillmentServiceMock = $this->createMock(FulfillmentService::class);
        $this->orderRepositoryMock = $this->createMock(OrderRepositoryInterface::class);
        $this->searchCriteriaBuilderMock = $this->createMock(SearchCriteriaBuilder::class);
        $this->apiConfigMock = $this->createMock(ApiConfig::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);

        $this->service = new FulfillmentCronService(
            $this->apiClientMock,
            $this->fulfillmentServiceMock,
            $this->orderRepositoryMock,
            $this->searchCriteriaBuilderMock,
            $this->apiConfigMock,
            $this->loggerMock
        );
    }

    public function testExecuteSkipsWhenDisabled(): void
    {
        $this->apiConfigMock->method('isFulfillmentSyncEnabled')->willReturn(false);

        // Should not check configuration or query orders
        $this->apiConfigMock->expects($this->never())->method('isConfigured');
        $this->orderRepositoryMock->expects($this->never())->method('getList');

        $this->service->execute();
    }

    public function testExecuteSkipsWhenNotConfigured(): void
    {
        $this->apiConfigMock->method('isFulfillmentSyncEnabled')->willReturn(true);
        $this->apiConfigMock->method('isConfigured')->willReturn(false);

        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with('Bob Go fulfillment cron: API key not configured');

        // Should not query orders
        $this->orderRepositoryMock->expects($this->never())->method('getList');

        $this->service->execute();
    }

    public function testExecuteProcessesOrders(): void
    {
        $this->apiConfigMock->method('isFulfillmentSyncEnabled')->willReturn(true);
        $this->apiConfigMock->method('isConfigured')->willReturn(true);

        // Set up search criteria builder chain
        $searchCriteriaMock = $this->createMock(SearchCriteria::class);
        $this->searchCriteriaBuilderMock->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilderMock->method('create')->willReturn($searchCriteriaMock);

        // Set up order with bobgo_order_id
        $orderMock = $this->createMock(OrderInterface::class);
        $orderMock->method('getEntityId')->willReturn(42);
        $orderMock->method('getData')
            ->with('bobgo_order_id')
            ->willReturn('bobgo_ord_123');

        $searchResultMock = $this->createMock(OrderSearchResultInterface::class);
        $searchResultMock->method('getItems')->willReturn([$orderMock]);
        $this->orderRepositoryMock->method('getList')->willReturn($searchResultMock);

        // API returns fulfillment data
        $fulfillmentData = [
            [
                'fulfillment_id' => 'ful_1',
                'channel_ref_id' => '42',
                'tracking_numbers' => [['number' => 'TRACK001', 'carrier' => 'Bob Go']],
                'line_items' => [],
            ],
        ];

        $this->apiClientMock->expects($this->once())
            ->method('get')
            ->with('order-fulfillments', ['order_id' => 'bobgo_ord_123'])
            ->willReturn($fulfillmentData);

        // Expect fulfillment to be processed
        $this->fulfillmentServiceMock->expects($this->once())
            ->method('processFulfillment')
            ->with($fulfillmentData[0]);

        $this->service->execute();
    }

    public function testExecuteHandlesApiError(): void
    {
        $this->apiConfigMock->method('isFulfillmentSyncEnabled')->willReturn(true);
        $this->apiConfigMock->method('isConfigured')->willReturn(true);

        // Set up search criteria builder chain
        $searchCriteriaMock = $this->createMock(SearchCriteria::class);
        $this->searchCriteriaBuilderMock->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilderMock->method('create')->willReturn($searchCriteriaMock);

        // Set up order
        $orderMock = $this->createMock(OrderInterface::class);
        $orderMock->method('getEntityId')->willReturn(42);
        $orderMock->method('getData')
            ->with('bobgo_order_id')
            ->willReturn('bobgo_ord_123');

        $searchResultMock = $this->createMock(OrderSearchResultInterface::class);
        $searchResultMock->method('getItems')->willReturn([$orderMock]);
        $this->orderRepositoryMock->method('getList')->willReturn($searchResultMock);

        // API throws exception
        $apiException = new BobGoApiException('Connection timeout', 500, '', 'order-fulfillments');
        $this->apiClientMock->expects($this->once())
            ->method('get')
            ->willThrowException($apiException);

        // Should log the error
        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with(
                'Bob Go fulfillment cron: API error for order',
                $this->callback(function ($context) {
                    return $context['order_id'] === 42
                        && $context['bobgo_order_id'] === 'bobgo_ord_123'
                        && $context['status_code'] === 500;
                })
            );

        // Should not throw - exception is caught
        $this->service->execute();
    }
}
