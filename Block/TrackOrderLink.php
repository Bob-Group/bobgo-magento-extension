<?php

namespace BobGroup\BobGo\Block;

use Magento\Framework\View\Element\Html\Link\Current;
use Magento\Framework\App\Config\ScopeConfigInterface;

class TrackOrderLink extends Current
{
    protected $scopeConfig;

    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        ScopeConfigInterface $scopeConfig,
        \Magento\Framework\App\DefaultPathInterface $defaultPath,
        array $data = []
    ) {
        $this->scopeConfig = $scopeConfig;
        parent::__construct($context, $defaultPath, $data);
    }

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



