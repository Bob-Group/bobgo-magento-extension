<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Api;

use Magento\Framework\Exception\LocalizedException;

/**
 * Exception thrown when a Bob Go API request fails.
 *
 * Carries the HTTP status code, raw response body, and the endpoint that
 * was called, so callers can log or react to specific failure conditions.
 */
class BobGoApiException extends LocalizedException
{
    /**
     * @var int HTTP status code from the API response
     */
    private int $statusCode;

    /**
     * @var string Raw response body from the API
     */
    private string $responseBody;

    /**
     * @var string The API endpoint that was called (e.g. 'orders', 'rates-at-checkout')
     */
    private string $endpoint;

    /**
     * @param string $message Human-readable error message
     * @param int $statusCode HTTP status code (0 if not an HTTP error)
     * @param string $responseBody Raw API response body
     * @param string $endpoint The API endpoint that failed
     * @param \Exception|null $previous Previous exception for chaining
     */
    public function __construct(
        string $message,
        int $statusCode = 0,
        string $responseBody = '',
        string $endpoint = '',
        ?\Exception $previous = null
    ) {
        $this->statusCode = $statusCode;
        $this->responseBody = $responseBody;
        $this->endpoint = $endpoint;
        parent::__construct(__($message), $previous, $statusCode);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getResponseBody(): string
    {
        return $this->responseBody;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }
}
