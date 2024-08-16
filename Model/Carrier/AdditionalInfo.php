<?php

namespace BobGroup\BobGo\Model\Carrier;

use Magento\Framework\App\RequestInterface;

/**
 * Handles the retrieval of additional information from the request body.
 */
class AdditionalInfo
{
    /**
     * @var \BobGroup\BobGo\Model\Carrier\AdditionalInfo
     */
    public $countryFactory;

    /**
     * @var RequestInterface
     */
    protected $request;

    /**
     * Constructor
     *
     * @param \BobGroup\BobGo\Model\Carrier\AdditionalInfo $countryFactory
     * @param RequestInterface $request
     */
    public function __construct($countryFactory, RequestInterface $request)
    {
        $this->countryFactory = $countryFactory;
        $this->request = $request;
    }

    /**
     * Retrieve the destination company from the request body
     *
     * @return string
     */
    public function getDestComp(): string
    {
        $data = $this->getRequestBody();

        return $data['address']['company'] ?? '';
    }

    /**
     * Retrieve the suburb from the request body
     *
     * @return string
     */
    public function getSuburb(): string
    {
        $data = $this->getRequestBody();

        return $data['address']['custom_attributes'][0]['value'] ?? '';
    }

    /**
     * Retrieve the destination telephone number from the request body
     *
     * @return string
     */
    public function getDestTelephone(): string
    {
        $data = $this->getRequestBody();

        return $data['address']['telephone'] ?? '';
    }

    /**
     * Get the full country name by country ID
     *
     * @param string $countryId
     * @return string
     */
    public function getCountryName(string $countryId): string
    {
        $countryName = '';
        $country = $this->countryFactory->create()->loadByCode($countryId);
        if ($country) {
            $countryName = $country->getName();
        }
        return $countryName;
    }

    /**
     * Retrieve the request body as an array
     *
     * @return array
     */
    private function getRequestBody(): array
    {
        return json_decode($this->request->getContent(), true) ?: [];
    }
}
