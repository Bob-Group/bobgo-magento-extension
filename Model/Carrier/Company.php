<?php

namespace bobgo\CustomShipping\Model\Carrier;

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
}
