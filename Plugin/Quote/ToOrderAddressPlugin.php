<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Plugin\Quote;

use Magento\Quote\Api\Data\AddressInterface as QuoteAddressInterface;
use Magento\Quote\Model\Quote\Address\ToOrderAddress;
use Magento\Sales\Api\Data\OrderAddressExtensionFactory;
use Magento\Sales\Api\Data\OrderAddressInterface;

/**
 * Copies the `suburb` extension attribute from the quote shipping address
 * onto the order address as it's being converted.
 *
 * Magento's ToOrderAddress converter only copies the data keys it knows
 * about; extension attributes have to be carried across explicitly. Without
 * this plugin the suburb is captured at checkout, persisted on the quote
 * address, and then dropped on the floor when the order is placed.
 *
 * Bob Go's OrderMapper reads `local_area` from the suburb extension
 * attribute, so this plugin is what makes the rate-time suburb flow through
 * to outbound order-push payloads.
 */
class ToOrderAddressPlugin
{
    private OrderAddressExtensionFactory $orderAddressExtensionFactory;

    public function __construct(OrderAddressExtensionFactory $orderAddressExtensionFactory)
    {
        $this->orderAddressExtensionFactory = $orderAddressExtensionFactory;
    }

    public function afterConvert(
        ToOrderAddress $subject,
        OrderAddressInterface $orderAddress,
        QuoteAddressInterface $quoteAddress
    ): OrderAddressInterface {
        $suburb = $this->extractSuburb($quoteAddress);
        if ($suburb === '') {
            return $orderAddress;
        }

        $extension = $orderAddress->getExtensionAttributes()
            ?: $this->orderAddressExtensionFactory->create();

        if (method_exists($extension, 'setSuburb')) {
            $extension->setSuburb($suburb);
        }
        $orderAddress->setExtensionAttributes($extension);

        // Also stash it as a plain data key so `getData('suburb')` works in
        // places that don't fish through the extension-attribute API.
        if (method_exists($orderAddress, 'setData')) {
            $orderAddress->setData('suburb', $suburb);
        }

        return $orderAddress;
    }

    private function extractSuburb(QuoteAddressInterface $quoteAddress): string
    {
        $ext = $quoteAddress->getExtensionAttributes();
        if ($ext && method_exists($ext, 'getSuburb')) {
            $value = $ext->getSuburb();
            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        if (method_exists($quoteAddress, 'getCustomAttribute')) {
            $attr = $quoteAddress->getCustomAttribute('suburb');
            if ($attr && $attr->getValue() !== null && (string) $attr->getValue() !== '') {
                return (string) $attr->getValue();
            }
        }

        if (method_exists($quoteAddress, 'getData')) {
            $direct = $quoteAddress->getData('suburb');
            if (is_scalar($direct) && (string) $direct !== '') {
                return (string) $direct;
            }
        }

        return '';
    }
}
