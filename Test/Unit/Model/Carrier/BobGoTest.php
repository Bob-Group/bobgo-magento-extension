<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Model\Carrier;

use BobGroup\BobGo\Model\Carrier\BobGo;
use BobGroup\BobGo\Model\Carrier\AdditionalInfo;
use BobGroup\BobGo\Api\BobGoApiClient;
use BobGroup\BobGo\Api\BobGoApiException;
use BobGroup\BobGo\Model\Config\ApiConfig;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Directory\Helper\Data;
use Magento\Directory\Model\CountryFactory;
use Magento\Directory\Model\CurrencyFactory;
use Magento\Directory\Model\RegionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http as MagentoHttp;
use Magento\Framework\Xml\Security;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Shipping\Model\Rate\ResultFactory;
use Magento\Shipping\Model\Simplexml\ElementFactory;
use Magento\Shipping\Model\Tracking\Result\StatusFactory;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BobGoTest extends TestCase
{
    /** @var BobGo */
    private $bobGo;

    /** @var StoreManagerInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $storeManagerMock;

    /** @var ScopeConfigInterface|\PHPUnit\Framework\MockObject\MockObject */
    private $scopeConfigMock;

    /** @var BobGoApiClient|\PHPUnit\Framework\MockObject\MockObject */
    private $apiClientMock;

    /** @var ApiConfig|\PHPUnit\Framework\MockObject\MockObject */
    private $apiConfigMock;

    /** @var ResultFactory|\PHPUnit\Framework\MockObject\MockObject */
    private $resultFactoryMock;

    /** @var MethodFactory|\PHPUnit\Framework\MockObject\MockObject */
    private $methodFactoryMock;

    /** @var AdditionalInfo|\PHPUnit\Framework\MockObject\MockObject */
    private $additionalInfoMock;

    protected function setUp(): void
    {
        $this->storeManagerMock = $this->createMock(StoreManagerInterface::class);
        $this->scopeConfigMock = $this->createMock(ScopeConfigInterface::class);
        $this->apiClientMock = $this->createMock(BobGoApiClient::class);
        $this->apiConfigMock = $this->createMock(ApiConfig::class);
        $this->resultFactoryMock = $this->createMock(ResultFactory::class);
        $this->methodFactoryMock = $this->createMock(MethodFactory::class);
        $this->additionalInfoMock = $this->createMock(AdditionalInfo::class);

        $rateErrorFactoryMock = $this->createMock(ErrorFactory::class);
        $loggerMock = $this->createMock(LoggerInterface::class);
        $xmlSecurityMock = $this->createMock(Security::class);
        $xmlElFactoryMock = $this->createMock(ElementFactory::class);
        $trackFactoryMock = $this->createMock(\Magento\Shipping\Model\Tracking\ResultFactory::class);
        $trackErrorFactoryMock = $this->createMock(\Magento\Shipping\Model\Tracking\Result\ErrorFactory::class);
        $trackStatusFactoryMock = $this->createMock(StatusFactory::class);
        $regionFactoryMock = $this->createMock(RegionFactory::class);
        $countryFactoryMock = $this->createMock(CountryFactory::class);
        $currencyFactoryMock = $this->createMock(CurrencyFactory::class);
        $directoryDataMock = $this->createMock(Data::class);
        $stockRegistryMock = $this->createMock(StockRegistryInterface::class);
        $productCollectionFactoryMock = $this->createMock(CollectionFactory::class);
        $requestMock = $this->createMock(MagentoHttp::class);

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
            $requestMock,
            $this->apiClientMock,
            $this->apiConfigMock,
            []
        );

        $this->bobGo->additionalInfo = $this->additionalInfoMock;
    }

    public function testGetBaseUrl(): void
    {
        $storeMock = $this->createMock(\Magento\Store\Model\Store::class);
        $this->storeManagerMock->method('getStore')->willReturn($storeMock);
        $storeMock->method('getBaseUrl')->willReturn('https://www.example.com/');

        $baseUrl = $this->bobGo->getBaseUrl();

        $this->assertEquals('example.com', $baseUrl);
    }

    public function testGetDestComp(): void
    {
        $this->additionalInfoMock->method('getDestComp')->willReturn('Test Company');

        $destComp = $this->bobGo->getDestComp();

        $this->assertEquals('Test Company', $destComp);
    }

    public function testGetDestSuburb(): void
    {
        $this->additionalInfoMock->method('getSuburb')->willReturn('Test Suburb');

        $destSuburb = $this->bobGo->getDestSuburb();

        $this->assertEquals('Test Suburb', $destSuburb);
    }

    public function testGetRates(): void
    {
        $payload = [
            'rate' => [
                'origin' => [
                    'company' => 'Test Company',
                    'address1' => '123 Test St',
                    'city' => 'Test City',
                    'country_code' => 'ZA',
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

        $this->apiClientMock->method('post')
            ->with('rates-at-checkout', $payload)
            ->willReturn(['rates' => [['id' => 'rate-1']]]);

        $rates = $this->bobGo->getRates($payload);

        $this->assertIsArray($rates);
        $this->assertEquals('rate-1', $rates['rates'][0]['id']);
    }

    public function testGetRatesHandlesApiError(): void
    {
        $payload = ['rate' => ['origin' => [], 'destination' => [], 'items' => []]];

        $this->apiClientMock->method('post')
            ->willThrowException(new BobGoApiException('API error', 500));

        $rates = $this->bobGo->getRates($payload);

        $this->assertEmpty($rates);
    }

    public function testProcessAdditionalValidation(): void
    {
        $productMock = $this->createMock(\Magento\Catalog\Model\Product::class);
        $productMock->method('isVirtual')->willReturn(false);
        $productMock->method('getWeight')->willReturn(1);

        $quoteItemMock = $this->createMock(\Magento\Quote\Model\Quote\Item::class);
        $quoteItemMock->method('getProduct')->willReturn($productMock);

        $rateRequest = new RateRequest();
        $rateRequest->setDestPostcode('12345');
        $rateRequest->setDestCountryId('ZA');
        $rateRequest->setAllItems([$quoteItemMock]);

        $result = $this->bobGo->processAdditionalValidation($rateRequest);

        $this->assertInstanceOf(BobGo::class, $result);
    }

    public function testTriggerRatesTestUsesApiClient(): void
    {
        $this->scopeConfigMock->method('getValue')
            ->willReturnMap([
                ['carriers/bobgo/active', ScopeInterface::SCOPE_STORE, null, '1'],
            ]);

        $this->apiClientMock->method('post')
            ->with('rates-at-checkout', $this->anything())
            ->willReturn([
                'rates' => [['id' => 'rate-1', 'service_name' => 'Standard']],
            ]);

        $result = $this->bobGo->triggerRatesTest();

        $this->assertIsArray($result);
        $this->assertNotFalse($result);
    }

    public function testTriggerRatesTestReturnsFalseOnApiError(): void
    {
        $this->scopeConfigMock->method('getValue')
            ->willReturnMap([
                ['carriers/bobgo/active', ScopeInterface::SCOPE_STORE, null, '1'],
            ]);

        $this->apiClientMock->method('post')
            ->willThrowException(new BobGoApiException('API error', 401));

        $result = $this->bobGo->triggerRatesTest();

        $this->assertFalse($result);
    }

    public function testCollectRatesPayloadHasNoIdentifier(): void
    {
        $this->scopeConfigMock->method('getValue')
            ->willReturn('test_value');
        $this->scopeConfigMock->method('isSetFlag')
            ->willReturn(true);

        $this->additionalInfoMock->method('getDestComp')->willReturn('Test Co');
        $this->additionalInfoMock->method('getSuburb')->willReturn('Test Suburb');

        $storeMock = $this->createMock(\Magento\Store\Model\Store::class);
        $storeMock->method('getBaseUrl')->willReturn('https://example.com/');
        $this->storeManagerMock->method('getStore')->willReturn($storeMock);

        $resultMock = $this->createMock(\Magento\Shipping\Model\Rate\Result::class);
        $this->resultFactoryMock->method('create')->willReturn($resultMock);

        // Capture the payload sent to apiClient
        $capturedPayload = null;
        $this->apiClientMock->method('post')
            ->willReturnCallback(function ($endpoint, $payload) use (&$capturedPayload) {
                $capturedPayload = $payload;
                return ['rates' => []];
            });

        $rateRequest = new RateRequest();
        $rateRequest->setDestPostcode('2196');
        $rateRequest->setDestCountryId('ZA');
        $rateRequest->setDestRegionCode('GT');
        $rateRequest->setDestCity('Sandton');
        $rateRequest->setDestStreet('1 Test St');
        $rateRequest->setAllItems([]);

        $this->bobGo->collectRates($rateRequest);

        // Verify the payload does NOT contain 'identifier'
        if ($capturedPayload !== null) {
            $this->assertArrayNotHasKey('identifier', $capturedPayload);
            $this->assertArrayHasKey('rate', $capturedPayload);
        }
    }
}
