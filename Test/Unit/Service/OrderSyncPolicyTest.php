<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Service;

use BobGroup\BobGo\Service\OrderSyncPolicy;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;

/**
 * Which orders Bob Go hears about.
 *
 * The extension used to push every order on every save, which meant abandoned
 * card attempts and virtual orders too. A virtual order has no shipping address,
 * so OrderMapper emits delivery_address: null, the API rejects it, and the order
 * is marked failed and retried on every subsequent save — forever.
 */
class OrderSyncPolicyTest extends TestCase
{
    /** @var OrderSyncPolicy */
    private $policy;

    protected function setUp(): void
    {
        $this->policy = new OrderSyncPolicy();
    }

    public function testVirtualOrdersAreNeverPushed(): void
    {
        // Even a linked one: there is no shipping address, so OrderMapper emits
        // delivery_address: null and the API rejects it on every save.
        $order = $this->order(Order::STATE_PROCESSING, ['bobgo_order_id' => '987'], true);

        $this->assertFalse($this->policy->shouldPush($order));
    }

    /**
     * @dataProvider creatableStateProvider
     */
    public function testUnlinkedOrdersArePushedOnlyInCreatableStates(string $state, bool $expected): void
    {
        $this->assertSame($expected, $this->policy->shouldPush($this->order($state)));
    }

    /**
     * @return array<string,array{0:string,1:bool}>
     */
    public function creatableStateProvider(): array
    {
        return [
            'new' => [Order::STATE_NEW, true],
            'processing' => [Order::STATE_PROCESSING, true],
            'holded' => [Order::STATE_HOLDED, true],
            'complete' => [Order::STATE_COMPLETE, true],
            // The sale isn't real yet — pushing every abandoned card attempt
            // fills the merchant's Bob Go account with orders that never ship.
            'pending_payment' => ['pending_payment', false],
            'payment_review' => ['payment_review', false],
            // Nothing to fulfil, so don't create it just to cancel it.
            'canceled' => [Order::STATE_CANCELED, false],
            'closed' => [Order::STATE_CLOSED, false],
        ];
    }

    /**
     * Once an order exists on Bob Go, Bob Go needs to hear about later changes
     * whatever state the order reaches — including its cancellation.
     */
    public function testLinkedOrdersArePushedInAnyState(): void
    {
        $order = $this->order(Order::STATE_CANCELED, ['bobgo_order_id' => '987']);

        $this->assertTrue($this->policy->shouldPush($order));
    }

    // ------------------------------------------------------------ status forwarding

    public function testForwardsCancellation(): void
    {
        $order = $this->order(Order::STATE_CANCELED, ['bobgo_order_id' => '987']);

        $this->assertSame('cancelled', $this->policy->statusToForward($order));
    }

    public function testForwardsCompletion(): void
    {
        $order = $this->order(Order::STATE_COMPLETE, ['bobgo_order_id' => '987']);

        $this->assertSame('completed', $this->policy->statusToForward($order));
    }

    public function testDoesNotForwardAStatusAlreadySent(): void
    {
        $order = $this->order(Order::STATE_COMPLETE, [
            'bobgo_order_id' => '987',
            'bobgo_status_synced' => 'completed',
        ]);

        $this->assertNull($this->policy->statusToForward($order));
    }

    /**
     * Completing an already-cancelled order is a 400 on Bob Go's side, so the
     * transition still has to be sent even though a repeat of the same status
     * would be a no-op.
     */
    public function testForwardsAStatusThatDiffersFromTheOneAlreadySent(): void
    {
        $order = $this->order(Order::STATE_CANCELED, [
            'bobgo_order_id' => '987',
            'bobgo_status_synced' => 'completed',
        ]);

        $this->assertSame('cancelled', $this->policy->statusToForward($order));
    }

    public function testNoStatusToForwardForIntermediateStates(): void
    {
        $order = $this->order(Order::STATE_PROCESSING, ['bobgo_order_id' => '987']);

        $this->assertNull($this->policy->statusToForward($order));
    }

    public function testNoStatusToForwardWithoutALink(): void
    {
        $this->assertNull($this->policy->statusToForward($this->order(Order::STATE_COMPLETE)));
    }

    /**
     * @param array<string,mixed> $data
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    private function order(string $state, array $data = [], bool $isVirtual = false)
    {
        $order = $this->createMock(Order::class);
        $order->method('getState')->willReturn($state);
        $order->method('getIsVirtual')->willReturn($isVirtual);
        $order->method('getData')->willReturnCallback(static function ($key = null) use ($data) {
            return $key === null ? $data : ($data[$key] ?? null);
        });
        return $order;
    }
}
