<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Service;

use BobGroup\BobGo\Model\Config\ApiConfig;
use BobGroup\BobGo\Service\RateCache;
use Magento\Framework\App\CacheInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The cart page is the API flood source: its shipping estimator recalculates
 * constantly, with an address so coarse (country/region/postcode only) that the
 * rates are approximate and display-only. Paying checkout-frequency API calls
 * for those was the single biggest waste available to remove.
 */
class RateCacheTest extends TestCase
{
    private $cache;
    private $logger;
    /** @var RateCache */
    private $rateCache;

    /** @var array<string,array{data:string,ttl:int|null}> */
    private $stored = [];

    /** The account the next lookup is made under. */
    private $environment = 'production';
    private $apiKey = 'key-a';
    private $storeId = 1;

    protected function setUp(): void
    {
        $this->cache = $this->createMock(CacheInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->cache->method('save')->willReturnCallback(
            function ($data, $identifier, $tags = [], $lifeTime = null) {
                $this->stored[$identifier] = ['data' => $data, 'ttl' => $lifeTime];
                return true;
            }
        );
        $this->cache->method('load')->willReturnCallback(
            function ($identifier) {
                return $this->stored[$identifier]['data'] ?? false;
            }
        );

        $this->rateCache = $this->newRateCache($this->cache, $this->logger);
    }

    /**
     * Rates belong to an account. The same basket asked under another
     * environment, key or store must not be answered from the first one's entry.
     *
     * @dataProvider accountChanges
     */
    public function testAnotherAccountDoesNotShareAnEntry(string $property, $value): void
    {
        $this->rateCache->save($this->payload(), $this->rates());

        $this->{$property} = $value;
        $fresh = $this->newRateCache($this->cache, $this->logger);

        $this->assertNull($fresh->load($this->payload()));
    }

    /**
     * @return array<string,array{0:string,1:mixed}>
     */
    public function accountChanges(): array
    {
        return [
            'sandbox to production' => ['environment', 'sandbox'],
            'another API key' => ['apiKey', 'key-b'],
            'another store' => ['storeId', 2],
        ];
    }

    /**
     * A keyless (1.0.x) checkout is charged against, so it gets the short TTL
     * too, not the cart estimate's two hours.
     */
    public function testKeylessCheckoutGetsTheShortTtl(): void
    {
        $this->rateCache->save([
            'identifier' => 'shop.co.za',
            'rate' => ['destination' => ['address1' => '1 Test Street', 'suburb' => 'Sandton']],
        ], $this->rates());

        $this->assertSame(900, $this->onlyStoredTtl());
    }

    /**
     * Once Bob Go is known to be down, no cart waits for it, whatever its basket.
     */
    public function testUnavailableIsSharedAcrossBaskets(): void
    {
        $this->assertFalse($this->rateCache->isUnavailable());

        $this->rateCache->markUnavailable();

        $this->assertTrue($this->newRateCache($this->cache, $this->logger)->isUnavailable());
        $this->assertSame(30, $this->onlyStoredTtl());
    }

    public function testMissReturnsNull(): void
    {
        $this->assertNull($this->rateCache->load($this->payload()));
    }

    public function testRoundTripsRates(): void
    {
        $payload = $this->payload();
        $rates = ['rates' => [['service_name' => 'Standard', 'total_price' => 100]]];

        $this->rateCache->save($payload, $rates);

        $loaded = $this->rateCache->load($payload);
        $this->assertSame($rates, $loaded);
        $this->assertFalse($this->rateCache->isNegative($loaded));
    }

    /**
     * A complete address is the one we will actually charge against, so its
     * rates must not go stale.
     */
    public function testCompleteAddressGetsTheShortTtl(): void
    {
        $this->rateCache->save($this->payload('1 Test Street', 'Sandton'), $this->rates());

        $this->assertSame(900, $this->onlyStoredTtl());
    }

    /**
     * No street and no suburb means Magento only has what the shopper typed into
     * the cart estimator — approximate by nature, so cache it hard.
     */
    public function testCoarseAddressGetsTheLongTtl(): void
    {
        $this->rateCache->save($this->payload('', ''), $this->rates());

        $this->assertSame(7200, $this->onlyStoredTtl());
    }

    public function testFailuresAreCachedBrieflyAndReadBackAsNegative(): void
    {
        $payload = $this->payload();

        $this->rateCache->saveFailure($payload);

        $this->assertSame(30, $this->onlyStoredTtl());
        $loaded = $this->rateCache->load($payload);
        $this->assertNotNull($loaded);
        $this->assertTrue($this->rateCache->isNegative($loaded));
    }

    /**
     * An empty rate list is a real answer ("this merchant has no rates for this
     * address"), but it gets the short TTL because the merchant may be part-way
     * through configuring rates on Bob Go.
     */
    public function testAnEmptyRateListIsStoredAsNegative(): void
    {
        $payload = $this->payload();

        $this->rateCache->save($payload, ['rates' => [], 'count' => 0]);

        $this->assertSame(30, $this->onlyStoredTtl());
        $this->assertTrue($this->rateCache->isNegative((array) $this->rateCache->load($payload)));
    }

    /**
     * One checkout calculation triggers several rate lookups, so repeated loads
     * inside a request must not each hit the cache backend.
     */
    public function testRepeatedLoadsUseTheInRequestMemo(): void
    {
        $payload = $this->payload();
        $this->rateCache->save($payload, $this->rates());

        $this->cache->expects($this->never())->method('load');

        $this->rateCache->load($payload);
        $this->rateCache->load($payload);
    }

    public function testDifferentPayloadsDoNotShareAnEntry(): void
    {
        $this->rateCache->save($this->payload('1 Test Street', 'Sandton'), $this->rates());

        $this->assertNull($this->rateCache->load($this->payload('2 Other Road', 'Rosebank')));
    }

    /**
     * A cache we can't reach is a performance problem, not a correctness one.
     */
    public function testABrokenCacheDegradesToAMiss(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willThrowException(new \RuntimeException('redis down'));
        $cache->method('save')->willThrowException(new \RuntimeException('redis down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('warning');

        $rateCache = $this->newRateCache($cache, $logger);
        $rateCache->save($this->payload(), $this->rates());

        // The memo still answers within the request; a fresh instance misses.
        $this->assertNull($this->newRateCache($cache, $logger)->load($this->payload()));
    }

    // ----------------------------------------------------------------------- helpers

    /**
     * @return array<string,mixed>
     */
    private function payload(string $street = '1 Test Street', string $suburb = 'Sandton'): array
    {
        return [
            'collection_address' => ['city' => 'Cape Town', 'code' => '8001'],
            'delivery_address' => [
                'street_address' => $street,
                'local_area' => $suburb,
                'city' => 'Johannesburg',
                'code' => '2196',
            ],
            'items' => [['description' => 'Widget', 'quantity' => 1, 'weight_kg' => 1.0]],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function rates(): array
    {
        return ['rates' => [['service_name' => 'Standard', 'total_price' => 100]]];
    }

    private function newRateCache($cache, $logger): RateCache
    {
        $apiConfig = $this->createMock(ApiConfig::class);
        $apiConfig->method('getEnvironment')->willReturnCallback(function () {
            return $this->environment;
        });
        $apiConfig->method('getApiKey')->willReturnCallback(function () {
            return $this->apiKey;
        });

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturnCallback(function () {
            return $this->storeId;
        });
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new RateCache($cache, $logger, $apiConfig, $storeManager);
    }

    private function onlyStoredTtl(): ?int
    {
        $this->assertCount(1, $this->stored);
        return reset($this->stored)['ttl'];
    }
}
