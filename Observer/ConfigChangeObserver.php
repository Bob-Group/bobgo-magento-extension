<?php

namespace BobGroup\BobGo\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface;
use Psr\Log\LoggerInterface;
use BobGroup\BobGo\Model\Carrier\BobGo;

class ConfigChangeObserver implements ObserverInterface
{
    /**
     * @var BobGo
     */
    protected $bobGo;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var ManagerInterface
     */
    protected $messageManager;

    /**
     * Constructor
     *
     * @param BobGo $bobGo
     * @param LoggerInterface $logger
     * @param ManagerInterface $messageManager
     */
    public function __construct(
        BobGo $bobGo,
        LoggerInterface $logger,
        ManagerInterface $messageManager
    ) {
        $this->bobGo = $bobGo;
        $this->logger = $logger;
        $this->messageManager = $messageManager;
    }

    /**
     * Execute observer to handle configuration changes.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $changedPaths = $observer->getEvent()->getData('changed_paths');

        if (is_array($changedPaths) && in_array('carriers/bobgo/active', $changedPaths)) {
            if ($this->bobGo->isActive()) {
                $result = $this->bobGo->triggerRatesTest();

                if ($result !== false) {
                    $this->messageManager->addSuccessMessage(
                        __('Bob Go rates at checkout connected.')
                    );
                } else {
                    $this->messageManager->addErrorMessage(
                        __('Failed to connect to rates at checkout. Please check your internet connection and make '
                            . 'sure Rates at checkout is enabled for your channel on Bob Go. Please visit '
                            . '<a href="https://my.bobgo.co.za/rates-at-checkout?tab=settings" target="_new">Bob '
                            . 'Go</a> and make sure your WooCommerce channel is enabled to receive rates.')
                    );
                }
            }
        }
    }
}
