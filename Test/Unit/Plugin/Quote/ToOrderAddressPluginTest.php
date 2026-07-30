<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Plugin\Quote;

use BobGroup\BobGo\Plugin\Quote\ToOrderAddressPlugin;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the quote -> order address conversion plugin. This is the
 * code path that makes the customer's checkout suburb actually reach the
 * order push payload — without it the suburb sits on the quote address
 * and disappears at order placement.
 */
class ToOrderAddressPluginTest extends TestCase
{
    public function testCopiesSuburbFromExtensionAttributes(): void
    {
        $plugin = $this->buildPlugin();

        $quoteAddress = $this->createQuoteAddressMock();
        $ext = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getSuburb'])
            ->getMock();
        $ext->method('getSuburb')->willReturn('Sandton');
        $quoteAddress->method('getExtensionAttributes')->willReturn($ext);

        $orderAddressExt = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['setSuburb'])
            ->getMock();
        $orderAddressExt->expects($this->once())->method('setSuburb')->with('Sandton');

        $orderAddress = $this->createOrderAddressMock();
        $orderAddress->method('getExtensionAttributes')->willReturn($orderAddressExt);

        // The order address must be updated with the suburb data.
        $orderAddress->expects($this->once())->method('setData')->with('suburb', 'Sandton');
        $orderAddress->expects($this->once())->method('setExtensionAttributes')->with($orderAddressExt);

        $result = $plugin->afterConvert($this->createSubject(), $orderAddress, $quoteAddress);
        $this->assertSame($orderAddress, $result);
    }

    public function testFallsBackToCustomAttribute(): void
    {
        $plugin = $this->buildPlugin();

        $quoteAddress = $this->createQuoteAddressMock();
        $quoteAddress->method('getExtensionAttributes')->willReturn(null);

        $attr = $this->createMock(\Magento\Framework\Api\AttributeInterface::class);
        $attr->method('getValue')->willReturn('Rosebank');
        $quoteAddress->method('getCustomAttribute')
            ->with('suburb')
            ->willReturn($attr);

        $orderAddress = $this->createOrderAddressMock();
        $orderAddress->method('getExtensionAttributes')->willReturn(null);

        $orderAddress->expects($this->once())->method('setData')->with('suburb', 'Rosebank');

        $plugin->afterConvert($this->createSubject(), $orderAddress, $quoteAddress);
    }

    public function testNoopWhenSuburbEmpty(): void
    {
        $plugin = $this->buildPlugin();

        $quoteAddress = $this->createQuoteAddressMock();
        $quoteAddress->method('getExtensionAttributes')->willReturn(null);
        $quoteAddress->method('getCustomAttribute')->willReturn(null);
        $quoteAddress->method('getData')->willReturn(null);

        $orderAddress = $this->createOrderAddressMock();
        $orderAddress->expects($this->never())->method('setData');
        $orderAddress->expects($this->never())->method('setExtensionAttributes');

        $result = $plugin->afterConvert($this->createSubject(), $orderAddress, $quoteAddress);
        $this->assertSame($orderAddress, $result);
    }

    private function createSubject(): \Magento\Quote\Model\Quote\Address\ToOrderAddress
    {
        return $this->createMock(\Magento\Quote\Model\Quote\Address\ToOrderAddress::class);
    }

    private function buildPlugin(): ToOrderAddressPlugin
    {
        $extFactory = $this->createMock(\Magento\Sales\Api\Data\OrderAddressExtensionFactory::class);
        $extFactory->method('create')->willReturn(
            $this->getMockBuilder(\stdClass::class)
                ->addMethods(['setSuburb'])
                ->getMock()
        );

        return new ToOrderAddressPlugin($extFactory);
    }

    private function createQuoteAddressMock(): \PHPUnit\Framework\MockObject\MockObject
    {
        // Build a mock with the methods our plugin probes via method_exists.
        return $this->getMockBuilder(\Magento\Quote\Api\Data\AddressInterface::class)
            ->addMethods(['getExtensionAttributes', 'getCustomAttribute', 'getData'])
            ->getMockForAbstractClass();
    }

    private function createOrderAddressMock(): \PHPUnit\Framework\MockObject\MockObject
    {
        return $this->getMockBuilder(\Magento\Sales\Api\Data\OrderAddressInterface::class)
            ->addMethods(['getExtensionAttributes', 'setExtensionAttributes', 'setData'])
            ->getMockForAbstractClass();
    }
}
