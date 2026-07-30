<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Service;

use BobGroup\BobGo\Api\BobGoApiException;
use BobGroup\BobGo\Model\ResourceModel\SyncLog as SyncLogResource;
use BobGroup\BobGo\Model\ResourceModel\SyncLog\Collection as SyncLogCollection;
use BobGroup\BobGo\Model\ResourceModel\SyncLog\CollectionFactory as SyncLogCollectionFactory;
use BobGroup\BobGo\Model\SyncLog;
use BobGroup\BobGo\Model\SyncLogFactory;
use BobGroup\BobGo\Service\SyncLogger;
use Magento\Framework\Exception\AlreadyExistsException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Focused tests for the race-safe dedup primitives:
 *  - claimEventId() returns false when a duplicate-key violation surfaces
 *    (real defence against concurrent webhook deliveries)
 *  - claimEventId() falls through on unexpected DB failures (so events
 *    aren't silently lost)
 *  - releaseEventIdClaim() deletes the claim so the slot is free for the
 *    retry
 *  - Address fields in the payload are redacted before persistence (no
 *    customer PII recurring in the log)
 */
class SyncLoggerTest extends TestCase
{
    private $syncLogFactoryMock;
    private $resourceMock;
    private $collectionFactoryMock;
    private $loggerMock;
    /** @var SyncLogger */
    private $syncLogger;

    protected function setUp(): void
    {
        $this->syncLogFactoryMock = $this->createMock(SyncLogFactory::class);
        $this->resourceMock = $this->createMock(SyncLogResource::class);
        $this->collectionFactoryMock = $this->createMock(SyncLogCollectionFactory::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);

        $this->syncLogger = new SyncLogger(
            $this->syncLogFactoryMock,
            $this->resourceMock,
            $this->collectionFactoryMock,
            $this->loggerMock
        );
    }

    public function testClaimEventIdReturnsTrueWhenNoEventId(): void
    {
        $this->resourceMock->expects($this->never())->method('save');
        $this->assertTrue($this->syncLogger->claimEventId(null));
        $this->assertTrue($this->syncLogger->claimEventId(''));
    }

    public function testClaimEventIdSucceedsOnFreshEvent(): void
    {
        $entry = $this->createSyncLogEntryMock();
        $this->syncLogFactoryMock->method('create')->willReturn($entry);

        $entry->expects($this->once())->method('setData');
        $this->resourceMock->expects($this->once())->method('save')->with($entry);

        $this->assertTrue($this->syncLogger->claimEventId('evt_1'));
    }

    public function testClaimEventIdReturnsFalseOnAlreadyExists(): void
    {
        $entry = $this->createSyncLogEntryMock();
        $this->syncLogFactoryMock->method('create')->willReturn($entry);

        $this->resourceMock->method('save')
            ->willThrowException(new AlreadyExistsException(new \Magento\Framework\Phrase('dup')));

        $this->assertFalse($this->syncLogger->claimEventId('evt_2'));
    }

    public function testClaimEventIdReturnsFalseOnRawDuplicateKey(): void
    {
        $entry = $this->createSyncLogEntryMock();
        $this->syncLogFactoryMock->method('create')->willReturn($entry);

        // Magento's framework doesn't always wrap PDO 23000 in AlreadyExists.
        // We need to detect it via the driver's error code too.
        $this->resourceMock->method('save')
            ->willThrowException(new \RuntimeException("SQLSTATE[23000]: Integrity constraint violation: Duplicate entry 'evt_3'-'inbound'"));

        $this->assertFalse($this->syncLogger->claimEventId('evt_3'));
    }

    public function testClaimEventIdFallsThroughOnUnexpectedError(): void
    {
        // If the table is gone or the DB is unreachable we should NOT
        // silently dedup — the event has to be processed, otherwise it's
        // lost. Return true so the caller proceeds with processing.
        $entry = $this->createSyncLogEntryMock();
        $this->syncLogFactoryMock->method('create')->willReturn($entry);

        $this->resourceMock->method('save')
            ->willThrowException(new \RuntimeException('Connection refused'));

        $this->loggerMock->expects($this->once())->method('error');
        $this->assertTrue($this->syncLogger->claimEventId('evt_4'));
    }

    public function testReleaseEventIdClaimDeletesMatchingRows(): void
    {
        $row = $this->createSyncLogEntryMock();
        $collection = $this->createMock(SyncLogCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$row]));

        $this->collectionFactoryMock->method('create')->willReturn($collection);

        $this->resourceMock->expects($this->once())->method('delete')->with($row);

        $this->syncLogger->releaseEventIdClaim('evt_5');
    }

    public function testReleaseEventIdClaimNoopOnEmptyEventId(): void
    {
        $this->collectionFactoryMock->expects($this->never())->method('create');
        $this->syncLogger->releaseEventIdClaim(null);
        $this->syncLogger->releaseEventIdClaim('');
    }

    public function testInboundPayloadRedactsAddressFields(): void
    {
        $captured = null;
        $entry = $this->createSyncLogEntryMock();
        $entry->method('setData')->willReturnCallback(function ($data) use (&$captured) {
            $captured = $data;
            return null;
        });
        $this->syncLogFactoryMock->method('create')->willReturn($entry);

        // No claim row to upgrade — collectionFactory returns empty.
        $emptyCollection = $this->createMock(SyncLogCollection::class);
        $emptyCollection->method('addFieldToFilter')->willReturnSelf();
        $emptyCollection->method('setPageSize')->willReturnSelf();
        $emptyEntry = $this->createSyncLogEntryMock();
        $emptyEntry->method('getId')->willReturn(null);
        $emptyCollection->method('getFirstItem')->willReturn($emptyEntry);
        $this->collectionFactoryMock->method('create')->willReturn($emptyCollection);

        $this->syncLogger->logInbound(
            'order_created',
            [
                'customer_email' => 'jane@example.com',
                'customer_phone' => '+27 11 555 1212',
                'delivery_address' => [
                    'street_address' => '17 Test Lane',
                    'local_area' => 'Sandton',
                    'city' => 'Johannesburg',
                    'code' => '2196',
                    'country' => 'ZA',
                ],
                'total' => 199.99,
            ],
            42,
            null,
            200,
            true
        );

        $this->assertIsArray($captured);
        $payload = json_decode($captured['payload'], true);
        $this->assertSame('***', $payload['customer_email']);
        $this->assertSame('***', $payload['customer_phone']);
        $this->assertSame('***', $payload['delivery_address']['street_address']);
        $this->assertSame('***', $payload['delivery_address']['local_area']);
        $this->assertSame('***', $payload['delivery_address']['city']);
        $this->assertSame('***', $payload['delivery_address']['code']);
        // Non-PII fields preserved
        $this->assertSame('ZA', $payload['delivery_address']['country']);
        $this->assertSame(199.99, $payload['total']);
    }

    // ------------------------------------- outbound failures keep what the API said

    /**
     * The status code alone is not actionable. Diagnosing a sync failure always
     * needed the response body, and it lived only in system.log — so the admin
     * grid could show a merchant that a sync had failed but never why:
     *
     *   what Bob Go said:  {"message":"Cannot delete order_item=18616,
     *                       fulfilled quantity is greater than 0."}
     *   what the grid had: "failed with status 400"
     */
    public function testOutboundFailureRecordsTheApiResponseBody(): void
    {
        $captured = $this->captureWrite();

        $this->syncLogger->logOutboundFailure(
            'order_updated_outbound',
            ['request' => ['channel_ref_id' => '3']],
            new BobGoApiException(
                'Bob Go API request to orders failed with status 400',
                400,
                '{"message":"Cannot delete order_item=18616, fulfilled quantity is greater than 0."}',
                'orders'
            ),
            3
        );

        $payload = json_decode($captured->value['payload'], true);
        $this->assertSame(
            'Cannot delete order_item=18616, fulfilled quantity is greater than 0.',
            $payload['response']['message']
        );
        $this->assertSame(400, $captured->value['http_status']);
        $this->assertSame(0, $captured->value['success']);
        $this->assertSame('3', $payload['request']['channel_ref_id']);
    }

    /**
     * Bob Go's 4xx responses echo the offending payload back, so the response is
     * decoded rather than stored raw: redactPii() can walk a structure but not a
     * JSON string, and storing the string would reintroduce the customer PII this
     * table deliberately keeps out.
     */
    public function testTheResponseBodyIsSubjectToPiiRedaction(): void
    {
        $captured = $this->captureWrite();

        $this->syncLogger->logOutboundFailure(
            'order_created',
            [],
            new BobGoApiException('failed', 422, json_encode([
                'message' => 'Validation failed',
                'order' => [
                    'customer_email' => 'jane@example.com',
                    'delivery_address' => ['street_address' => '17 Test Lane', 'city' => 'Sandton'],
                ],
            ]), 'orders'),
            3
        );

        $payload = json_decode($captured->value['payload'], true);
        $this->assertSame('Validation failed', $payload['response']['message']);
        $this->assertSame('***', $payload['response']['order']['customer_email']);
        $this->assertSame('***', $payload['response']['order']['delivery_address']['street_address']);
        $this->assertSame('***', $payload['response']['order']['delivery_address']['city']);
    }

    /**
     * A proxy's HTML error page cannot be walked for PII, so it is kept opaque and
     * capped — the same 512-byte limit BobGoApiClient applies to its own snippet.
     */
    public function testANonJsonResponseIsCappedAndKeptAsAString(): void
    {
        $captured = $this->captureWrite();

        $this->syncLogger->logOutboundFailure(
            'order_created',
            [],
            new BobGoApiException('failed', 502, '<html>' . str_repeat('x', 4000) . '</html>', 'orders'),
            3
        );

        $payload = json_decode($captured->value['payload'], true);
        $this->assertIsString($payload['response']);
        $this->assertSame(512, strlen($payload['response']));
    }

    /**
     * The whole payload is truncated to the column's byte limit and the request is
     * the bulky part, so the diagnosis is written first. Ordered the other way, a
     * large order's payload would push the response out of the row exactly when it
     * was needed.
     */
    public function testTheDiagnosisIsWrittenAheadOfTheBulkyRequest(): void
    {
        $captured = $this->captureWrite();

        $this->syncLogger->logOutboundFailure(
            'order_created',
            ['request' => ['items' => range(1, 5)]],
            new BobGoApiException('failed', 400, '{"message":"nope"}', 'orders'),
            3
        );

        $payload = json_decode($captured->value['payload'], true);
        $this->assertSame(['error', 'response', 'request'], array_keys($payload));
    }

    /**
     * A transport failure or a bug in our own code is not a BobGoApiException, so
     * there is no status and no body — the row must still be written.
     */
    public function testAPlainExceptionStillProducesARowWithNoResponse(): void
    {
        $captured = $this->captureWrite();

        $this->syncLogger->logOutboundFailure(
            'order_created',
            ['request' => []],
            new \RuntimeException('database went away'),
            3
        );

        $payload = json_decode($captured->value['payload'], true);
        $this->assertSame('database went away', $payload['error']);
        $this->assertArrayNotHasKey('response', $payload);
        $this->assertNull($captured->value['http_status']);
    }

    public function testAnEmptyResponseBodyIsOmittedRatherThanStoredBlank(): void
    {
        $captured = $this->captureWrite();

        $this->syncLogger->logOutboundFailure(
            'order_created',
            ['request' => []],
            new BobGoApiException('failed', 500, '   ', 'orders'),
            3
        );

        $payload = json_decode($captured->value['payload'], true);
        $this->assertArrayNotHasKey('response', $payload);
    }

    /**
     * Captures the row handed to setData().
     *
     * An object, not an array: returning an array would hand the test a copy taken
     * before the write ever happened.
     */
    private function captureWrite(): \stdClass
    {
        $captured = new \stdClass();
        $captured->value = [];

        $entry = $this->createSyncLogEntryMock();
        $entry->method('setData')->willReturnCallback(function ($data) use ($captured) {
            $captured->value = $data;
            return null;
        });
        $this->syncLogFactoryMock->method('create')->willReturn($entry);

        return $captured;
    }

    /**
     * @return SyncLog|\PHPUnit\Framework\MockObject\MockObject
     */
    private function createSyncLogEntryMock()
    {
        return $this->getMockBuilder(SyncLog::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setData', 'getData'])
            ->addMethods(['getId'])
            ->getMock();
    }
}
