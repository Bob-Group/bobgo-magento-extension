<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Observer;

use BobGroup\BobGo\Observer\ModifyShippingDescription;
use Magento\Framework\Event\Observer;
use Magento\Sales\Api\Data\OrderInterface;
use PHPUnit\Framework\TestCase;

class ModifyShippingDescriptionTest extends TestCase
{
    private ModifyShippingDescription $observer;

    protected function setUp(): void
    {
        $this->observer = new ModifyShippingDescription();
    }

    public function testStripsBobgoDescription(): void
    {
        $order = $this->getMockBuilder(OrderInterface::class)
            ->addMethods(['setShippingDescription'])
            ->getMockForAbstractClass();
        $order->method('getShippingMethod')->willReturn('bobgo_standard');
        $order->method('getShippingDescription')
            ->willReturn('Bob Go - Delivery in 3 - 5 days - Standard Delivery');

        $order->expects($this->once())
            ->method('setShippingDescription')
            ->with('Standard Delivery');

        $this->observer->execute($this->wrap($order));
    }

    public function testLeavesNonBobgoCarrierAlone(): void
    {
        $order = $this->getMockBuilder(OrderInterface::class)
            ->addMethods(['setShippingDescription'])
            ->getMockForAbstractClass();
        $order->method('getShippingMethod')->willReturn('dhl_express');
        $order->method('getShippingDescription')
            ->willReturn('DHL - Same Day - Express Premium');

        // Setting the description on a non-bobgo carrier would corrupt it —
        // the observer must skip without touching the order.
        $order->expects($this->never())->method('setShippingDescription');

        $this->observer->execute($this->wrap($order));
    }

    public function testHandlesNullShippingDescription(): void
    {
        $order = $this->getMockBuilder(OrderInterface::class)
            ->addMethods(['setShippingDescription'])
            ->getMockForAbstractClass();
        $order->method('getShippingMethod')->willReturn('bobgo_standard');
        $order->method('getShippingDescription')->willReturn(null);

        $order->expects($this->never())->method('setShippingDescription');

        $this->observer->execute($this->wrap($order));
    }

    public function testHandlesNullShippingMethod(): void
    {
        $order = $this->getMockBuilder(OrderInterface::class)
            ->addMethods(['setShippingDescription'])
            ->getMockForAbstractClass();
        $order->method('getShippingMethod')->willReturn(null);
        $order->method('getShippingDescription')->willReturn('something - else');

        $order->expects($this->never())->method('setShippingDescription');

        $this->observer->execute($this->wrap($order));
    }

    public function testNullOrderIsNoop(): void
    {
        $observer = $this->createMock(Observer::class);
        $event = new \stdClass();
        $event->order = null;
        $observer->method('getEvent')->willReturn(
            (new class {
                public function getOrder() { return null; }
            })
        );

        // Should not throw
        $this->observer->execute($observer);
        $this->assertTrue(true);
    }

    private function wrap(OrderInterface $order): Observer
    {
        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn(
            (new class($order) {
                private $order;
                public function __construct($order) { $this->order = $order; }
                public function getOrder() { return $this->order; }
            })
        );
        return $observer;
    }
}
