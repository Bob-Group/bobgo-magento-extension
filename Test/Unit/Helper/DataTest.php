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

        $this->helper = new Data($this->contextMock, $this->moduleListMock);
    }

    public function testIsEnabled(): void
    {
        $this->scopeConfigMock->method('isSetFlag')
            ->with(Data::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE)
            ->willReturn(true);

        $this->assertTrue($this->helper->isEnabled());
    }

    public function testIsEnabledReturnsFalseWhenDisabled(): void
    {
        $this->scopeConfigMock->method('isSetFlag')
            ->with(Data::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE)
            ->willReturn(false);

        $this->assertFalse($this->helper->isEnabled());
    }

    public function testGetDebugStatus(): void
    {
        $this->scopeConfigMock->method('isSetFlag')
            ->with(Data::XML_PATH_DEBUG, ScopeInterface::SCOPE_STORE)
            ->willReturn(true);

        $this->assertTrue($this->helper->getDebugStatus());
    }

    public function testGetDebugStatusReturnsFalseWhenDisabled(): void
    {
        $this->scopeConfigMock->method('isSetFlag')
            ->with(Data::XML_PATH_DEBUG, ScopeInterface::SCOPE_STORE)
            ->willReturn(false);

        $this->assertFalse($this->helper->getDebugStatus());
    }

    public function testGetExtensionVersion(): void
    {
        $this->moduleListMock->method('getOne')
            ->with('BobGroup_BobGo')
            ->willReturn(['setup_version' => '1.2.3']);

        $result = $this->helper->getExtensionVersion();
        $this->assertEquals('1.2.3', $result);
    }

    public function testGetExtensionVersionReturnsNAWhenModuleNotFound(): void
    {
        $this->moduleListMock->method('getOne')
            ->with('BobGroup_BobGo')
            ->willReturn(null);

        $result = $this->helper->getExtensionVersion();
        $this->assertEquals('N/A', $result);
    }

    public function testLogWithDebugEnabled(): void
    {
        $this->scopeConfigMock->method('isSetFlag')
            ->with(Data::XML_PATH_DEBUG, ScopeInterface::SCOPE_STORE)
            ->willReturn(true);

        $this->loggerMock->expects($this->once())
            ->method('debug')
            ->with('Test message');

        $this->helper->log('Test message');
    }

    public function testLogWithDebugDisabled(): void
    {
        $this->scopeConfigMock->method('isSetFlag')
            ->with(Data::XML_PATH_DEBUG, ScopeInterface::SCOPE_STORE)
            ->willReturn(false);

        $this->loggerMock->expects($this->never())
            ->method('debug');

        $this->helper->log('Test message');
    }

    public function testLogWithSeparator(): void
    {
        $this->scopeConfigMock->method('isSetFlag')
            ->with(Data::XML_PATH_DEBUG, ScopeInterface::SCOPE_STORE)
            ->willReturn(true);

        $this->loggerMock->expects($this->exactly(2))
            ->method('debug')
            ->withConsecutive(
                [str_repeat('=', 100)],
                ['Test message']
            );

        $this->helper->log('Test message', true);
    }
}
