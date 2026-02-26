<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;

/**
 * Simplifies the shipping description before an order is placed.
 *
 * Magento stores the full carrier + method title (e.g. "Bob Go - Delivery in 3 - 5 days - Standard Delivery").
 * This observer extracts only the method title portion after the last " - " separator
 * so the stored description is cleaner (e.g. "Standard Delivery").
 */
class ModifyShippingDescription implements ObserverInterface
{
    public const CODE = 'bobgo';

    private const DESCRIPTION_SEPARATOR = ' - ';

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getOrder();
        $shippingDescription = $order->getShippingDescription();
        $order->setShippingDescription($this->extractMethodTitle($shippingDescription));
    }

    /**
     * Extract the method title after the last " - " separator, or return the full description as fallback.
     */
    private function extractMethodTitle(string $shippingDescription): string
    {
        $lastSeparatorPos = strrpos($shippingDescription, self::DESCRIPTION_SEPARATOR);

        if ($lastSeparatorPos !== false) {
            return trim(substr($shippingDescription, $lastSeparatorPos + strlen(self::DESCRIPTION_SEPARATOR)));
        }

        return $shippingDescription;
    }
}
