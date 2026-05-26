<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Service;

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
