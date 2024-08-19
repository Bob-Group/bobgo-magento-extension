<?php

namespace BobGroup\BobGo\Test\Unit\Model\Carrier;

use BobGroup\BobGo\Model\Carrier\BobGo;
use BobGroup\BobGo\Model\Carrier\AdditionalInfo;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Directory\Helper\Data;
use Magento\Directory\Model\CountryFactory;
use Magento\Directory\Model\CurrencyFactory;
use Magento\Directory\Model\RegionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http as MagentoHttp;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\DataObject;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Xml\Security;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Shipping\Model\Rate\ResultFactory;
use Magento\Shipping\Model\Tracking\Result\StatusFactory;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BobGoTest extends TestCase
{
    private $bobGo;
    private $storeManagerMock;
    private $scopeConfigMock;
    private $curlMock;
    private $resultFactoryMock;
    private $methodFactoryMock;
    private $additionalInfoMock;

    protected function setUp(): void
    {
        // Create mock objects for all dependencies
        $this->storeManagerMock = $this->createMock(StoreManagerInterface::class);
        $this->scopeConfigMock = $this->createMock(ScopeConfigInterface::class);
        $this->curlMock = $this->createMock(Curl::class); // Ensure Curl mock is initialized
        $this->resultFactoryMock = $this->createMock(ResultFactory::class);
        $this->methodFactoryMock = $this->createMock(MethodFactory::class);
        $this->additionalInfoMock = $this->createMock(AdditionalInfo::class); // Correctly mock AdditionalInfo

        // Create mock objects for other dependencies that aren't used directly
        $jsonFactoryMock = $this->createMock(JsonFactory::class);
        $rateErrorFactoryMock = $this->createMock(ErrorFactory::class);
        $loggerMock = $this->createMock(LoggerInterface::class);
        $xmlSecurityMock = $this->createMock(Security::class);
        $xmlElFactoryMock = $this->createMock(\Magento\Shipping\Model\Simplexml\ElementFactory::class);
        $trackFactoryMock = $this->createMock(\Magento\Shipping\Model\Tracking\ResultFactory::class);
        $trackErrorFactoryMock = $this->createMock(\Magento\Shipping\Model\Tracking\Result\ErrorFactory::class);
        $trackStatusFactoryMock = $this->createMock(StatusFactory::class);
        $regionFactoryMock = $this->createMock(RegionFactory::class);
        $countryFactoryMock = $this->createMock(CountryFactory::class);
        $currencyFactoryMock = $this->createMock(CurrencyFactory::class);
        $directoryDataMock = $this->createMock(Data::class);
        $stockRegistryMock = $this->createMock(StockRegistryInterface::class);
        $productCollectionFactoryMock = $this->createMock(CollectionFactory::class);

        // Mock the CurlFactory to return the Curl mock
        $curlFactoryMock = $this->createMock(CurlFactory::class);
        $curlFactoryMock->method('create')->willReturn($this->curlMock);

        $requestMock = $this->createMock(MagentoHttp::class);

        // Instantiate the BobGo class with the mocked dependencies
        $this->bobGo = new BobGo(
            $this->scopeConfigMock,
            $rateErrorFactoryMock,
            $loggerMock,
            $xmlSecurityMock,
            $xmlElFactoryMock,
            $this->resultFactoryMock,
            $this->methodFactoryMock,
            $trackFactoryMock,
            $trackErrorFactoryMock,
            $trackStatusFactoryMock,
            $regionFactoryMock,
            $countryFactoryMock,
            $currencyFactoryMock,
            $directoryDataMock,
            $stockRegistryMock,
            $this->storeManagerMock,
            $productCollectionFactoryMock,
            $jsonFactoryMock,
            $curlFactoryMock, // Pass the CurlFactory mock here
            $requestMock,
            []
        );

        // Assign the mocked AdditionalInfo directly to the BobGo instance
        $this->bobGo->additionalInfo = $this->additionalInfoMock;
    }


// Your test methods go here...

public function testGetBaseUrl()
    {
        $storeMock = $this->createMock(\Magento\Store\Model\Store::class);
        $this->storeManagerMock->method('getStore')->willReturn($storeMock);
        $storeMock->method('getBaseUrl')->willReturn('https://www.example.com/');

        $baseUrl = $this->bobGo->getBaseUrl();

        $this->assertEquals('example.com', $baseUrl);
    }

    public function testGetDestComp()
    {
        $this->additionalInfoMock->method('getDestComp')->willReturn('Test Company');

        $destComp = $this->bobGo->getDestComp();

        $this->assertEquals('Test Company', $destComp);
    }

    public function testGetDestSuburb()
    {
        $this->additionalInfoMock->method('getSuburb')->willReturn('Test Suburb');

        $destSuburb = $this->bobGo->getDestSuburb();

        $this->assertEquals('Test Suburb', $destSuburb);
    }

    public function testGetRates()
    {
        $payload = [
            'identifier' => 'example.com',
            'rate' => [
                'origin' => [
                    'company' => 'Test Company',
                    'address1' => '123 Test St',
                    'city' => 'Test City',
                    'country_code' => 'US',
                    'postal_code' => '12345',
                ],
                'destination' => [
                    'company' => 'Destination Company',
                    'address1' => '456 Destination St',
                    'city' => 'Destination City',
                    'country_code' => 'ZA',
                    'postal_code' => '67890',
                ],
                'items' => [
                    [
                        'sku' => 'item-1',
                        'quantity' => 1,
                        'price' => 100.00,
                        'weight' => 500,
                    ],
                ],
            ],
        ];

        $this->curlMock->method('getBody')->willReturn(json_encode(['rates' => [['id' => 'rate-1']]]));

        $rates = $this->bobGo->getRates($payload);

        $this->assertIsArray($rates);
        $this->assertEquals('rate-1', $rates['rates'][0]['id']);
    }

    public function testProcessAdditionalValidation()
    {
        // Create a mock for Product
        $productMock = $this->createMock(\Magento\Catalog\Model\Product::class);
        $productMock->method('isVirtual')->willReturn(false);
        $productMock->method('getWeight')->willReturn(1);

        // Create a mock for Quote Item
        $quoteItemMock = $this->createMock(\Magento\Quote\Model\Quote\Item::class);
        $quoteItemMock->method('getProduct')->willReturn($productMock);

        // Create a real RateRequest object
        $rateRequest = new RateRequest();
        $rateRequest->setDestPostcode('12345');
        $rateRequest->setDestCountryId('ZA');
        $rateRequest->setAllItems([$quoteItemMock]);

        $result = $this->bobGo->processAdditionalValidation($rateRequest);

        $this->assertInstanceOf(BobGo::class, $result);
    }


}
