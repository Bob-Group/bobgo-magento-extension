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

        if ($weightUnit) {
            foreach ($order->getItems() as $orderItem) {
                $orderItem->setData('product_type', $weightUnit);
            }
        }

        return [$order];
    }
}
