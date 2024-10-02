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

        // Test for rates at checkout
        if (is_array($changedPaths) && in_array('carriers/bobgo/active', $changedPaths)) {
            if ($this->bobGo->isActive()) {
                $result = $this->bobGo->triggerRatesTest();

                if ($result !== false) {
                    $this->messageManager->addSuccessMessage(
                        __('Bob Go rates at checkout connected.')
                    );
                } else {
                    $this->messageManager->addErrorMessage(
                        __('Failed to connect to rates at checkout. Please check your internet connection
                        and make sure Rates at checkout is enabled for your channel on Bob Go. Please visit Bob Go
                        settings page to make sure your Magento channel is enabled to receive rates.
                        https://my.bobgo.co.za/rates-at-checkout?tab=settings')
                    );
                }
            }
        }

        // Test for webhooks
        if (is_array($changedPaths) && in_array('carriers/bobgo/enable_webhooks', $changedPaths)) {
//            if ($this->bobGo->isWebhookEnabled()) {
                $this->logger->info('Webhooks test start: ');
                $result = $this->bobGo->triggerWebhookTest();
                $this->logger->info('Webhooks test end: ' . $result);

                if ($result) {
                    $this->messageManager->addSuccessMessage(
                        __('Webhook validation successful.')
                    );
                } else {
                    $this->messageManager->addErrorMessage(
                        __('Webhook validation failed. Please check the webhook key and try again.')
                    );
                }
//            }
        }
    }
}
