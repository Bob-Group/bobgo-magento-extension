<?php

namespace BobGroup\BobGo\Plugin;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Api\Data\OrderItemInterface;

/**
 * Converts order item weights from pounds (LBS) to kilograms (KG) before saving.
 *
 * Bob Go expects item weights in kilograms. When the Magento store is configured
 * to use pounds as the weight unit, this plugin converts each order item's weight
 * using the exact conversion factor: 1 lb = 0.45359237 kg.
 */
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
     * @return array{0: OrderInterface}
     */
    public function beforeSave(
        OrderRepositoryInterface $subject,
        OrderInterface $order
    ): array {
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

                // Set the converted weight back to the item using the correct setter method
                $orderItem->setWeight($convertedWeight);

                // Assuming you want to store this in a custom field, you should add a custom attribute
                // If you are using a custom attribute, ensure that it’s correctly added to the OrderItemInterface
                // $orderItem->setData('custom_weight_attribute', $convertedWeight);
            }
        }

        return [$order];
    }
}
