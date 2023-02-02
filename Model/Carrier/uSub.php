<?php

namespace bobgo\CustomShipping\Model\Carrier;
use Magento\Checkout\Api\Data\ShippingInformationInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;


class uSub {


    /**
     * This is supposed to get the suburb from the shipping address extension attributes.
     * Does not work for now, was supposed to access the suburb attribute from the shipping address extension attributes.
     * @throws NoSuchEntityException
     */
    public function getDestSuburb()
    {
        $objectManager = ObjectManager::getInstance();
        $quote = $objectManager->get('Magento\Checkout\Model\ShippingInformation')->getExtensionAttributes();
        return $quote->getSuburb();
    }



}
