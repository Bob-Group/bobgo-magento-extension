<?php

namespace BobGroup\BobGo\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\HTTP\Client\Curl;
use BobGroup\BobGo\Model\Carrier\UData;
use Psr\Log\LoggerInterface;
use Magento\Store\Model\StoreManagerInterface;

class OrderCreateWebhook extends OrderWebhookBase
{
    protected Curl $curl;
    protected LoggerInterface $logger;
    protected StoreManagerInterface $storeManager;

    public function __construct(LoggerInterface $logger, Curl $curl, StoreManagerInterface $storeManager)
    {
        $this->logger = $logger;
        $this->curl = $curl;
        $this->storeManager = $storeManager;
    }

    public function execute(Observer $observer)
    {
        $order = $observer->getEvent()->getOrder();
        if (!$order) {
            return;
        }

        // Extract order data and send to the webhook URL
        $this->sendWebhook($order, 'order_created');
    }
}
