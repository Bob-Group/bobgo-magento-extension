<?php

namespace bobgo\Customshipping\Plugin;

use Magento\Quote\Api\CartRepositoryInterface;

use Magento\Checkout\Api\Data\ShippingInformationInterface;

class ShippingInformationManagement
{
    public CartRepositoryInterface $cartRepository;

    public function __construct(
        CartRepositoryInterface $cartRepository,
    )
    {
        $this->cartRepository = $cartRepository;
    }

    public function beforeSaveAddressInformation($subject, $cartId, ShippingInformationInterface $addressInformation)
    {
        $quote = $this->cartRepository->getActive($cartId);
        $deliveryNote = $addressInformation->getShippingAddress()->getExtensionAttributes()->getSuburb();
        $quote->setSuburb($deliveryNote);
        $this->cartRepository->save($quote);
        return [$cartId, $addressInformation];
    }

}
