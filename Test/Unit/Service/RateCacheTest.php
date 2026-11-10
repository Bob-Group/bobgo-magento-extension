<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Service;

use BobGroup\BobGo\Service\RateCache;
use Magento\Framework\App\CacheInterface;
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

        $this->rateCache = new RateCache($this->cache, $this->logger);
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

        $rateCache = new RateCache($cache, $logger);
        $rateCache->save($this->payload(), $this->rates());

        // The memo still answers within the request; a fresh instance misses.
        $this->assertNull((new RateCache($cache, $logger))->load($this->payload()));
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

    private function onlyStoredTtl(): ?int
    {
        $this->assertCount(1, $this->stored);
        return reset($this->stored)['ttl'];
    }
}
