<?php

namespace BobGroup\BobGo\Observer;

use Magento\Framework\Event\Observer;

class OrderCreateWebhook extends OrderWebhookBase
{
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
