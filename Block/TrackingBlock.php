<?php

namespace BobGroup\BobGo\Block;

use Magento\Framework\View\Element\Template;
use Magento\Framework\Registry;

class TrackingBlock extends \Magento\Framework\View\Element\Template
{
    protected $registry;

    public function __construct(
        Template\Context $context,
        Registry $registry,
        array $data = []
    ) {
        $this->registry = $registry;
        parent::__construct($context, $data);
    }

    public function getResponse()
    {
        return $this->registry->registry('shipment_data');
    }
}

