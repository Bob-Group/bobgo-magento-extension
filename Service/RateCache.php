<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Service;

use Magento\Framework\App\CacheInterface;
use Psr\Log\LoggerInterface;

/**
 * Caches rates-at-checkout responses.
 *
 * The cart page is the flood source. Its shipping estimator recalculates on
 * every keystroke-ish change with a *coarse* address — country, region and
 * postcode, no street and no suburb — and those rates are approximate and
 * display-only. Charging the API at checkout frequency for them is pure waste,
 * so the TTL is split by address precision:
 *
 *   coarse (cart estimate)    2 hours   — approximate anyway
 *   complete (checkout)      15 minutes — this is the price we will charge, so
 *                                         it must not go stale
 *
 * Two more tiers on top:
 *
 *   - An in-request memo, because one checkout calculation triggers several rate
 *     lookups (Magento re-collects totals more than once per request).
 *   - A short negative entry for errors and empty results, so a Bob Go outage
 *     doesn't turn into one upstream call per cart recalculation.
 *
 * The cache key is a hash of the whole request payload, so any change to origin,
 * destination or basket contents misses cleanly.
 */
class RateCache
{
    public const CACHE_TAG = 'BOBGO_RATES';

    private const KEY_PREFIX = 'bobgo_rates_';

    /** Rates for an address precise enough to be charged against. */
    private const TTL_COMPLETE_ADDRESS = 900;

    /** Rates for a cart-page estimate: approximate, display-only. */
    private const TTL_COARSE_ADDRESS = 7200;

    /** Errors and empty results. Long enough to absorb a burst, short enough
     *  that recovery is quick. */
    private const TTL_NEGATIVE = 30;

    /** Marker for "we asked, and there are no rates" — distinct from a miss. */
    private const NO_RATES = ['__bobgo' => 'no_rates'];

    private CacheInterface $cache;
    private LoggerInterface $logger;

    /**
     * In-request memo. Same lifetime as the request, so it needs no TTL.
     *
     * @var array<string,array<string,mixed>>
     */
    private array $memo = [];

    public function __construct(CacheInterface $cache, LoggerInterface $logger)
    {
        $this->cache = $cache;
        $this->logger = $logger;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|null The cached entry, or null on a miss
     */
    public function load(array $payload): ?array
    {
        $key = $this->key($payload);

        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        try {
            $raw = $this->cache->load($key);
        } catch (\Throwable $e) {
            $this->logger->warning('Bob Go: rate cache read failed', ['error' => $e->getMessage()]);
            return null;
        }

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $this->memo[$key] = $decoded;
        return $decoded;
    }

    /**
     * Is this entry the "no rates available" marker rather than real rates?
     *
     * @param array<string,mixed> $entry
     */
    public function isNegative(array $entry): bool
    {
        return $entry === self::NO_RATES;
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $rates
     */
    public function save(array $payload, array $rates): void
    {
        if (empty($rates['rates'])) {
            // Nothing to show. Cache it briefly rather than re-asking on every
            // recalculation, but don't hold onto it — the merchant may be in the
            // middle of configuring rates on Bob Go.
            $this->store($payload, self::NO_RATES, self::TTL_NEGATIVE);
            return;
        }

        $this->store($payload, $rates, $this->ttlFor($payload));
    }

    /**
     * Record that the call failed, so a Bob Go outage costs one request per 30s
     * rather than one per cart recalculation.
     *
     * @param array<string,mixed> $payload
     */
    public function saveFailure(array $payload): void
    {
        $this->store($payload, self::NO_RATES, self::TTL_NEGATIVE);
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $entry
     */
    private function store(array $payload, array $entry, int $ttl): void
    {
        $key = $this->key($payload);
        $this->memo[$key] = $entry;

        try {
            $this->cache->save((string) json_encode($entry), $key, [self::CACHE_TAG], $ttl);
        } catch (\Throwable $e) {
            // A cache we can't write to is a performance problem, not a
            // correctness one.
            $this->logger->warning('Bob Go: rate cache write failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * A delivery address with no street and no suburb is a cart-page estimate:
     * Magento only has what the shopper typed into the estimator.
     *
     * @param array<string,mixed> $payload
     */
    private function ttlFor(array $payload): int
    {
        $destination = is_array($payload['delivery_address'] ?? null) ? $payload['delivery_address'] : [];
        $street = trim((string) ($destination['street_address'] ?? ''));
        $suburb = trim((string) ($destination['local_area'] ?? ''));

        return ($street === '' && $suburb === '')
            ? self::TTL_COARSE_ADDRESS
            : self::TTL_COMPLETE_ADDRESS;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function key(array $payload): string
    {
        return self::KEY_PREFIX . sha1((string) json_encode($payload));
    }
}
