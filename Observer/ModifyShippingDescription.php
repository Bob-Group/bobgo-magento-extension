<?php

namespace BobGroup\BobGo\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Psr\Log\LoggerInterface;

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

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @param LoggerInterface $logger
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Extract the method title from the shipping description before order placement.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        // Get the order object from the event
        $order = $observer->getEvent()->getOrder();

        // Get the current shipping description
        $shippingDescription = $order->getShippingDescription();

        // Get the method title from the shipping description
        $methodTitle = $this->extractMethodTitle($shippingDescription);

        // Set the new shipping description based only on MethodTitle
        $newDescription = $methodTitle;

        // Update the shipping description in the order
        $order->setShippingDescription($newDescription);
    }

    /**
     * Helper function to extract the method title from the original shipping description
     *
     * @param string $shippingDescription
     * @return string
     */
    private function extractMethodTitle($shippingDescription)
    {
        // Find the position of the last dash in the string
        $lastDashPosition = strrpos($shippingDescription, ' - ');

        // If a dash is found, extract the part after the last dash
        if ($lastDashPosition !== false) {
            return trim(substr($shippingDescription, $lastDashPosition + 3)); // +3 to skip the ' - ' part
        }

        // If no dash is found, return the full description (fallback)
        return $shippingDescription;
    }

}
