<?php

namespace BobGroup\BobGo\Test\Unit\Plugin;

use BobGroup\BobGo\Plugin\AddWeightUnitToOrderPlugin;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

class AddWeightUnitToOrderPluginTest extends TestCase
{
    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $loggerMock;

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
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->scopeConfigMock = $this->createMock(ScopeConfigInterface::class);

        // Instantiate the plugin
        $this->plugin = new AddWeightUnitToOrderPlugin(
            $this->loggerMock,
            $this->scopeConfigMock
        );
    }

    public function testBeforeSaveWithLbsWeightUnit(): void
    {
        // Mock the OrderInterface
        $orderMock = $this->createMock(OrderInterface::class);

        // Mock the OrderItemInterface
        $orderItemMock = $this->createMock(OrderItemInterface::class);
        $orderItemMock->method('getWeight')->willReturn(10); // Assume 10 lbs

        // Return a list of order items
        $orderMock->method('getItems')->willReturn([$orderItemMock]);

        // Set up the ScopeConfig mock to return 'lbs'
        $this->scopeConfigMock->method('getValue')
            ->with('general/locale/weight_unit', \Magento\Store\Model\ScopeInterface::SCOPE_STORE)
            ->willReturn('lbs');

        // Expect that the weight will be converted and set back
        $orderItemMock->expects($this->once())
            ->method('setWeight')
            ->with(4.5359237); // 10 lbs * 0.45359237 = 4.5359237 kg

        // Call the plugin's beforeSave method
        $result = $this->plugin->beforeSave($this->createMock(OrderRepositoryInterface::class), $orderMock);

        // Assert that the returned order is the same as the original
        $this->assertSame([$orderMock], $result);
    }

    public function testBeforeSaveWithNonLbsWeightUnit(): void
    {
        // Mock the OrderInterface
        $orderMock = $this->createMock(OrderInterface::class);

        // Mock the OrderItemInterface
        $orderItemMock = $this->createMock(OrderItemInterface::class);

        // Return a list of order items
        $orderMock->method('getItems')->willReturn([$orderItemMock]);

        // Set up the ScopeConfig mock to return 'kgs'
        $this->scopeConfigMock->method('getValue')
            ->with('general/locale/weight_unit', \Magento\Store\Model\ScopeInterface::SCOPE_STORE)
            ->willReturn('kgs');

        // Expect that the setWeight method is never called since the unit is not 'lbs'
        $orderItemMock->expects($this->never())
            ->method('setWeight');

        // Call the plugin's beforeSave method
        $result = $this->plugin->beforeSave($this->createMock(OrderRepositoryInterface::class), $orderMock);

        // Assert that the returned order is the same as the original
        $this->assertSame([$orderMock], $result);
    }
}
