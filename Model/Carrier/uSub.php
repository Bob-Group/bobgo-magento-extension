<?php

namespace bobgo\Customshipping\Model\Carrier;
use Magento\Checkout\Api\Data\ShippingInformationInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;


class uSub {


    /**
     * @throws NoSuchEntityException
     */
    public function getDestSuburb()
    {
        $objectManager = ObjectManager::getInstance();
        $quote = $objectManager->get('Magento\Checkout\Model\ShippingInformation')->getExtensionAttributes();
        return $quote->getSuburb();
    }



}
