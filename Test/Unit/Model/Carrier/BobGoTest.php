<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Model\Carrier;

use BobGroup\BobGo\Model\Carrier\BobGo;
use BobGroup\BobGo\Model\Carrier\AdditionalInfo;
use BobGroup\BobGo\Api\BobGoApiClient;
use BobGroup\BobGo\Api\BobGoApiException;
use BobGroup\BobGo\Model\Config\ApiConfig;
use BobGroup\BobGo\Service\RateCache;
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

    /** @var RateCache|\PHPUnit\Framework\MockObject\MockObject */
    private $rateCacheMock;

    protected function setUp(): void
    {
        $this->storeManagerMock = $this->createMock(StoreManagerInterface::class);
        $this->scopeConfigMock = $this->createMock(ScopeConfigInterface::class);
        $this->apiClientMock = $this->createMock(BobGoApiClient::class);
        $this->apiConfigMock = $this->createMock(ApiConfig::class);
        $this->resultFactoryMock = $this->createMock(ResultFactory::class);
        $this->methodFactoryMock = $this->createMock(MethodFactory::class);
        $this->additionalInfoMock = $this->createMock(AdditionalInfo::class);
        $this->rateCacheMock = $this->createMock(RateCache::class);

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
            $this->rateCacheMock,
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

        $methodMock = $this->createMock(\Magento\Quote\Model\Quote\Address\RateResult\Method::class);
        $this->methodFactoryMock->method('create')->willReturn($methodMock);

        // Capture the payload sent to apiClient
        $capturedPayload = null;
        $this->apiClientMock->method('post')
            ->willReturnCallback(function ($endpoint, $payload) use (&$capturedPayload) {
                $capturedPayload = $payload;
                return ['rates' => [['service_name' => 'Standard', 'service_code' => 'STD', 'total_price' => 100.00, 'min_delivery_date' => '', 'max_delivery_date' => '']]];
            });

        $rateRequest = new RateRequest();
        $rateRequest->setDestPostcode('2196');
        $rateRequest->setDestCountryId('ZA');
        $rateRequest->setDestRegionCode('GT');
        $rateRequest->setDestCity('Sandton');
        $rateRequest->setDestStreet('1 Test St');
        $rateRequest->setAllItems([]);

        $this->bobGo->collectRates($rateRequest);

        // Verify the v2 payload structure does NOT contain 'identifier'
        $this->assertNotNull($capturedPayload, 'API should have been called with a payload');
        $this->assertArrayNotHasKey('identifier', $capturedPayload);
        $this->assertArrayHasKey('collection_address', $capturedPayload);
        $this->assertArrayHasKey('delivery_address', $capturedPayload);
        $this->assertArrayHasKey('items', $capturedPayload);
    }

    /**
     * Fail soft. Magento invokes collectRates() with no try/catch of its own
     * (Shipping::collectCarrierRates), so anything escaping here 500s the
     * checkout shipping step and the cart estimator for every customer —
     * including stores that also offer other carriers. Whatever goes wrong, the
     * carrier must simply not appear.
     *
     * @dataProvider thrownFromApiProvider
     */
    public function testCollectRatesHidesCarrierWhenSomethingThrows(\Throwable $thrown): void
    {
        $this->scopeConfigMock->method('getValue')->willReturn('test_value');
        $this->scopeConfigMock->method('isSetFlag')->willReturn(true);

        $this->additionalInfoMock->method('getDestComp')->willReturn('Test Co');
        $this->additionalInfoMock->method('getSuburb')->willReturn('Test Suburb');

        $storeMock = $this->createMock(\Magento\Store\Model\Store::class);
        $storeMock->method('getBaseUrl')->willReturn('https://example.com/');
        $this->storeManagerMock->method('getStore')->willReturn($storeMock);

        $this->resultFactoryMock->method('create')
            ->willReturn($this->createMock(\Magento\Shipping\Model\Rate\Result::class));

        $this->apiClientMock->method('post')->willReturnCallback(
            static function () use ($thrown) {
                throw $thrown;
            }
        );

        $rateRequest = new RateRequest();
        $rateRequest->setDestPostcode('2196');
        $rateRequest->setDestCountryId('ZA');
        $rateRequest->setDestCity('Sandton');
        $rateRequest->setDestStreet('1 Test St');
        $rateRequest->setAllItems([]);

        $this->assertFalse($this->bobGo->collectRates($rateRequest));
    }

    /**
     * @return array<string,array{0:\Throwable}>
     */
    public function thrownFromApiProvider(): array
    {
        return [
            // What Magento's Curl client throws on a timeout or DNS failure.
            'bare exception' => [new \Exception('Operation timed out')],
            // And a hard error, so the guard is genuinely \Throwable-wide.
            'error' => [new \TypeError('unexpected null')],
        ];
    }

    // ------------------------------------------------------- rate payload money fields

    /**
     * declared_value and order_total_price are different numbers and both are
     * required. Merchants configure free-shipping-over-X on Bob Go against the
     * POST-discount total; sending only the pre-discount value gave WooCommerce
     * shoppers free shipping they hadn't earned and denied it to those who had.
     * We used to send a hardcoded declared_value of 0 and no total at all.
     */
    public function testRatePayloadCarriesPreAndPostDiscountValues(): void
    {
        $captured = $this->captureRatePayload(function (RateRequest $request) {
            $request->setPackagePhysicalValue(500.00);
            $request->setPackageValue(500.00);
            $request->setPackageValueWithDiscount(450.00);
        });

        $this->assertSame(500.00, $captured['declared_value']);
        $this->assertSame(450.00, $captured['order_total_price']);
        $this->assertArrayHasKey('handling_time', $captured);
    }

    /**
     * A 100%-off shipping promo legitimately produces a zero total, and zero is
     * meaningfully different from "not supplied".
     */
    public function testPostDiscountTotalOfZeroIsSentAsZero(): void
    {
        $captured = $this->captureRatePayload(function (RateRequest $request) {
            $request->setPackageValue(500.00);
            $request->setPackageValueWithDiscount(0.0);
        });

        $this->assertSame(0.0, $captured['order_total_price']);
    }

    public function testDeclaredValueFallsBackToSummingTheItems(): void
    {
        $quoteItem = $this->createMock(\Magento\Quote\Model\Quote\Item::class);
        $quoteItem->method('getName')->willReturn('Widget');
        $quoteItem->method('getQty')->willReturn(2);
        $quoteItem->method('getPrice')->willReturn(125.00);
        $quoteItem->method('getWeight')->willReturn(1.0);

        $captured = $this->captureRatePayload(
            static function (RateRequest $request) {
                // Neither package value populated — some flows don't set them.
            },
            [$quoteItem]
        );

        $this->assertSame(250.00, $captured['declared_value']);
    }

    // ------------------------------------------------------------------ free shipping

    /**
     * A cart rule already granted free shipping, so there is nothing to price.
     *
     * The short-circuit must happen before the cache is consulted: the
     * free-shipping flag is not part of the cache key, so zeroing a *cached* rate
     * would leak free shipping to the next cart with the same basket and address
     * and no coupon.
     */
    public function testFreeShippingSkipsTheApiAndTheCacheEntirely(): void
    {
        $this->scopeConfigMock->method('isSetFlag')->willReturn(true);
        $this->scopeConfigMock->method('getValue')->willReturn('test_value');

        $resultMock = $this->createMock(\Magento\Shipping\Model\Rate\Result::class);
        $this->resultFactoryMock->method('create')->willReturn($resultMock);

        $method = $this->createMock(\Magento\Quote\Model\Quote\Address\RateResult\Method::class);
        $method->expects($this->once())->method('setMethod')->with(BobGo::FREE_SHIPPING_METHOD);
        $method->expects($this->once())->method('setPrice')->with(0.0);
        $this->methodFactoryMock->method('create')->willReturn($method);

        $this->apiClientMock->expects($this->never())->method('post');
        $this->rateCacheMock->expects($this->never())->method('load');
        $this->rateCacheMock->expects($this->never())->method('save');

        $resultMock->expects($this->once())->method('append')->with($method);

        $request = new RateRequest();
        $request->setDestCountryId('ZA');
        $request->setDestPostcode('2196');
        $request->setAllItems([]);
        $request->setFreeShipping(true);

        $this->bobGo->collectRates($request);
    }

    // ------------------------------------------------------------------------ caching

    public function testCachedRatesAreServedWithoutCallingTheApi(): void
    {
        $this->rateCacheMock->method('load')->willReturn(['rates' => [['service_name' => 'Cached']]]);
        $this->rateCacheMock->method('isNegative')->willReturn(false);
        $this->apiClientMock->expects($this->never())->method('post');

        $this->assertSame(
            ['rates' => [['service_name' => 'Cached']]],
            $this->bobGo->getRates(['any' => 'payload'])
        );
    }

    public function testANegativeCacheEntryMeansNoRatesWithoutCallingTheApi(): void
    {
        $this->rateCacheMock->method('load')->willReturn(['__bobgo' => 'no_rates']);
        $this->rateCacheMock->method('isNegative')->willReturn(true);
        $this->apiClientMock->expects($this->never())->method('post');

        $this->assertEmpty($this->bobGo->getRates(['any' => 'payload']));
    }

    public function testAFailedCallIsCachedBrieflySoAnOutageIsNotHammered(): void
    {
        $this->rateCacheMock->method('load')->willReturn(null);
        $this->apiClientMock->method('post')
            ->willThrowException(new BobGoApiException('timeout', 0, '', 'rates-at-checkout'));

        $this->rateCacheMock->expects($this->once())->method('saveFailure');
        $this->rateCacheMock->expects($this->never())->method('save');

        $this->assertEmpty($this->bobGo->getRates(['any' => 'payload']));
    }

    public function testSuccessfulRatesAreCached(): void
    {
        $this->rateCacheMock->method('load')->willReturn(null);
        $this->apiClientMock->method('post')->willReturn(['rates' => [['service_name' => 'Standard']]]);

        $this->rateCacheMock->expects($this->once())->method('save');

        $this->bobGo->getRates(['any' => 'payload']);
    }

    // ----------------------------------------------------------------------- helpers

    /**
     * Run collectRates() and hand back the payload that reached the API.
     *
     * @param callable $prepare Receives the RateRequest before collection
     * @param array<int,object> $items
     * @return array<string,mixed>
     */
    private function captureRatePayload(callable $prepare, array $items = []): array
    {
        $this->scopeConfigMock->method('getValue')->willReturn('test_value');
        $this->scopeConfigMock->method('isSetFlag')->willReturn(true);
        $this->additionalInfoMock->method('getDestComp')->willReturn('Test Co');
        $this->additionalInfoMock->method('getSuburb')->willReturn('Sandton');

        $storeMock = $this->createMock(\Magento\Store\Model\Store::class);
        $storeMock->method('getBaseUrl')->willReturn('https://example.com/');
        $this->storeManagerMock->method('getStore')->willReturn($storeMock);

        $this->resultFactoryMock->method('create')
            ->willReturn($this->createMock(\Magento\Shipping\Model\Rate\Result::class));
        $this->methodFactoryMock->method('create')
            ->willReturn($this->createMock(\Magento\Quote\Model\Quote\Address\RateResult\Method::class));

        $this->rateCacheMock->method('load')->willReturn(null);

        $captured = [];
        $this->apiClientMock->method('post')
            ->willReturnCallback(function ($endpoint, $payload) use (&$captured) {
                $captured = $payload;
                return ['rates' => [['service_name' => 'Standard', 'total_price' => 100.0]]];
            });

        $request = new RateRequest();
        $request->setDestCountryId('ZA');
        $request->setDestPostcode('2196');
        $request->setDestCity('Sandton');
        $request->setDestStreet('1 Test St');
        $request->setAllItems($items);
        $prepare($request);

        $this->bobGo->collectRates($request);

        return $captured;
    }
}
