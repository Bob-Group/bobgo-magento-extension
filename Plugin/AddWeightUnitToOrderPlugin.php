<?php

namespace BobGroup\BobGo\Plugin;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

class AddWeightUnitToOrderPlugin
{
    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * Constructor
     *
     * @param LoggerInterface $logger
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        LoggerInterface $logger,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Before save plugin to modify order items' weight based on the configured weight unit.
     *
     * @param OrderRepositoryInterface $subject
     * @param OrderInterface $order
     * @return array
     */
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
                $orderItem->setWeight($convertedWeight);
                $orderItem->setData('weight', $convertedWeight);
            }
        }

        return [$order];
    }
}
