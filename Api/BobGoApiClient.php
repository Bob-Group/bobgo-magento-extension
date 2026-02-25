<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Api;

use BobGroup\BobGo\Model\Config\ApiConfig;
use Magento\Framework\HTTP\Client\CurlFactory;
use Psr\Log\LoggerInterface;

/**
 * HTTP client for all Bob Go API v2 communication.
 *
 * Provides GET, POST, PATCH, and DELETE methods with automatic Bearer token
 * authentication, JSON encoding/decoding, and structured error handling.
 * Uses Magento's CurlFactory for HTTP transport with a 30-second timeout.
 *
 * API keys are read from ApiConfig and masked in error logs for security.
 */
class BobGoApiClient
{
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

    public function __construct(
        ApiConfig $apiConfig,
        CurlFactory $curlFactory,
        LoggerInterface $logger
    ) {
        $this->apiConfig = $apiConfig;
        $this->curlFactory = $curlFactory;
        $this->logger = $logger;
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

        $payloadJson = json_encode($payload);
        if ($payloadJson === false) {
            throw new BobGoApiException('Failed to encode request payload to JSON', 0, '', $endpoint);
        }

        $curl->post($url, $payloadJson);
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

        $payloadJson = json_encode($payload);
        if ($payloadJson === false) {
            throw new BobGoApiException('Failed to encode request payload to JSON', 0, '', $endpoint);
        }

        $curl->setOption(CURLOPT_CUSTOMREQUEST, 'PATCH');
        $curl->post($url, $payloadJson);
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
            $payloadJson = json_encode($payload);
            if ($payloadJson === false) {
                throw new BobGoApiException('Failed to encode request payload to JSON', 0, '', $endpoint);
            }
            $curl->post($url, $payloadJson);
        } else {
            $curl->get($url);
        }
        return $this->handleResponse($curl, $endpoint);
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
        $curl->addHeader('Authorization', 'Bearer ' . $apiKey);
        $curl->setOption(CURLOPT_TIMEOUT, 30);
        return $curl;
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
            return [];
        }

        return $decoded;
    }

    private function getMaskedApiKey(): string
    {
        $apiKey = $this->apiConfig->getApiKey();
        if ($apiKey === null || strlen($apiKey) < 4) {
            return '****';
        }
        return '****' . substr($apiKey, -4);
    }
}
