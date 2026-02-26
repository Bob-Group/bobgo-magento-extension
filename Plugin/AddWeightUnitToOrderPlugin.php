<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Plugin;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Converts order item weights from pounds (LBS) to kilograms (KG) before saving.
 *
 * Bob Go expects item weights in kilograms. When the Magento store is configured
 * to use pounds as the weight unit, this plugin converts each order item's weight
 * using the exact conversion factor: 1 lb = 0.45359237 kg.
 */
class AddWeightUnitToOrderPlugin
{
    private const LBS_TO_KG = 0.45359237;

    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;

    public function __construct(
        ScopeConfigInterface $scopeConfig
    ) {
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Before save plugin to convert order item weights from lbs to kg when applicable.
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
            ScopeInterface::SCOPE_STORE
        );

        if ($weightUnit === 'lbs' && $order->getItems()) {
            foreach ($order->getItems() as $orderItem) {
                $weight = $orderItem->getWeight();
                if ($weight !== null) {
                    $orderItem->setWeight($weight * self::LBS_TO_KG);
                }
            }
        }

        return [$order];
    }
}
