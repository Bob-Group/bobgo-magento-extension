<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Helper;

use BobGroup\BobGo\Helper\Data;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Module\ModuleListInterface;
use PHPUnit\Framework\TestCase;

/**
 * The helper is now just the version lookup behind the admin config field.
 *
 * It used to also carry isEnabled() and a debug logger reading
 * BobGroup_BobGo/general/{enabled,debug} — config paths that exist in neither
 * config.xml nor system.xml, so isEnabled() was always false and log() never
 * wrote anything. The tests for them passed because they stubbed the config
 * lookup, which is exactly how dead code keeps its coverage.
 */
class DataTest extends TestCase
{
    /** @var Data */
    private $helper;

    /** @var \PHPUnit\Framework\MockObject\MockObject */
    private $moduleListMock;

    protected function setUp(): void
    {
        $contextMock = $this->createMock(Context::class);
        $this->moduleListMock = $this->createMock(ModuleListInterface::class);

        $this->helper = new Data($contextMock, $this->moduleListMock);
    }

    public function testGetExtensionVersionReadsTheModuleSetupVersion(): void
    {
        $this->moduleListMock->method('getOne')
            ->with('BobGroup_BobGo')
            ->willReturn(['setup_version' => '1.1.0']);

        $this->assertSame('1.1.0', $this->helper->getExtensionVersion());
    }

    public function testGetExtensionVersionFallsBackWhenTheModuleIsUnknown(): void
    {
        $this->moduleListMock->method('getOne')->willReturn(null);

        $this->assertSame('N/A', $this->helper->getExtensionVersion());
    }
}
