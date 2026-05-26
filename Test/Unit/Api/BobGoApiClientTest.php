<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Api;

use BobGroup\BobGo\Api\BobGoApiClient;
use BobGroup\BobGo\Api\BobGoApiException;
use BobGroup\BobGo\Model\Config\ApiConfig;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BobGoApiClientTest extends TestCase
{
    /**
     * @var BobGoApiClient
     */
    private $client;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $apiConfigMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $curlFactoryMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $curlMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $loggerMock;

    protected function setUp(): void
    {
        $this->apiConfigMock = $this->createMock(ApiConfig::class);
        $this->curlFactoryMock = $this->createMock(CurlFactory::class);
        $this->curlMock = $this->createMock(Curl::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);

        $this->curlFactoryMock->method('create')->willReturn($this->curlMock);

        $storeMock = $this->createMock(StoreInterface::class);
        $storeMock->method('getBaseUrl')->willReturn('https://store.example.com/');
        $storeManagerMock = $this->createMock(StoreManagerInterface::class);
        $storeManagerMock->method('getStore')->willReturn($storeMock);

        $this->client = new BobGoApiClient(
            $this->apiConfigMock,
            $this->curlFactoryMock,
            $this->loggerMock,
            $storeManagerMock
        );
    }

    public function testPostSendsCorrectHeaders(): void
    {
        $this->apiConfigMock->method('getApiKey')->willReturn('test-key-abc123');
        $this->apiConfigMock->method('getBaseUrl')->willReturn(ApiConfig::BASE_URL_SANDBOX);

        $headers = [];
        $this->curlMock->method('addHeader')
            ->willReturnCallback(function (string $name, string $value) use (&$headers) {
                $headers[$name] = $value;
            });

        $this->curlMock->method('getStatus')->willReturn(200);
        $this->curlMock->method('getBody')->willReturn('{"success":true}');

        $this->client->post('orders', ['order_id' => '123']);

        $this->assertSame('application/json', $headers['Content-Type'] ?? null);
        $this->assertSame('application/json', $headers['Accept'] ?? null);
        $this->assertSame('Bearer test-key-abc123', $headers['Authorization'] ?? null);
        $this->assertSame('store.example.com', $headers['bobgo-channel-identifier'] ?? null);
    }

    /**
     * @dataProvider channelIdentifierProvider
     */
    public function testChannelIdentifierNormalization(string $baseUrl, string $expected): void
    {
        $storeMock = $this->createMock(StoreInterface::class);
        $storeMock->method('getBaseUrl')->willReturn($baseUrl);
        $storeManagerMock = $this->createMock(StoreManagerInterface::class);
        $storeManagerMock->method('getStore')->willReturn($storeMock);

        $client = new BobGoApiClient(
            $this->apiConfigMock,
            $this->curlFactoryMock,
            $this->loggerMock,
            $storeManagerMock
        );

        $this->apiConfigMock->method('getApiKey')->willReturn('test-key');
        $this->apiConfigMock->method('getBaseUrl')->willReturn(ApiConfig::BASE_URL_SANDBOX);

        $headers = [];
        $this->curlMock->method('addHeader')
            ->willReturnCallback(function (string $name, string $value) use (&$headers) {
                $headers[$name] = $value;
            });
        $this->curlMock->method('getStatus')->willReturn(200);
        $this->curlMock->method('getBody')->willReturn('{}');

        $client->get('webhooks');

        $this->assertSame($expected, $headers['bobgo-channel-identifier'] ?? null);
    }

    public function channelIdentifierProvider(): array
    {
        return [
            'https with trailing slash'    => ['https://app.bobgo-magento.test/', 'app.bobgo-magento.test'],
            'http with trailing slash'     => ['http://shop.local/', 'shop.local'],
            'https without trailing slash' => ['https://store.example.com', 'store.example.com'],
            'subpath preserved'            => ['https://example.com/shop/', 'example.com/shop'],
            'uppercase scheme'             => ['HTTPS://Example.com/', 'Example.com'],
        ];
    }

    public function testPostBuildsCorrectUrl(): void
    {
        $this->apiConfigMock->method('getApiKey')->willReturn('test-key');
        $this->apiConfigMock->method('getBaseUrl')->willReturn(ApiConfig::BASE_URL_SANDBOX);

        $expectedUrl = ApiConfig::BASE_URL_SANDBOX . 'orders';

        $this->curlMock->expects($this->once())
            ->method('post')
            ->with($expectedUrl, $this->anything());

        $this->curlMock->method('getStatus')->willReturn(200);
        $this->curlMock->method('getBody')->willReturn('{}');

        $this->client->post('orders', ['data' => 'value']);
    }

    public function testGetBuildsUrlWithQueryParams(): void
    {
        $this->apiConfigMock->method('getApiKey')->willReturn('test-key');
        $this->apiConfigMock->method('getBaseUrl')->willReturn(ApiConfig::BASE_URL_SANDBOX);

        $expectedUrl = ApiConfig::BASE_URL_SANDBOX . 'shipments?status=pending&page=2';

        $this->curlMock->expects($this->once())
            ->method('get')
            ->with($expectedUrl);

        $this->curlMock->method('getStatus')->willReturn(200);
        $this->curlMock->method('getBody')->willReturn('[]');

        $this->client->get('shipments', ['status' => 'pending', 'page' => '2']);
    }

    public function testThrowsExceptionOn4xx(): void
    {
        $this->apiConfigMock->method('getApiKey')->willReturn('test-key-abc123');
        $this->apiConfigMock->method('getBaseUrl')->willReturn(ApiConfig::BASE_URL_SANDBOX);

        $this->curlMock->method('getStatus')->willReturn(422);
        $this->curlMock->method('getBody')->willReturn('{"error":"Invalid payload"}');

        $this->loggerMock->expects($this->once())->method('error');

        $this->expectException(BobGoApiException::class);
        $this->expectExceptionMessage('Bob Go API request to orders failed with status 422');

        $this->client->post('orders', ['bad' => 'data']);
    }

    public function testThrowsExceptionWhenNoApiKey(): void
    {
        $this->apiConfigMock->method('getApiKey')->willReturn(null);

        $this->expectException(BobGoApiException::class);
        $this->expectExceptionMessage('Bob Go API key is not configured');

        $this->client->get('orders');
    }

    public function testHandlesEmptyResponse(): void
    {
        $this->apiConfigMock->method('getApiKey')->willReturn('test-key');
        $this->apiConfigMock->method('getBaseUrl')->willReturn(ApiConfig::BASE_URL_SANDBOX);

        $this->curlMock->method('getStatus')->willReturn(204);
        $this->curlMock->method('getBody')->willReturn('');

        $result = $this->client->get('orders/123/cancel');

        $this->assertSame([], $result);
    }

    public function testMasksApiKeyInLogs(): void
    {
        $this->apiConfigMock->method('getApiKey')->willReturn('super-secret-key-9999');
        $this->apiConfigMock->method('getBaseUrl')->willReturn(ApiConfig::BASE_URL_SANDBOX);

        $this->curlMock->method('getStatus')->willReturn(500);
        $this->curlMock->method('getBody')->willReturn('Internal Server Error');

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with(
                'Bob Go API error',
                $this->callback(function (array $context) {
                    $this->assertSame('****9999', $context['api_key']);
                    $this->assertStringNotContainsString('super-secret-key', $context['api_key']);
                    return true;
                })
            );

        try {
            $this->client->get('test-endpoint');
        } catch (BobGoApiException $e) {
            // Expected
        }
    }
}
