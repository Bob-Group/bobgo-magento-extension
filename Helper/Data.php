<?php

namespace BobGroup\BobGo\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Helper class for BobGo module
 *
 * This class provides various helper functions used in the BobGo module, including
 * configuration checks, version retrieval, and logging.
 *
 * @website https://www.bobgo.co.za
 */
class Data extends AbstractHelper
{
    public const XML_PATH_ENABLED = 'BobGroup_BobGo/general/enabled';
    public const XML_PATH_DEBUG = 'BobGroup_BobGo/general/debug';

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $_logger;

    /**
     * @var ModuleListInterface
     */
    protected ModuleListInterface $_moduleList;

    public function __construct(
        Context $context,
        ModuleListInterface $moduleList
    ) {
        $this->_logger = $context->getLogger();
        $this->_moduleList = $moduleList;
        parent::__construct($context);
    }

    /**
     * Check if the BobGo module is enabled
     *
     * @return string|null
     */
    public function isEnabled(): ?string
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE
        );

        return is_string($value) ? $value : null;
    }

    /**
     * Get the debug status of the BobGo module
     *
     * @return string|null
     */
    public function getDebugStatus(): ?string
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_DEBUG,
            ScopeInterface::SCOPE_STORE
        );

        return is_string($value) ? $value : null;
    }

    /**
     * Get the version of the BobGo extension
     *
     * @return string
     */
    public function getExtensionVersion(): string
    {
        $moduleCode = 'BobGroup_BobGo';
        $moduleInfo = $this->_moduleList->getOne($moduleCode);

        return $moduleInfo['setup_version'] ?? 'N/A';
    }

    /**
     * Log a debug message if debug mode is enabled
     *
     * @param string $message
     * @param bool $useSeparator
     * @return void
     */
    public function log($message, $useSeparator = false)
    {
        if ($this->getDebugStatus()) {
            if ($useSeparator) {
                $this->_logger->debug(str_repeat('=', 100));
            }

            $this->_logger->debug($message);
        }
    }
}
