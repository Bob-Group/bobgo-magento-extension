<?php
namespace uafrica\Customshipping\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * @category   uafrica
 * @package    uafrica_customshipping
 * @author      uafrica
 * @website    https://www.bob.co.za
 */
class Data extends AbstractHelper
{

    const XML_PATH_ENABLED = 'uafrica_customshipping/general/enabled';
    const XML_PATH_DEBUG   = 'uafrica_customshipping/general/debug';

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $_logger;

    /**
     * @var ModuleListInterface
     */
    protected $_moduleList;

    /**
     * @param Context $context
     * @param ModuleListInterface $moduleList
     */
    public function __construct(
        Context $context,
        ModuleListInterface $moduleList
    ) {
        $this->_logger                  = $context->getLogger();
        $this->_moduleList              = $moduleList;

        parent::__construct($context);
    }

    /**
     * Check if enabled
     *
     * @return string|null
     */
    public function isEnabled()
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE
        );
    }

    public function getDebugStatus()
    {
        return $this->scopeConfig->getValue(
            self::XML_PATH_DEBUG,
            ScopeInterface::SCOPE_STORE
        );
    }

    public function getExtensionVersion()
    {
        $moduleCode = 'uafrica_Customshipping';
        $moduleInfo = $this->_moduleList->getOne($moduleCode);
        return $moduleInfo['setup_version'];
    }

    /**
     *
     * @param $message
     * @param bool|false $useSeparator
     */
    public function log($message, $useSeparator = false)
    {
        if ($this->getDebugStatus()) {
            if ($useSeparator) {
                $this->_logger->addDebug(str_repeat('=', 100));
            }

            $this->_logger->addDebug($message);
        }
    }
}
