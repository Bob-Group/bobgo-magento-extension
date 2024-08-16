<?php

namespace BobGroup\BobGo\Model\Carrier;

use Magento\Framework\App\RequestInterface;

/**
 * Handles the retrieval of company information from the Estimate Shipping Methods request body.
 */
class USubs
{
    /**
     * @var RequestInterface
     */
    protected $request;

    /**
     * Constructor
     *
     * @param RequestInterface $request
     */
    public function __construct(RequestInterface $request)
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

        return $data['address']['company'] ?? '';
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
