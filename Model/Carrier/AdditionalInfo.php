<?php

namespace BobGroup\BobGo\Model\Carrier;

use Magento\Framework\App\Request\Http;
use Magento\Directory\Model\CountryFactory;

/**
 * Handles the retrieval of additional information from the request body.
 */
class AdditionalInfo
{
    /**
     * @var CountryFactory
     */
    public $countryFactory;

    /**
     * @var Http
     */
    protected $request;

    /**
     * Constructor
     *
     * @param CountryFactory $countryFactory
     * @param Http $request
     */
    public function __construct(CountryFactory $countryFactory, Http $request)
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

        if (isset($data['address']) && is_array($data['address']) && isset($data['address']['company'])) {
            return (string) $data['address']['company'];
        }

        return '';
    }

    /**
     * Retrieve the suburb from the request body
     *
     * @return string
     */
    public function getSuburb(): string
    {
        $data = $this->getRequestBody();

        if (
            isset($data['address']) && is_array($data['address']) &&
            isset($data['address']['custom_attributes'][0]) && is_array($data['address']['custom_attributes'][0]) &&
            isset($data['address']['custom_attributes'][0]['value'])
        ) {
            return (string) $data['address']['custom_attributes'][0]['value'];
        }

        return '';
    }

    /**
     * Retrieve the destination telephone number from the request body
     *
     * @return string
     */
    public function getDestTelephone(): string
    {
        $data = $this->getRequestBody();

        if (isset($data['address']) && is_array($data['address']) && isset($data['address']['telephone'])) {
            return (string) $data['address']['telephone'];
        }

        return '';
    }

    /**
     * Get the full country name by country ID
     *
     * @param string $countryId
     * @return string
     */
    public function getCountryName(string $countryId): string
    {
        $country = $this->countryFactory->create()->loadByCode($countryId);
        return $country->getName() ?: '';
    }

    /**
     * Retrieve the request body as an array
     *
     * @return array<string, mixed>
     */
    private function getRequestBody(): array
    {
        $content = $this->request->getContent();
        $data = json_decode($content, true);

        return is_array($data) ? $data : [];
    }
}
