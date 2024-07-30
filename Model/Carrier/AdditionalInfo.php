<?php

namespace BobGroup\BobGo\Model\Carrier;

/**
 * Get the AdditionalInfo information from the request body and return it
 */
class AdditionalInfo
{

    /**
     * @return mixed|string
     */
    public function getDestComp(): mixed
    {
        $data = json_decode(file_get_contents('php://input'), true);

        if (isset($data['address']['company'])) {
            $destComp = $data['address']['company'];
        } else {
            $destComp = '';
        }
        return $destComp;
    }

    public function getSuburb(): mixed
    {

        $data = json_decode(file_get_contents('php://input'), true);

        if (isset($data['address']['custom_attributes'][0]['value'])) {
            $destSub = $data['address']['custom_attributes'][0]['value'];
            //print_r($destSub);
        } else {
            $destSub = '';
        }
        return $destSub;
    }

    /**
     * @return mixed|string
     */
    public function getDestTelephone(): mixed
    {
        $data = json_decode(file_get_contents('php://input'), true);

        if (isset($data['address']['telephone'])) {
            $destTelephone = $data['address']['telephone'];
        } else {
            $destTelephone = '';
        }
        return $destTelephone;
    }


    /**
     * @var \BobGroup\BobGo\Model\Carrier\AdditionalInfo
     */
    public $countryFactory;

    public function __construct($countryFactory)
    {
        $this->countryFactory = $countryFactory;
    }

    /**
     * country full name
     *
     * @return string
     */
    public function getCountryName($countryId): string
    {
        $countryName = '';
        $country = $this->countryFactory->create()->loadByCode($countryId);
        if ($country) {
            $countryName = $country->getName();
        }
        return $countryName;
    }

//    // Method to get the per-package price
//    protected function _getPerpackagePrice($cost, $handlingType, $handlingFee)
//    {
//        if ($handlingType == AbstractCarrier::HANDLING_TYPE_PERCENT) {
//            return $cost + $cost * $this->_numBoxes * $handlingFee / self::UNITS;
//        }
//        return $cost + $this->_numBoxes * $handlingFee;
//    }
//
//    // Method to get the per-order price
//    protected function _getPerorderPrice($cost, $handlingType, $handlingFee)
//    {
//        if ($handlingType == self::HANDLING_TYPE_PERCENT) {
//            return $cost + $cost * $handlingFee / self::UNITS;
//        }
//        return $cost + $handlingFee;
//    }
//
//    // Method to get configuration data of the carrier
//    public function getCode($type, $code = '')
//    {
//        $codes = [
//            'method' => [
//                'bobGo' => __('BobGo'),
//            ],
//            'unit_of_measure' => [
//                'KGS' => __('Kilograms'),
//                'LBS' => __('Pounds'),
//            ],
//        ];
//        if (!isset($codes[$type])) {
//            return false;
//        } elseif ('' === $code) {
//            return $codes[$type];
//        }
//        if (!isset($codes[$type][$code])) {
//            return false;
//        } else {
//            return $codes[$type][$code];
//        }
//    }

}
