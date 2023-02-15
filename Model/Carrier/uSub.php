<?php

namespace bobgo\CustomShipping\Model\Carrier;
use Magento\Checkout\Api\Data\ShippingInformationInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;


class uSub
{
    public function getSuburb(): mixed
    {
        //address.custom_attributes[0].value = "suburb value"
        $data = json_decode(file_get_contents('php://input'), true);
        return $data['address']['custom_attributes'][0]['value'] ?? '';
    }

}
