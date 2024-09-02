<?php

namespace BobGroup\BobGo\Block\System\Config\Form\Field;

use Magento\Framework\Data\Form\Element\AbstractElement;
use BobGroup\BobGo\Helper\Data;

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
     * @var Data
     */
    protected Data $_helper;

    /**
     * Constructor
     *
     * @param \Magento\Backend\Block\Template\Context $context
     * @param Data $helper
     */
    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        Data $helper
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
    protected function _getElementHtml(AbstractElement $element): string
    {
        $extensionVersion = $this->_helper->getExtensionVersion();
        $extensionTitle = 'BobGo';
        $versionLabel = sprintf(
            '<a href="%s" title="%s" target="_blank">%s</a>',
            self::EXTENSION_URL,
            $extensionTitle,
            $extensionVersion
        );

        // Set the value using setData
        $element->setData('value', $versionLabel);

        // Ensure the return value is a string or provide a fallback
        $value = $element->getData('value');
        return is_string($value) ? $value : '';
    }
}
