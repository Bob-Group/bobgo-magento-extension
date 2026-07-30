<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Service;

use BobGroup\BobGo\Model\Config\ApiConfig;
use BobGroup\BobGo\Service\WebhookSignatureVerifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class WebhookSignatureVerifierTest extends TestCase
{
    private const SECRET = 'shhh-very-secret';

    private $apiConfigMock;
    private $loggerMock;
    /** @var WebhookSignatureVerifier */
    private $verifier;

    protected function setUp(): void
    {
        $this->apiConfigMock = $this->createMock(ApiConfig::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->verifier = new WebhookSignatureVerifier($this->apiConfigMock, $this->loggerMock);
    }

    public function testAcceptsCorrectSignature(): void
    {
        $body = '{"event_id":"evt_1","topic":"fulfillment/created"}';
        $expected = base64_encode(hash_hmac('sha256', $body, self::SECRET, true));

        $this->apiConfigMock->method('getWebhookSecret')->willReturn(self::SECRET);

        $this->assertTrue($this->verifier->verify($body, $expected));
    }

    public function testRejectsWrongSignature(): void
    {
        $this->apiConfigMock->method('getWebhookSecret')->willReturn(self::SECRET);

        $this->assertFalse($this->verifier->verify('{}', 'definitely-not-the-right-hmac'));
    }

    public function testRejectsTamperedBody(): void
    {
        $original  = '{"order_id":1}';
        $tampered  = '{"order_id":2}';
        $signature = base64_encode(hash_hmac('sha256', $original, self::SECRET, true));

        $this->apiConfigMock->method('getWebhookSecret')->willReturn(self::SECRET);

        // Signature was computed over $original — verifying $tampered must fail.
        $this->assertFalse($this->verifier->verify($tampered, $signature));
    }

    public function testRejectsWhenSecretNotConfigured(): void
    {
        $this->apiConfigMock->method('getWebhookSecret')->willReturn(null);
        $this->loggerMock->expects($this->once())->method('warning');

        $this->assertFalse($this->verifier->verify('{}', 'any-signature'));
    }

    public function testRejectsWhenSignatureMissing(): void
    {
        $this->apiConfigMock->method('getWebhookSecret')->willReturn(self::SECRET);
        $this->loggerMock->expects($this->once())->method('warning');

        $this->assertFalse($this->verifier->verify('{}', null));
    }

    public function testRejectsWhenSignatureEmpty(): void
    {
        $this->apiConfigMock->method('getWebhookSecret')->willReturn(self::SECRET);
        $this->loggerMock->expects($this->once())->method('warning');

        $this->assertFalse($this->verifier->verify('{}', ''));
    }

    public function testRejectsSignatureUnderDifferentSecret(): void
    {
        $body = '{"x":1}';
        $signatureFromWrongSecret = base64_encode(hash_hmac('sha256', $body, 'wrong-secret', true));

        $this->apiConfigMock->method('getWebhookSecret')->willReturn(self::SECRET);

        $this->assertFalse($this->verifier->verify($body, $signatureFromWrongSecret));
    }
}
