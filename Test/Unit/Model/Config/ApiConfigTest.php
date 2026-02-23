<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Model\Config;

use BobGroup\BobGo\Model\Config\ApiConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
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

    protected function setUp(): void
    {
        $this->scopeConfigMock = $this->createMock(ScopeConfigInterface::class);
        $this->apiConfig = new ApiConfig($this->scopeConfigMock);
    }

    public function testGetApiKey(): void
    {
        $this->scopeConfigMock->method('getValue')
            ->with(ApiConfig::XML_PATH_API_KEY, ScopeInterface::SCOPE_STORE)
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
