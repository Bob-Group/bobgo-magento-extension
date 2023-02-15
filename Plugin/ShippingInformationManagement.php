<?php

namespace bobgo\CustomShipping\Plugin;

use Magento\Quote\Api\CartRepositoryInterface;

use Magento\Checkout\Api\Data\ShippingInformationInterface;

/**
 * Class ShippingInformationManagement
 * @package bobgo\CustomShipping\Plugin
 * This class is supposed to copy the suburb attribute from the quote to the order object.
 */
class ShippingInformationManagement
{
    public CartRepositoryInterface $cartRepository;

    public function __construct(
        CartRepositoryInterface $cartRepository
    )
    {
        $this->cartRepository = $cartRepository;
    }

    public function beforeSaveAddressInformation($subject, $cartId, ShippingInformationInterface $addressInformation): array
    {
        $quote = $this->cartRepository->getActive($cartId);
        $deliveryNote = $addressInformation->getShippingAddress()->getExtensionAttributes()->getSuburb();
        $quote->setSuburb($deliveryNote);
        $this->cartRepository->save($quote);
        return [$cartId, $addressInformation];
    }

}
