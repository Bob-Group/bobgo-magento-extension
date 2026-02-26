<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Helper class for BobGo module.
 *
 * Provides configuration checks, version retrieval, and debug logging.
 */
class Data extends AbstractHelper
{
    public const XML_PATH_ENABLED = 'BobGroup_BobGo/general/enabled';
    public const XML_PATH_DEBUG = 'BobGroup_BobGo/general/debug';

    private const MODULE_CODE = 'BobGroup_BobGo';
    private const SEPARATOR_LENGTH = 100;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private \Psr\Log\LoggerInterface $debugLogger;

    /**
     * @var ModuleListInterface
     */
    private ModuleListInterface $moduleList;

    public function __construct(
        Context $context,
        ModuleListInterface $moduleList
    ) {
        $this->debugLogger = $context->getLogger();
        $this->moduleList = $moduleList;
        parent::__construct($context);
    }

    /**
     * Check if the BobGo module is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Get the debug status of the BobGo module.
     */
    public function getDebugStatus(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_DEBUG,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Get the version of the BobGo extension.
     */
    public function getExtensionVersion(): string
    {
        $moduleInfo = $this->moduleList->getOne(self::MODULE_CODE);

        return $moduleInfo['setup_version'] ?? 'N/A';
    }

    /**
     * Log a debug message if debug mode is enabled.
     */
    public function log(string $message, bool $useSeparator = false): void
    {
        if ($this->getDebugStatus()) {
            if ($useSeparator) {
                $this->debugLogger->debug(str_repeat('=', self::SEPARATOR_LENGTH));
            }

            $this->debugLogger->debug($message);
        }
    }
}
