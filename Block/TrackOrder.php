<?php
namespace BobGroup\BobGo\Block;
class TrackOrder extends \Magento\Framework\View\Element\Template
{
    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        array $data = []
    )
    {
        parent::__construct($context, $data);
    }

    public function getHelloWorld()
    {
        return 'Hello World';
    }

}
