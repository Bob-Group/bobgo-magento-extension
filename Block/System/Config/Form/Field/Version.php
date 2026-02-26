<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Block\System\Config\Form\Field;

use Magento\Framework\Data\Form\Element\AbstractElement;
use BobGroup\BobGo\Helper\Data;

/**
 * Displays the Bob Go extension version number in System Configuration.
 */
class Version extends \Magento\Config\Block\System\Config\Form\Field
{
    public const EXTENSION_URL = 'https://www.bobgo.co.za';
    private const EXTENSION_TITLE = 'BobGo';

    /**
     * @var Data
     */
    private Data $helper;

    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        Data $helper
    ) {
        $this->helper = $helper;
        parent::__construct($context);
    }

    /**
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        $extensionVersion = htmlspecialchars($this->helper->getExtensionVersion(), ENT_QUOTES, 'UTF-8');
        $versionLabel = sprintf(
            '<a href="%s" title="%s" target="_blank">%s</a>',
            self::EXTENSION_URL,
            self::EXTENSION_TITLE,
            $extensionVersion
        );

        $element->setData('value', $versionLabel);

        $value = $element->getData('value');
        return is_string($value) ? $value : '';
    }
}
