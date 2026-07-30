<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Module\ModuleListInterface;

/**
 * Helper class for BobGo module.
 *
 * Version lookup for the admin config field. It used to also carry isEnabled()
 * and a debug logger reading BobGroup_BobGo/general/{enabled,debug} — config
 * paths that exist in neither config.xml nor system.xml, so both were always
 * false and log() never wrote anything.
 */
class Data extends AbstractHelper
{
    private const MODULE_CODE = 'BobGroup_BobGo';

    /**
     * @var ModuleListInterface
     */
    private ModuleListInterface $moduleList;

    public function __construct(
        Context $context,
        ModuleListInterface $moduleList
    ) {
        $this->moduleList = $moduleList;
        parent::__construct($context);
    }

    /**
     * Get the version of the BobGo extension.
     */
    public function getExtensionVersion(): string
    {
        $moduleInfo = $this->moduleList->getOne(self::MODULE_CODE);

        return $moduleInfo['setup_version'] ?? 'N/A';
    }
}
