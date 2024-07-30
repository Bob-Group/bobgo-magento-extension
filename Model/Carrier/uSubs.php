<?php

namespace BobGroup\BobGo\Model\Carrier;

/** Get AdditionalInfo information if available from the Estimate Shipping Methods Request Body */
class uSubs
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
