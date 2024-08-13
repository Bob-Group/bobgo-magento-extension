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
        $this->logger->info('ConfigChangeObserver triggered.');

        $changedPaths = $observer->getEvent()->getData('changed_paths');

        if (is_array($changedPaths) && in_array('carriers/bobgo/active', $changedPaths)) {
            $this->logger->info('BobGo configuration change detected.');

            if ($this->bobGo->isActive()) {
                $result = $this->bobGo->triggerRatesTest();

                if ($result !== false) {
                    $this->logger->info('Rates test triggered successfully with a valid response.');
                    $this->messageManager->addSuccessMessage(__('BobGo rates test triggered successfully.'));
                } else {
                    $this->logger->error('Error in BobGo rates test.');
                    $this->messageManager->addErrorMessage(__('BobGo rates test failed. Please check visit https://www.bobgo.co.za/ and enable this channel for rates at checkout.'));
                }
            } else {
                $this->logger->info('BobGo is not active.');
            }
        } else {
            $this->logger->info('No relevant configuration changes detected.');
        }
    }

}
