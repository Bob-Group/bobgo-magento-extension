<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Service;

use BobGroup\BobGo\Service\FulfilmentSyncService;
use BobGroup\BobGo\Service\FulfillmentService;
use BobGroup\BobGo\Service\OrderPushService;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The webhook handlers are now thin: stamp, refresh from the API, and apply the
 * one thing that can only come from the webhook body (the human-readable
 * tracking status, and cancellation).
 *
 * What these tests are really guarding is that the handlers do NOT patch local
 * fulfilment state from the payload. That was the source of the race between
 * fulfillment/created and tracking/updated, and of the "webhook is the only path
 * that can ever ship an order" problem.
 */
class FulfillmentServiceTest extends TestCase
{
    private $orderRepository;
    private $orderManagement;
    private $fulfilmentSync;
    private $orderPushService;
    private $logger;
    /** @var FulfillmentService */
    private $service;

    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->orderManagement = $this->createMock(OrderManagementInterface::class);
        $this->fulfilmentSync = $this->createMock(FulfilmentSyncService::class);
        $this->orderPushService = $this->createMock(OrderPushService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtDate')->willReturn('2026-05-19 12:00:00');

        $this->service = new FulfillmentService(
            $this->orderRepository,
            $this->orderManagement,
            $this->fulfilmentSync,
            $this->orderPushService,
            $dateTime,
            $this->logger
        );
    }

    // ------------------------------------------------------------ fulfillment/created

    public function testFulfillmentStampsWebhookTimestampAndRefreshesFromApi(): void
    {
        $writes = [];
        $order = $this->order(42, $writes);

        $this->fulfilmentSync->expects($this->once())
            ->method('syncOrder')
            ->with($order, [['sku' => 'SKU-A', 'fulfilled_qty' => 1]]);

        $this->service->processFulfillment($order, [
            'id' => 2546,
            'method_reference' => 'TRACK001',
            'order_items' => [['sku' => 'SKU-A', 'fulfilled_qty' => 1]],
        ]);

        $this->assertSame('2026-05-19 12:00:00', $writes['bobgo_last_webhook']);
    }

    public function testFulfillmentPassesNoItemsWhenThePayloadHasNone(): void
    {
        $order = $this->order(42);

        $this->fulfilmentSync->expects($this->once())->method('syncOrder')->with($order, []);

        $this->service->processFulfillment($order, ['id' => 2546, 'method_reference' => 'TRACK001']);
    }

    // -------------------------------------------------------------- tracking/updated

    public function testTrackingUpdateRefreshesThenCommentsTheStatus(): void
    {
        $order = $this->order(42);

        $this->fulfilmentSync->expects($this->once())->method('syncOrder')->with($order);
        $order->expects($this->once())
            ->method('addCommentToStatusHistory')
            ->with('Bob Go tracking update: In Transit (ref: TRACK002)');
        // Timestamp and comment land in ONE save — every order save re-fires the
        // outbound push observer, so saving per concern made the webhook
        // needlessly expensive.
        $this->orderRepository->expects($this->once())->method('save')->with($order);

        $this->service->processTrackingUpdate($order, [
            'shipment_tracking_reference' => 'TRACK002',
            'status_friendly' => 'In Transit',
        ]);
    }

    /**
     * The refresh is the substantive work, so it must happen even when there is
     * no human-readable status to comment.
     */
    public function testTrackingUpdateStillRefreshesWithoutAStatus(): void
    {
        $order = $this->order(42);

        $this->fulfilmentSync->expects($this->once())->method('syncOrder');
        $order->expects($this->never())->method('addCommentToStatusHistory');

        $this->service->processTrackingUpdate($order, ['shipment_tracking_reference' => 'TRACK002']);
    }

    // ----------------------------------------------------------------- order/updated

    public function testOrderUpdateIgnoresNonCancellationStatuses(): void
    {
        $order = $this->order(42);

        $this->orderManagement->expects($this->never())->method('cancel');

        $this->service->processOrderUpdate($order, ['status' => 'processing']);
    }

    public function testOrderUpdateCancelsAndRebaselinesTheSyncHash(): void
    {
        $order = $this->order(42);
        $order->method('getState')->willReturn('processing');

        $this->orderManagement->expects($this->once())->method('cancel')->with(42)->willReturn(true);

        // Cancelling zeroes total_due, which flips the derived payment_status
        // from unpaid to paid and so changes the outbound payload hash. Without
        // re-baselining, the next save would PATCH that meaningless change
        // straight back to Bob Go.
        $fresh = $this->order(42);
        $this->orderRepository->method('get')->with(42)->willReturn($fresh);
        $this->orderPushService->expects($this->once())->method('refreshSyncHash')->with($fresh);

        $this->service->processOrderUpdate($order, ['status' => 'cancelled']);
    }

    public function testOrderUpdateIsIdempotentForAnAlreadyCancelledOrder(): void
    {
        $order = $this->order(42);
        $order->method('getState')->willReturn(Order::STATE_CANCELED);

        $this->orderManagement->expects($this->never())->method('cancel');

        $this->service->processOrderUpdate($order, ['status' => 'cancelled']);
    }

    /**
     * Magento refuses to cancel once anything is invoiced or shipped. We can't
     * fix that from here, but the operator has to know the two systems now
     * disagree.
     */
    public function testOrderUpdateWarnsWhenMagentoRefusesToCancel(): void
    {
        $order = $this->order(42);
        $order->method('getState')->willReturn('complete');

        $this->orderManagement->method('cancel')->willReturn(false);
        $this->logger->expects($this->once())->method('warning');
        $this->orderPushService->expects($this->never())->method('refreshSyncHash');

        $this->service->processOrderUpdate($order, ['status' => 'cancelled']);
    }

    public function testOrderUpdateAcceptsTheAmericanSpelling(): void
    {
        $order = $this->order(42);
        $order->method('getState')->willReturn('processing');

        $this->orderManagement->expects($this->once())->method('cancel')->willReturn(true);
        $this->orderRepository->method('get')->willReturn($this->order(42));

        $this->service->processOrderUpdate($order, ['status' => 'canceled']);
    }

    // ----------------------------------------------------------------------- helpers

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    private function order(int $entityId, ?array &$writes = null)
    {
        $order = $this->createMock(Order::class);
        $order->method('getEntityId')->willReturn($entityId);
        $order->method('getIncrementId')->willReturn('000000' . $entityId);
        $order->method('setData')->willReturnCallback(function ($k, $v = null) use (&$writes) {
            if ($writes !== null) {
                $writes[$k] = $v;
            }
            return null;
        });
        return $order;
    }
}
