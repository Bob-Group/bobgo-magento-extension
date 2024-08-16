<?php

namespace BobGroup\BobGo\Model\Carrier;

use Magento\Framework\App\Request\Http;

/**
 * Handles the retrieval of company information from the Estimate Shipping Methods request body.
 */
class USubs
{
    /**
     * @var Http
     */
    protected $request;

    /**
     * Constructor
     *
     * @param Http $request
     */
    public function __construct(Http $request)
    {
        $this->request = $request;
    }

    /**
     * Retrieve the destination company from the request body
     *
     * @return string
     */
    public function getDestComp(): string
    {
        $data = json_decode($this->getRequestBody(), true);

        // Ensure that $data is an array and has the expected structure
        if (is_array($data) && isset($data['address']) && is_array($data['address']) && isset($data['address']['company'])) {
            return $data['address']['company'];
        }

        return '';
    }

    /**
     * Retrieve the raw request body
     *
     * @return string
     */
    private function getRequestBody(): string
    {
        return $this->request->getContent();
    }
}
