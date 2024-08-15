<?php

namespace BobGroup\BobGo\Plugin;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

class AddWeightUnitToOrderPlugin
{
    protected $logger;
    protected $scopeConfig;

    public function __construct(
        LoggerInterface $logger,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
    }

    public function beforeSave(
        OrderRepositoryInterface $subject,
        OrderInterface $order
    ) {
        $weightUnit = $this->scopeConfig->getValue(
            'general/locale/weight_unit',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );

        if ($weightUnit === 'lbs') {
            foreach ($order->getItems() as $orderItem) {
                // Get the current weight of the item
                $weight = $orderItem->getWeight();

                // Convert weight from lbs to kg
                $convertedWeight = $weight * 0.45359237;

                // Set the converted weight back to the item
                $orderItem->setData('weight', $weight);
            }
        }
        
        return [$order];
    }
}
