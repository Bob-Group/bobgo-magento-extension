<?php

namespace BobGroup\BobGo\Test\Unit\Model\Carrier;

use BobGroup\BobGo\Model\Carrier\AdditionalInfo;
use Magento\Framework\App\Request\Http;
use Magento\Directory\Model\CountryFactory;
use Magento\Directory\Model\Country;
use PHPUnit\Framework\TestCase;

class AdditionalInfoTest extends TestCase
{
    private $additionalInfo;
    private $requestMock;
    private $countryFactoryMock;
    private $countryMock;

    protected function setUp(): void
    {
        $this->requestMock = $this->createMock(Http::class);
        $this->countryFactoryMock = $this->createMock(CountryFactory::class);
        $this->countryMock = $this->createMock(Country::class);

        $this->countryFactoryMock->method('create')
            ->willReturn($this->countryMock);

        $this->countryMock->method('loadByCode')
            ->willReturn($this->countryMock);

        $this->additionalInfo = new AdditionalInfo($this->countryFactoryMock, $this->requestMock);
    }

    public function testGetCountryName()
    {
        $countryId = 'US';

        $this->countryMock->method('getName')
            ->willReturn('United States');

        $result = $this->additionalInfo->getCountryName($countryId);

        $this->assertEquals('United States', $result);
    }

    public function testGetDestComp()
    {
        $requestBody = json_encode([
            'address' => [
                'company' => 'Test Company'
            ]
        ]);

        $this->requestMock->method('getContent')
            ->willReturn($requestBody);

        $result = $this->additionalInfo->getDestComp();

        $this->assertEquals('Test Company', $result);
    }

    public function testGetDestCompReturnsEmptyStringWhenNotSet()
    {
        $requestBody = json_encode([]);

        $this->requestMock->method('getContent')
            ->willReturn($requestBody);

        $result = $this->additionalInfo->getDestComp();

        $this->assertEquals('', $result);
    }

    public function testGetSuburb()
    {
        $requestBody = json_encode([
            'address' => [
                'custom_attributes' => [
                    ['value' => 'Test Suburb']
                ]
            ]
        ]);

        $this->requestMock->method('getContent')
            ->willReturn($requestBody);

        $result = $this->additionalInfo->getSuburb();

        $this->assertEquals('Test Suburb', $result);
    }

    public function testGetSuburbReturnsEmptyStringWhenNotSet()
    {
        $requestBody = json_encode([]);

        $this->requestMock->method('getContent')
            ->willReturn($requestBody);

        $result = $this->additionalInfo->getSuburb();

        $this->assertEquals('', $result);
    }

    public function testGetDestTelephone()
    {
        $requestBody = json_encode([
            'address' => [
                'telephone' => '123456789'
            ]
        ]);

        $this->requestMock->method('getContent')
            ->willReturn($requestBody);

        $result = $this->additionalInfo->getDestTelephone();

        $this->assertEquals('123456789', $result);
    }

    public function testGetDestTelephoneReturnsEmptyStringWhenNotSet()
    {
        $requestBody = json_encode([]);

        $this->requestMock->method('getContent')
            ->willReturn($requestBody);

        $result = $this->additionalInfo->getDestTelephone();

        $this->assertEquals('', $result);
    }
}
