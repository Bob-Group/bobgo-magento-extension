<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Api;

use BobGroup\BobGo\Model\Config\ApiConfig;
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
    private const REQUEST_TIMEOUT_SECONDS = 30;
    private const MIN_KEY_DISPLAY_LENGTH = 4;
    private const KEY_MASK = '****';

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

    public function __construct(
        ApiConfig $apiConfig,
        CurlFactory $curlFactory,
        LoggerInterface $logger,
        StoreManagerInterface $storeManager
    ) {
        $this->apiConfig = $apiConfig;
        $this->curlFactory = $curlFactory;
        $this->logger = $logger;
        $this->storeManager = $storeManager;
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
        $curl = $this->createCurl();
        $curl->get($url);
        return $this->handleResponse($curl, $endpoint);
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
        $curl = $this->createCurl();
        $curl->post($url, $this->encodePayload($payload, $endpoint));
        return $this->handleResponse($curl, $endpoint);
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
        $curl = $this->createCurl();
        $curl->setOption(CURLOPT_CUSTOMREQUEST, 'PATCH');
        $curl->post($url, $this->encodePayload($payload, $endpoint));
        return $this->handleResponse($curl, $endpoint);
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
        $curl = $this->createCurl();

        $curl->setOption(CURLOPT_CUSTOMREQUEST, 'DELETE');
        if (!empty($payload)) {
            $curl->post($url, $this->encodePayload($payload, $endpoint));
        } else {
            $curl->get($url);
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
     * @return \Magento\Framework\HTTP\Client\Curl
     * @throws BobGoApiException
     */
    private function createCurl(): \Magento\Framework\HTTP\Client\Curl
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
        $curl->setOption(CURLOPT_TIMEOUT, self::REQUEST_TIMEOUT_SECONDS);
        return $curl;
    }

    /**
     * Canonical store URL used by Bob Go to associate inbound API calls with
     * the right channel. Must remain stable for the lifetime of the integration.
     */
    private function getChannelIdentifier(): string
    {
        return rtrim($this->storeManager->getStore()->getBaseUrl(), '/');
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

        if ($statusCode >= 400) {
            $maskedKey = $this->getMaskedApiKey();
            $this->logger->error(
                'Bob Go API error',
                [
                    'endpoint' => $endpoint,
                    'status_code' => $statusCode,
                    'response' => $responseBody,
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
