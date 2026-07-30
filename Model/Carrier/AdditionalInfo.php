<?php
declare(strict_types=1);

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
    private CountryFactory $countryFactory;

    /**
     * @var Http
     */
    private Http $request;

    public function __construct(CountryFactory $countryFactory, Http $request)
    {
        $this->countryFactory = $countryFactory;
        $this->request = $request;
    }

    /**
     * Retrieve the destination company from the request body.
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
     * Retrieve the suburb from the request body.
     *
     * Magento's checkout AJAX serialises custom_attributes as a list of
     * `{attribute_code, value}` objects. We match on `attribute_code` rather
     * than positional index because other modules can inject custom
     * attributes ahead of suburb, breaking a [0] lookup.
     */
    public function getSuburb(): string
    {
        $data = $this->getRequestBody();

        if (!isset($data['address']) || !is_array($data['address'])) {
            return '';
        }

        $address = $data['address'];

        if (isset($address['custom_attributes']) && is_array($address['custom_attributes'])) {
            foreach ($address['custom_attributes'] as $attr) {
                if (!is_array($attr)) {
                    continue;
                }
                $code = $attr['attribute_code'] ?? null;
                if ($code === 'suburb' && isset($attr['value']) && $attr['value'] !== '') {
                    return (string) $attr['value'];
                }
            }
            // Some Magento builds emit custom_attributes as an associative map
            // keyed by attribute_code instead of a numeric list of objects.
            if (isset($address['custom_attributes']['suburb'])) {
                $value = $address['custom_attributes']['suburb'];
                if (is_array($value) && isset($value['value'])) {
                    return (string) $value['value'];
                }
                if (is_scalar($value)) {
                    return (string) $value;
                }
            }
        }

        return '';
    }

    /**
     * Retrieve the destination telephone number from the request body.
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
     * Get the full country name by country ID.
     */
    public function getCountryName(string $countryId): string
    {
        $country = $this->countryFactory->create()->loadByCode($countryId);
        return $country->getName() ?: '';
    }

    /**
     * @return array<string, mixed>
     */
    private function getRequestBody(): array
    {
        $content = $this->request->getContent();
        $data = json_decode($content, true);

        return is_array($data) ? $data : [];
    }
}
