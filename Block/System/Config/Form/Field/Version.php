<?php
namespace BobGroup\BobGo\Block\System\Config\Form\Field;

use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Displays Version number in System Configuration
 *
 * This block is responsible for displaying the version number of the BobGo extension
 * in the system configuration settings.
 *
 * @website https://www.bobgo.co.za
 */
class Version extends \Magento\Config\Block\System\Config\Form\Field
{
    /**
     * @var string
     */
    public const EXTENSION_URL = 'https://www.bobgo.co.za';

    /**
     * @var \BobGroup\BobGo\Helper\Data $helper
     */
    protected $_helper;

    /**
     * Constructor
     *
     * @param \Magento\Backend\Block\Template\Context $context
     * @param \BobGroup\BobGo\Helper\Data $helper
     */
    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        \BobGroup\BobGo\Helper\Data $helper
    ) {
        $this->_helper = $helper;
        parent::__construct($context);
    }

    /**
     * Get HTML for the element
     *
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        $extensionVersion   = $this->_helper->getExtensionVersion();
        $extensionTitle     = 'BobGo';
        $versionLabel       = sprintf(
            '<a href="%s" title="%s" target="_blank">%s</a>',
            self::EXTENSION_URL,
            $extensionTitle,
            $extensionVersion
        );
        $element->setValue($versionLabel);

        return $element->getValue();
    }
}
