<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Api;

use BobGroup\BobGo\Api\BobGoApiClient;
use BobGroup\BobGo\Api\BobGoApiException;
use BobGroup\BobGo\Model\Config\ApiConfig;
use BobGroup\BobGo\Service\ConnectionHealth;
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

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $connectionHealthMock;

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

        $this->connectionHealthMock = $this->createMock(ConnectionHealth::class);

        $this->client = new BobGoApiClient(
            $this->apiConfigMock,
            $this->curlFactoryMock,
            $this->loggerMock,
            $storeManagerMock,
            $this->connectionHealthMock
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
            $storeManagerMock,
            $this->connectionHealthMock
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

    /**
     * Magento's Curl client reports transport failures — connect timeout, read
     * timeout, DNS failure, TLS error — by throwing a bare \Exception from
     * Curl::doError(). Nothing up our stack catches that type, and
     * Shipping::collectCarrierRates() has no try/catch around collectRates(),
     * so letting it escape turns a Bob Go outage into a 500 on the checkout
     * shipping step. Everything must leave this class as a BobGoApiException.
     *
     * @dataProvider transportCallProvider
     */
    public function testTransportFailuresBecomeApiExceptions(string $method, array $args): void
    {
        $this->apiConfigMock->method('getApiKey')->willReturn('test-key-abc123');
        $this->apiConfigMock->method('getBaseUrl')->willReturn(ApiConfig::BASE_URL_SANDBOX);

        $boom = static function (): void {
            throw new \Exception('Operation timed out after 8001 milliseconds');
        };
        $this->curlMock->method('get')->willReturnCallback($boom);
        $this->curlMock->method('post')->willReturnCallback($boom);

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with('Bob Go API transport failure', $this->callback(function (array $context) {
                // The key must still be masked on this path.
                $this->assertSame('****c123', $context['api_key']);
                return true;
            }));

        try {
            $this->client->{$method}(...$args);
            $this->fail('Expected a BobGoApiException');
        } catch (BobGoApiException $e) {
            // Status 0 == "never produced an HTTP response", same convention as
            // the missing-API-key case.
            $this->assertSame(0, $e->getStatusCode());
            $this->assertStringContainsString('Operation timed out', $e->getMessage());
        }
    }

    /**
     * @return array<string,array{0:string,1:array<int,mixed>}>
     */
    public function transportCallProvider(): array
    {
        return [
            'get' => ['get', ['rates-at-checkout']],
            'post' => ['post', ['rates-at-checkout', ['items' => []]]],
            'patch' => ['patch', ['orders', ['id' => 1]]],
            'delete' => ['delete', ['webhooks']],
        ];
    }

    /**
     * "A key is saved" is not "we can talk to Bob Go". A key revoked on the Bob Go
     * side left the config page looking healthy while every call 401'd, so the
     * state is fed from ordinary traffic rather than from a Test button.
     *
     * @dataProvider healthObservationProvider
     */
    public function testConnectionHealthObservesTheResponseStatus(int $status): void
    {
        $this->apiConfigMock->method('getApiKey')->willReturn('test-key');
        $this->apiConfigMock->method('getBaseUrl')->willReturn(ApiConfig::BASE_URL_SANDBOX);
        $this->curlMock->method('getStatus')->willReturn($status);
        $this->curlMock->method('getBody')->willReturn('{}');

        $this->connectionHealthMock->expects($this->once())->method('observe')->with($status);

        try {
            $this->client->get('webhooks');
        } catch (BobGoApiException $e) {
            // 4xx/5xx still throw; we only care that the status was observed.
        }
    }

    /**
     * @return array<string,array{0:int}>
     */
    public function healthObservationProvider(): array
    {
        return [
            'success' => [200],
            'unauthorised' => [401],
            'not enrolled' => [404],
            'server error' => [500],
        ];
    }

    /**
     * A transport failure produced no response, so it is reported as status 0 —
     * ConnectionHealth treats that as inconclusive and leaves the last known
     * state alone rather than crying wolf on every network blip.
     */
    public function testTransportFailureIsObservedAsInconclusive(): void
    {
        $this->apiConfigMock->method('getApiKey')->willReturn('test-key');
        $this->apiConfigMock->method('getBaseUrl')->willReturn(ApiConfig::BASE_URL_SANDBOX);
        $this->curlMock->method('get')->willReturnCallback(static function (): void {
            throw new \Exception('Operation timed out');
        });

        $this->connectionHealthMock->expects($this->once())->method('observe')->with(0);

        $this->expectException(BobGoApiException::class);
        $this->client->get('webhooks');
    }
}
