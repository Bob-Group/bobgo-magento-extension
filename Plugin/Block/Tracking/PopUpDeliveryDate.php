<?php

namespace uafrica\Customshipping\Plugin\Block\Tracking;

use Magento\Shipping\Block\Tracking\Popup;
use Magento\Shipping\Model\Tracking\Result\Status;
use Magento\Shiiping\Model\Carrier;

/*
 * Plugin to update delivery date value in case if UAfrica is a carrier used
 */
class PopupDeliveryDate
{
    /**
     * Show only date for expected delivery in case if UAfrica is a carrier
     *
     * @param Popup $subject
     * @param string $result
     * @param string $date
     * @param string $time
     * @return string
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterFormatDeliveryDateTime(Popup $subject, $result, $date, $time)
    {
        if ($this->getCarrier($subject) === Carrier::CODE) {
            $result = $subject->formatDeliveryDate($date);
        }
        return $result;
    }

    /**
     * Retrieve carrier name from tracking info
     *
     * @param Popup $subject
     * @return string
     */
    private function getCarrier(Popup $subject): string
    {
        foreach ($subject->getTrackingInfo() as $trackingData) {
            foreach ($trackingData as $trackingInfo) {
                if ($trackingInfo instanceof Status) {
                    $carrier = $trackingInfo->getCarrier();
                    return $carrier;
                }
            }
        }
        return '';
    }
}
