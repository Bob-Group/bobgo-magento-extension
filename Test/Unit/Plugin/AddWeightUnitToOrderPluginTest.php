<?php

namespace BobGroup\BobGo\Test\Unit\Plugin;

use BobGroup\BobGo\Plugin\AddWeightUnitToOrderPlugin;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Magento\Framework\App\Config\ScopeConfigInterface;

class AddWeightUnitToOrderPluginTest extends TestCase
{
    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $scopeConfigMock;

    /**
     * @var AddWeightUnitToOrderPlugin
     */
    private $plugin;

    protected function setUp(): void
    {
        $this->scopeConfigMock = $this->createMock(ScopeConfigInterface::class);

        $this->plugin = new AddWeightUnitToOrderPlugin(
            $this->scopeConfigMock
        );
    }

    public function testBeforeSaveWithLbsWeightUnit(): void
    {
        $orderMock = $this->createMock(OrderInterface::class);

        $orderItemMock = $this->createMock(OrderItemInterface::class);
        $orderItemMock->method('getWeight')->willReturn(10.0);

        $orderMock->method('getItems')->willReturn([$orderItemMock]);

        $this->scopeConfigMock->method('getValue')
            ->with('general/locale/weight_unit', \Magento\Store\Model\ScopeInterface::SCOPE_STORE)
            ->willReturn('lbs');

        $orderItemMock->expects($this->once())
            ->method('setWeight')
            ->with($this->callback(function ($weight) {
                return abs($weight - 4.5359237) < 0.0001;
            }));

        $result = $this->plugin->beforeSave($this->createMock(OrderRepositoryInterface::class), $orderMock);

        $this->assertSame([$orderMock], $result);
    }

    public function testBeforeSaveWithNonLbsWeightUnit(): void
    {
        $orderMock = $this->createMock(OrderInterface::class);

        $orderItemMock = $this->createMock(OrderItemInterface::class);

        $orderMock->method('getItems')->willReturn([$orderItemMock]);

        $this->scopeConfigMock->method('getValue')
            ->with('general/locale/weight_unit', \Magento\Store\Model\ScopeInterface::SCOPE_STORE)
            ->willReturn('kgs');

        $orderItemMock->expects($this->never())
            ->method('setWeight');

        $result = $this->plugin->beforeSave($this->createMock(OrderRepositoryInterface::class), $orderMock);

        $this->assertSame([$orderMock], $result);
    }

    public function testBeforeSaveWithNullWeight(): void
    {
        $orderMock = $this->createMock(OrderInterface::class);

        $orderItemMock = $this->createMock(OrderItemInterface::class);
        $orderItemMock->method('getWeight')->willReturn(null);

        $orderMock->method('getItems')->willReturn([$orderItemMock]);

        $this->scopeConfigMock->method('getValue')
            ->with('general/locale/weight_unit', \Magento\Store\Model\ScopeInterface::SCOPE_STORE)
            ->willReturn('lbs');

        $orderItemMock->expects($this->never())
            ->method('setWeight');

        $result = $this->plugin->beforeSave($this->createMock(OrderRepositoryInterface::class), $orderMock);

        $this->assertSame([$orderMock], $result);
    }
}
