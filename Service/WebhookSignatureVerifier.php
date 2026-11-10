<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Service;

use BobGroup\BobGo\Model\Config\ApiConfig;
use Psr\Log\LoggerInterface;

/**
 * Verifies inbound Bob Go webhook signatures.
 *
 * Bob Go signs the raw request body with HMAC-SHA256 using the merchant-issued
 * webhook secret and sends the base64-encoded digest in the Bobgo-Webhook-Signature
 * header. We recompute the digest over the same byte sequence and compare in
 * constant time. Any failure mode — missing secret, missing header, mismatch —
 * returns false. The caller must 403 and never inspect the body.
 *
 * Pulled out of the webhook controller so the verification can be unit-tested
 * without standing up the full Magento action stack.
 */
class WebhookSignatureVerifier
{
    private ApiConfig $apiConfig;
    private LoggerInterface $logger;

    public function __construct(ApiConfig $apiConfig, LoggerInterface $logger)
    {
        $this->apiConfig = $apiConfig;
        $this->logger = $logger;
    }

    /**
     * @param string $rawBody The raw, undecoded request body
     * @param string|null $providedSignature Value of the Bobgo-Webhook-Signature header
     */
    public function verify(string $rawBody, ?string $providedSignature): bool
    {
        $secret = $this->apiConfig->getWebhookSecret();
        if ($secret === null) {
            $this->logger->warning('Bob Go webhook rejected: webhook secret not configured');
            return false;
        }

        if ($providedSignature === null || $providedSignature === '') {
            $this->logger->warning('Bob Go webhook rejected: missing signature header');
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));
        return hash_equals($expected, $providedSignature);
    }
}
