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
}
