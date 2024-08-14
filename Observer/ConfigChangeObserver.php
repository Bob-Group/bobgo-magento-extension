<?php

namespace BobGroup\BobGo\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface;
use Psr\Log\LoggerInterface;
use BobGroup\BobGo\Model\Carrier\BobGo;

class ConfigChangeObserver implements ObserverInterface
{
    protected $bobGo;
    protected $logger;
    protected $messageManager;

    public function __construct(
        BobGo $bobGo,
        LoggerInterface $logger,
        ManagerInterface $messageManager
    ) {
        $this->bobGo = $bobGo;
        $this->logger = $logger;
        $this->messageManager = $messageManager;
    }

    public function execute(Observer $observer)
    {
        $changedPaths = $observer->getEvent()->getData('changed_paths');

        if (is_array($changedPaths) && in_array('carriers/bobgo/active', $changedPaths)) {

            if ($this->bobGo->isActive()) {
                $result = $this->bobGo->triggerRatesTest();

                if ($result !== false) {
                    $this->messageManager->addSuccessMessage(__('BobGo rates at checkout test is successful.'));
                } else {
                    $this->messageManager->addErrorMessage(__('BobGo rates at checkout test failed. Please visit https://www.bobgo.co.za/ and enable this channel for rates at checkout.'));
                }
            }
        }
    }

}
