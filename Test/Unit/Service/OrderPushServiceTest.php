<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Service;

use BobGroup\BobGo\Api\BobGoApiClient;
use BobGroup\BobGo\Api\OrderMapperInterface;
use BobGroup\BobGo\Service\OrderPushService;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OrderPushServiceTest extends TestCase
{
    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $apiClientMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $orderMapperMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $loggerMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $orderRepositoryMock;

    /**
     * @var OrderPushService
     */
    private $service;

    protected function setUp(): void
    {
        $this->apiClientMock = $this->createMock(BobGoApiClient::class);
        $this->orderMapperMock = $this->createMock(OrderMapperInterface::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->orderRepositoryMock = $this->createMock(OrderRepositoryInterface::class);

        $this->service = new OrderPushService(
            $this->apiClientMock,
            $this->orderMapperMock,
            $this->loggerMock,
            $this->orderRepositoryMock
        );
    }

    public function testPushOrderCallsPostAndSavesBobGoId(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(100);
        $order->method('getIncrementId')->willReturn('000000100');

        $payload = ['ChannelRefID' => '100'];
        $this->orderMapperMock->method('mapOrderToPayload')
            ->with($order)
            ->willReturn($payload);

        $this->apiClientMock->expects($this->once())
            ->method('post')
            ->with('orders', $payload)
            ->willReturn(['id' => 'bg-order-abc-123']);

        $order->expects($this->once())
            ->method('setData')
            ->with('bobgo_order_id', 'bg-order-abc-123');

        $this->orderRepositoryMock->expects($this->once())
            ->method('save')
            ->with($order);

        $this->loggerMock->expects($this->once())
            ->method('info');

        $this->service->pushOrder($order);
    }

    public function testPushOrderHandlesApiError(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(100);
        $order->method('getIncrementId')->willReturn('000000100');

        $this->orderMapperMock->method('mapOrderToPayload')
            ->willReturn([]);

        $this->apiClientMock->method('post')
            ->willThrowException(new \Exception('API connection failed'));

        $this->loggerMock->expects($this->once())
            ->method('error');

        // Should not throw
        $this->service->pushOrder($order);
    }

    public function testUpdateOrderCallsPatch(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(100);
        $order->method('getIncrementId')->willReturn('000000100');
        $order->method('getData')
            ->with('bobgo_order_id')
            ->willReturn('bg-order-abc-123');

        $payload = ['id' => 'bg-order-abc-123', 'ChannelRefID' => '100'];
        $this->orderMapperMock->method('mapOrderToUpdatePayload')
            ->with($order)
            ->willReturn($payload);

        $this->apiClientMock->expects($this->once())
            ->method('patch')
            ->with('orders', $payload)
            ->willReturn([]);

        $this->loggerMock->expects($this->once())
            ->method('info');

        $this->service->updateOrder($order);
    }

    public function testUpdateOrderHandlesApiError(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(100);
        $order->method('getIncrementId')->willReturn('000000100');
        $order->method('getData')
            ->with('bobgo_order_id')
            ->willReturn('bg-order-abc-123');

        $this->orderMapperMock->method('mapOrderToUpdatePayload')
            ->willReturn([]);

        $this->apiClientMock->method('patch')
            ->willThrowException(new \Exception('API timeout'));

        $this->loggerMock->expects($this->once())
            ->method('error');

        // Should not throw
        $this->service->updateOrder($order);
    }
}
