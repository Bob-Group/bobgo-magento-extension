<?php

namespace BobGroup\BobGo\Block;

use Magento\Framework\View\Element\Template;

class TrackingBlock extends \Magento\Framework\View\Element\Template
{
    protected $response;

    protected function _toHtml()
    {
        $this->_logger->info('Block HTML rendered: ' . $this->getNameInLayout());
        return parent::_toHtml();
    }

    public function setResponse(array $response): self
    {
        $this->_response = $response;
        return $this;
    }

    public function getResponse()
    {
        return $this->response;
    }
}
