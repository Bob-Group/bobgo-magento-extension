<?php

namespace BobGroup\BobGo\Test\Unit\Helper;

use BobGroup\BobGo\Helper\Data;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DataTest extends TestCase
{
    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $contextMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $moduleListMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $scopeConfigMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $loggerMock;

    /**
     * @var Data
     */
    private $helper;

    protected function setUp(): void
    {
        $this->contextMock = $this->createMock(Context::class);
        $this->moduleListMock = $this->createMock(ModuleListInterface::class);
        $this->scopeConfigMock = $this->createMock(ScopeConfigInterface::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);

        $this->contextMock->method('getLogger')->willReturn($this->loggerMock);
        $this->contextMock->method('getScopeConfig')->willReturn($this->scopeConfigMock);

        // Instantiate the Data helper
        $this->helper = new Data($this->contextMock, $this->moduleListMock);
    }

    public function testIsEnabled(): void
    {
        // Mock the scopeConfig to return '1' when checking if the module is enabled
        $this->scopeConfigMock->method('getValue')
            ->with(Data::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE)
            ->willReturn('1');

        $result = $this->helper->isEnabled();
        $this->assertEquals('1', $result);
    }

    public function testIsEnabledReturnsNullWhenDisabled(): void
    {
        // Mock the scopeConfig to return null when the module is disabled
        $this->scopeConfigMock->method('getValue')
            ->with(Data::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE)
            ->willReturn(null);

        $result = $this->helper->isEnabled();
        $this->assertNull($result);
    }

    public function testGetDebugStatus(): void
    {
        // Mock the scopeConfig to return '1' when checking if debug is enabled
        $this->scopeConfigMock->method('getValue')
            ->with(Data::XML_PATH_DEBUG, ScopeInterface::SCOPE_STORE)
            ->willReturn('1');

        $result = $this->helper->getDebugStatus();
        $this->assertEquals('1', $result);
    }

    public function testGetDebugStatusReturnsNullWhenDisabled(): void
    {
        // Mock the scopeConfig to return null when debug is disabled
        $this->scopeConfigMock->method('getValue')
            ->with(Data::XML_PATH_DEBUG, ScopeInterface::SCOPE_STORE)
            ->willReturn(null);

        $result = $this->helper->getDebugStatus();
        $this->assertNull($result);
    }

    public function testGetExtensionVersion(): void
    {
        // Mock the moduleList to return a specific version
        $this->moduleListMock->method('getOne')
            ->with('BobGroup_BobGo')
            ->willReturn(['setup_version' => '1.2.3']);

        $result = $this->helper->getExtensionVersion();
        $this->assertEquals('1.2.3', $result);
    }

    public function testGetExtensionVersionReturnsNAWhenModuleNotFound(): void
    {
        // Mock the moduleList to return null (module not found)
        $this->moduleListMock->method('getOne')
            ->with('BobGroup_BobGo')
            ->willReturn(null);

        $result = $this->helper->getExtensionVersion();
        $this->assertEquals('N/A', $result);
    }

    public function testLogWithDebugEnabled(): void
    {
        // Mock the scopeConfig to return '1' when checking if debug is enabled
        $this->scopeConfigMock->method('getValue')
            ->with(Data::XML_PATH_DEBUG, ScopeInterface::SCOPE_STORE)
            ->willReturn('1');

        // Expect the logger to be called with a specific message
        $this->loggerMock->expects($this->once())
            ->method('debug')
            ->with('Test message');

        $this->helper->log('Test message');
    }

    public function testLogWithDebugDisabled(): void
    {
        // Mock the scopeConfig to return null when debug is disabled
        $this->scopeConfigMock->method('getValue')
            ->with(Data::XML_PATH_DEBUG, ScopeInterface::SCOPE_STORE)
            ->willReturn(null);

        // Expect the logger not to be called
        $this->loggerMock->expects($this->never())
            ->method('debug');

        $this->helper->log('Test message');
    }

    public function testLogWithSeparator(): void
    {
        // Mock the scopeConfig to return '1' when checking if debug is enabled
        $this->scopeConfigMock->method('getValue')
            ->with(Data::XML_PATH_DEBUG, ScopeInterface::SCOPE_STORE)
            ->willReturn('1');

        // Expect the logger to be called with a separator and the message
        $this->loggerMock->expects($this->exactly(2))
            ->method('debug')
            ->withConsecutive(
                [str_repeat('=', 100)],
                ['Test message']
            );

        $this->helper->log('Test message', true);
    }
}
