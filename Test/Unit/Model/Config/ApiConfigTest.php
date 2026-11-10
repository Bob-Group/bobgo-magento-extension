<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Model\Config;

use BobGroup\BobGo\Model\Config\ApiConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\TestCase;

class ApiConfigTest extends TestCase
{
    /**
     * @var ApiConfig
     */
    private $apiConfig;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $scopeConfigMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $encryptorMock;

    protected function setUp(): void
    {
        $this->scopeConfigMock = $this->createMock(ScopeConfigInterface::class);
        $this->encryptorMock = $this->createMock(EncryptorInterface::class);
        $this->apiConfig = new ApiConfig($this->scopeConfigMock, $this->encryptorMock);
    }

    public function testGetApiKey(): void
    {
        $this->scopeConfigMock->method('getValue')
            ->with(ApiConfig::XML_PATH_API_KEY, ScopeInterface::SCOPE_STORE)
            ->willReturn('encrypted-api-key-123');

        $this->encryptorMock->method('decrypt')
            ->with('encrypted-api-key-123')
            ->willReturn('test-api-key-123');

        $this->assertSame('test-api-key-123', $this->apiConfig->getApiKey());
    }

    public function testGetApiKeyReturnsNullWhenEmpty(): void
    {
        $this->scopeConfigMock->method('getValue')
            ->with(ApiConfig::XML_PATH_API_KEY, ScopeInterface::SCOPE_STORE)
            ->willReturn('');

        $this->assertNull($this->apiConfig->getApiKey());
    }

    public function testGetEnvironmentDefaultsSandbox(): void
    {
        $this->scopeConfigMock->method('getValue')
            ->with(ApiConfig::XML_PATH_ENVIRONMENT, ScopeInterface::SCOPE_STORE)
            ->willReturn(null);

        $this->assertSame(ApiConfig::ENV_SANDBOX, $this->apiConfig->getEnvironment());
    }

    public function testGetEnvironmentReturnsProduction(): void
    {
        $this->scopeConfigMock->method('getValue')
            ->with(ApiConfig::XML_PATH_ENVIRONMENT, ScopeInterface::SCOPE_STORE)
            ->willReturn('production');

        $this->assertSame(ApiConfig::ENV_PRODUCTION, $this->apiConfig->getEnvironment());
    }

    public function testGetBaseUrlSandbox(): void
    {
        $this->scopeConfigMock->method('getValue')
            ->willReturnMap([
                [ApiConfig::XML_PATH_ENVIRONMENT, ScopeInterface::SCOPE_STORE, null, null],
            ]);

        $this->assertSame(ApiConfig::BASE_URL_SANDBOX, $this->apiConfig->getBaseUrl());
    }

    public function testGetBaseUrlProduction(): void
    {
        $this->scopeConfigMock->method('getValue')
            ->willReturnMap([
                [ApiConfig::XML_PATH_ENVIRONMENT, ScopeInterface::SCOPE_STORE, null, 'production'],
            ]);

        $this->assertSame(ApiConfig::BASE_URL_PRODUCTION, $this->apiConfig->getBaseUrl());
    }

    public function testIsConfiguredTrue(): void
    {
        $this->scopeConfigMock->method('getValue')
            ->with(ApiConfig::XML_PATH_API_KEY, ScopeInterface::SCOPE_STORE)
            ->willReturn('encrypted-some-key');

        $this->encryptorMock->method('decrypt')
            ->with('encrypted-some-key')
            ->willReturn('some-key');

        $this->assertTrue($this->apiConfig->isConfigured());
    }

    public function testIsConfiguredFalse(): void
    {
        $this->scopeConfigMock->method('getValue')
            ->with(ApiConfig::XML_PATH_API_KEY, ScopeInterface::SCOPE_STORE)
            ->willReturn(null);

        $this->assertFalse($this->apiConfig->isConfigured());
    }

    public function testIsOrderPushEnabled(): void
    {
        $this->scopeConfigMock->method('isSetFlag')
            ->with(ApiConfig::XML_PATH_ENABLE_ORDER_PUSH, ScopeInterface::SCOPE_STORE)
            ->willReturn(true);

        $this->assertTrue($this->apiConfig->isOrderPushEnabled());
    }

    public function testIsFulfillmentSyncEnabled(): void
    {
        $this->scopeConfigMock->method('isSetFlag')
            ->with(ApiConfig::XML_PATH_ENABLE_FULFILLMENT_SYNC, ScopeInterface::SCOPE_STORE)
            ->willReturn(true);

        $this->assertTrue($this->apiConfig->isFulfillmentSyncEnabled());
    }
}
