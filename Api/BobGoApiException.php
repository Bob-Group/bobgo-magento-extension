<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Api;

use Magento\Framework\Exception\LocalizedException;

class BobGoApiException extends LocalizedException
{
    /**
     * @var int
     */
    private int $statusCode;

    /**
     * @var string
     */
    private string $responseBody;

    /**
     * @var string
     */
    private string $endpoint;

    public function __construct(
        string $message,
        int $statusCode = 0,
        string $responseBody = '',
        string $endpoint = '',
        \Exception $previous = null
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
