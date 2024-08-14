<?php

namespace BobGroup\BobGo\Model\Carrier;

/**
 * Get the Company information from the request body and return it
 */
class Company
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
}
