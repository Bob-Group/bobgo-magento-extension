<?php

namespace BobGroup\BobGo\Block;

use Magento\Framework\View\Element\Html\Link\Current;
use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Conditional "Track my order" link for the customer account sidebar.
 *
 * Renders the link only when the track order feature is enabled in the
 * Bob Go admin configuration. Returns empty string when disabled.
 */
class TrackOrderLink extends Current
{
    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @param \Magento\Framework\View\Element\Template\Context $context
     * @param ScopeConfigInterface $scopeConfig
     * @param \Magento\Framework\App\DefaultPathInterface $defaultPath
     * @param array<string, mixed> $data
     */
    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        ScopeConfigInterface $scopeConfig,
        \Magento\Framework\App\DefaultPathInterface $defaultPath,
        array $data = []
    ) {
        $this->scopeConfig = $scopeConfig;
        parent::__construct($context, $defaultPath, $data);
    }

    /**
     * Render the link HTML only if the Track My Order feature is enabled.
     *
     * @return string HTML output, or empty string if feature is disabled
     */
    protected function _toHtml()
    {
        // Check if the Track My Order feature is enabled
        $isEnabled = $this->scopeConfig->isSetFlag(
            'carriers/bobgo/enable_track_order',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );

        // Return an empty string if the feature is disabled
        if (!$isEnabled) {
            return '';
        }

        // Use the parent class's rendering method
        return parent::_toHtml();
    }
}



