<?php

namespace BobGroup\BobGo\Test\Unit\Model\Carrier;

use BobGroup\BobGo\Model\Carrier\AdditionalInfo;
use Magento\Framework\App\Request\Http;
use Magento\Directory\Model\CountryFactory;
use Magento\Directory\Model\Country;
use PHPUnit\Framework\TestCase;

class AdditionalInfoTest extends TestCase
{
    /** @var AdditionalInfo */
    private $additionalInfo;

    /** @var Http|\PHPUnit\Framework\MockObject\MockObject */
    private $requestMock;

    /** @var CountryFactory|\PHPUnit\Framework\MockObject\MockObject */
    private $countryFactoryMock;

    /** @var Country|\PHPUnit\Framework\MockObject\MockObject */
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

    public function testGetCountryName(): void
    {
        $countryId = 'US';

        $this->countryMock->method('getName')
            ->willReturn('United States');

        $result = $this->additionalInfo->getCountryName($countryId);

        $this->assertEquals('United States', $result);
    }

    public function testGetDestComp(): void
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

    public function testGetDestCompReturnsEmptyStringWhenNotSet(): void
    {
        $requestBody = json_encode([]);

        $this->requestMock->method('getContent')
            ->willReturn($requestBody);

        $result = $this->additionalInfo->getDestComp();

        $this->assertEquals('', $result);
    }

    public function testGetSuburb(): void
    {
        $requestBody = json_encode([
            'address' => [
                'custom_attributes' => [
                    ['attribute_code' => 'suburb', 'value' => 'Test Suburb']
                ]
            ]
        ]);

        $this->requestMock->method('getContent')
            ->willReturn($requestBody);

        $result = $this->additionalInfo->getSuburb();

        $this->assertEquals('Test Suburb', $result);
    }

    public function testGetSuburbSkipsAttributesAheadOfIt(): void
    {
        $requestBody = json_encode([
            'address' => [
                'custom_attributes' => [
                    ['attribute_code' => 'something_else', 'value' => 'ignore me'],
                    ['attribute_code' => 'suburb', 'value' => 'Sandton'],
                ]
            ]
        ]);

        $this->requestMock->method('getContent')->willReturn($requestBody);
        $this->assertSame('Sandton', $this->additionalInfo->getSuburb());
    }

    public function testGetSuburbHandlesAssociativeShape(): void
    {
        $requestBody = json_encode([
            'address' => [
                'custom_attributes' => [
                    'suburb' => ['value' => 'Rosebank'],
                ]
            ]
        ]);

        $this->requestMock->method('getContent')->willReturn($requestBody);
        $this->assertSame('Rosebank', $this->additionalInfo->getSuburb());
    }

    public function testGetSuburbReturnsEmptyStringWhenNotSet(): void
    {
        $requestBody = json_encode([]);

        $this->requestMock->method('getContent')
            ->willReturn($requestBody);

        $result = $this->additionalInfo->getSuburb();

        $this->assertEquals('', $result);
    }

    public function testGetDestTelephone(): void
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

    public function testGetDestTelephoneReturnsEmptyStringWhenNotSet(): void
    {
        $requestBody = json_encode([]);

        $this->requestMock->method('getContent')
            ->willReturn($requestBody);

        $result = $this->additionalInfo->getDestTelephone();

        $this->assertEquals('', $result);
    }
}
