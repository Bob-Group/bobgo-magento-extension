<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Api;

use BobGroup\BobGo\Model\Config\ApiConfig;
use BobGroup\BobGo\Service\ConnectionHealth;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * HTTP client for all Bob Go API v2 communication.
 *
 * Provides GET, POST, PATCH, and DELETE methods with automatic Bearer token
 * authentication, JSON encoding/decoding, and structured error handling.
 * Uses Magento's CurlFactory for HTTP transport.
 *
 * API keys are read from ApiConfig and masked in error logs for security.
 */
class BobGoApiClient
{
    /**
     * Default ceiling for any single Bob Go API call. Earlier versions used
     * 30 s, which is long enough to make checkout feel broken when Bob Go
     * is unhealthy. 15 s is still generous (typical responses are sub-second)
     * while keeping a stalled call out of the customer's way.
     */
    private const REQUEST_TIMEOUT_SECONDS = 15;
    /**
     * Hard cap for time-sensitive paths (rates-at-checkout). The checkout
     * blocks on this call, so a faster failure beats a slow success.
     */
    private const RATES_TIMEOUT_SECONDS = 8;
    /**
     * Magento's Curl client uses CURLOPT_CONNECTTIMEOUT for the TCP
     * handshake. We override the default (which depends on PHP build) so a
     * black-holed DNS / firewall doesn't eat the whole request timeout
     * before we even get to send bytes.
     */
    private const CONNECT_TIMEOUT_SECONDS = 5;
    private const MIN_KEY_DISPLAY_LENGTH = 4;
    private const KEY_MASK = '****';

    /** Endpoints that block customer-facing flows and need the tighter ceiling. */
    private const FAST_PATH_ENDPOINTS = ['rates-at-checkout'];

    /**
     * @var ApiConfig
     */
    private ApiConfig $apiConfig;

    /**
     * @var CurlFactory
     */
    private CurlFactory $curlFactory;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @var ConnectionHealth
     */
    private ConnectionHealth $connectionHealth;

    public function __construct(
        ApiConfig $apiConfig,
        CurlFactory $curlFactory,
        LoggerInterface $logger,
        StoreManagerInterface $storeManager,
        ConnectionHealth $connectionHealth
    ) {
        $this->apiConfig = $apiConfig;
        $this->curlFactory = $curlFactory;
        $this->logger = $logger;
        $this->storeManager = $storeManager;
        $this->connectionHealth = $connectionHealth;
    }

    /**
     * @param string $endpoint
     * @param array<string,mixed> $queryParams
     * @return array<string,mixed>
     * @throws BobGoApiException
     */
    public function get(string $endpoint, array $queryParams = []): array
    {
        $url = $this->buildUrl($endpoint, $queryParams);
        $curl = $this->createCurl($endpoint);
        return $this->send($curl, $endpoint, static function () use ($curl, $url) {
            $curl->get($url);
        });
    }

    /**
     * @param string $endpoint
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     * @throws BobGoApiException
     */
    public function post(string $endpoint, array $payload): array
    {
        $url = $this->buildUrl($endpoint);
        $body = $this->encodePayload($payload, $endpoint);
        $curl = $this->createCurl($endpoint);
        return $this->send($curl, $endpoint, static function () use ($curl, $url, $body) {
            $curl->post($url, $body);
        });
    }

    /**
     * @param string $endpoint
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     * @throws BobGoApiException
     */
    public function patch(string $endpoint, array $payload): array
    {
        $url = $this->buildUrl($endpoint);
        $body = $this->encodePayload($payload, $endpoint);
        $curl = $this->createCurl($endpoint);
        $curl->setOption(CURLOPT_CUSTOMREQUEST, 'PATCH');
        return $this->send($curl, $endpoint, static function () use ($curl, $url, $body) {
            $curl->post($url, $body);
        });
    }

    /**
     * @param string $endpoint
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     * @throws BobGoApiException
     */
    public function delete(string $endpoint, array $payload = []): array
    {
        $url = $this->buildUrl($endpoint);
        $body = empty($payload) ? null : $this->encodePayload($payload, $endpoint);
        $curl = $this->createCurl($endpoint);

        $curl->setOption(CURLOPT_CUSTOMREQUEST, 'DELETE');
        return $this->send($curl, $endpoint, static function () use ($curl, $url, $body) {
            if ($body !== null) {
                $curl->post($url, $body);
            } else {
                $curl->get($url);
            }
        });
    }

    /**
     * Dispatch a prepared request and normalise the response.
     *
     * Magento's Curl client reports transport failures — connect timeout, read
     * timeout, DNS failure, TLS error — by throwing a bare \Exception from
     * Curl::doError(). Those must not escape as-is: nothing up the stack
     * catches them, and Shipping::collectCarrierRates() has no try/catch around
     * collectRates(), so a Bob Go outage would surface as a 500 on the checkout
     * shipping step instead of simply hiding our rates. Every failure mode
     * leaves this class as a BobGoApiException.
     *
     * A status code of 0 on the exception means the request never produced an
     * HTTP response at all (same convention as the missing-API-key case).
     *
     * @param callable $dispatch Performs the actual curl call
     * @return array<string,mixed>
     * @throws BobGoApiException
     */
    private function send(
        \Magento\Framework\HTTP\Client\Curl $curl,
        string $endpoint,
        callable $dispatch
    ): array {
        try {
            $dispatch();
        } catch (\Throwable $e) {
            // Status 0: we learned nothing about the credentials, so the health
            // state is deliberately left as it was.
            $this->connectionHealth->observe(0);
            $this->logger->error('Bob Go API transport failure', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
                'api_key' => $this->getMaskedApiKey(),
            ]);
            throw new BobGoApiException(
                sprintf('Bob Go API request to %s could not be completed: %s', $endpoint, $e->getMessage()),
                0,
                '',
                $endpoint,
                $e instanceof \Exception ? $e : null
            );
        }

        return $this->handleResponse($curl, $endpoint);
    }

    /**
     * @param array<string,mixed> $payload
     * @param string $endpoint
     * @return string
     * @throws BobGoApiException
     */
    private function encodePayload(array $payload, string $endpoint): string
    {
        $json = json_encode($payload);
        if ($json === false) {
            throw new BobGoApiException('Failed to encode request payload to JSON', 0, '', $endpoint);
        }
        return $json;
    }

    /**
     * @throws BobGoApiException
     * @return \Magento\Framework\HTTP\Client\Curl
     */
    private function createCurl(string $endpoint = ''): \Magento\Framework\HTTP\Client\Curl
    {
        $apiKey = $this->apiConfig->getApiKey();
        if ($apiKey === null) {
            throw new BobGoApiException('Bob Go API key is not configured', 0, '', '');
        }

        $curl = $this->curlFactory->create();
        $curl->addHeader('Content-Type', 'application/json');
        $curl->addHeader('Accept', 'application/json');
        $curl->addHeader('Authorization', 'Bearer ' . $apiKey);
        $curl->addHeader('bobgo-channel-identifier', $this->getChannelIdentifier());

        $timeout = in_array($endpoint, self::FAST_PATH_ENDPOINTS, true)
            ? self::RATES_TIMEOUT_SECONDS
            : self::REQUEST_TIMEOUT_SECONDS;
        $curl->setOption(CURLOPT_TIMEOUT, $timeout);
        $curl->setOption(CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT_SECONDS);

        return $curl;
    }

    /**
     * Canonical store identifier used by Bob Go to associate inbound API calls
     * with the right channel. Format: "host[/path]" — no scheme, no trailing
     * slash. The scheme is omitted because a ":" in the header value breaks
     * downstream parsers that split on the first colon.
     */
    private function getChannelIdentifier(): string
    {
        $baseUrl = $this->storeManager->getStore()->getBaseUrl();
        $withoutScheme = (string)preg_replace('#^https?://#i', '', $baseUrl);
        return rtrim($withoutScheme, '/');
    }

    /**
     * @param string $endpoint
     * @param array<string,mixed> $queryParams
     * @return string
     */
    private function buildUrl(string $endpoint, array $queryParams = []): string
    {
        $url = $this->apiConfig->getBaseUrl() . ltrim($endpoint, '/');
        if (!empty($queryParams)) {
            $url .= '?' . http_build_query($queryParams);
        }
        return $url;
    }

    /**
     * @param \Magento\Framework\HTTP\Client\Curl $curl
     * @param string $endpoint
     * @return array<string,mixed>
     * @throws BobGoApiException
     */
    private function handleResponse(\Magento\Framework\HTTP\Client\Curl $curl, string $endpoint): array
    {
        $statusCode = $curl->getStatus();
        $responseBody = $curl->getBody();

        // Write-through health tracking: a key revoked on the Bob Go side shows up
        // here, on ordinary traffic, without anyone pressing a Test button.
        $this->connectionHealth->observe((int) $statusCode);

        if ($statusCode >= 400) {
            $maskedKey = $this->getMaskedApiKey();
            // Cap response body in logs — Bob Go's 4xx/5xx responses usually
            // echo the offending payload back, which can include PII like
            // email/phone/address that we don't want recurring in system.log.
            $this->logger->error(
                'Bob Go API error',
                [
                    'endpoint' => $endpoint,
                    'status_code' => $statusCode,
                    'response_snippet' => substr($responseBody, 0, 512),
                    'api_key' => $maskedKey,
                ]
            );
            throw new BobGoApiException(
                sprintf('Bob Go API request to %s failed with status %d', $endpoint, $statusCode),
                $statusCode,
                $responseBody,
                $endpoint
            );
        }

        if (empty($responseBody)) {
            return [];
        }

        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            $this->logger->warning('Bob Go API returned non-JSON response', [
                'endpoint' => $endpoint,
                'response' => substr($responseBody, 0, 500),
            ]);
            return [];
        }

        return $decoded;
    }

    private function getMaskedApiKey(): string
    {
        $apiKey = $this->apiConfig->getApiKey();
        if ($apiKey === null || strlen($apiKey) < self::MIN_KEY_DISPLAY_LENGTH) {
            return self::KEY_MASK;
        }
        return self::KEY_MASK . substr($apiKey, -4);
    }
}
