<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Service;

use BobGroup\BobGo\Model\ResourceModel\SyncLog as SyncLogResource;
use BobGroup\BobGo\Model\ResourceModel\SyncLog\CollectionFactory as SyncLogCollectionFactory;
use BobGroup\BobGo\Model\SyncLog;
use BobGroup\BobGo\Model\SyncLogFactory;
use Psr\Log\LoggerInterface;

/**
 * Centralised writer for the bobgo_sync_log table.
 *
 * Every inbound webhook and every outbound API call should pass through here
 * so that operators have a single timeline to debug delivery and sync issues
 * from. Also provides event_id deduplication for inbound webhooks.
 */
class SyncLogger
{
    private const MAX_PAYLOAD_BYTES = 65535;

    private SyncLogFactory $syncLogFactory;
    private SyncLogResource $syncLogResource;
    private SyncLogCollectionFactory $collectionFactory;
    private LoggerInterface $logger;

    public function __construct(
        SyncLogFactory $syncLogFactory,
        SyncLogResource $syncLogResource,
        SyncLogCollectionFactory $collectionFactory,
        LoggerInterface $logger
    ) {
        $this->syncLogFactory = $syncLogFactory;
        $this->syncLogResource = $syncLogResource;
        $this->collectionFactory = $collectionFactory;
        $this->logger = $logger;
    }

    /**
     * Has an inbound event with this provider-issued id already been logged
     * successfully? Used to short-circuit duplicate webhook deliveries.
     */
    public function wasEventIdProcessed(?string $eventId): bool
    {
        if ($eventId === null || $eventId === '') {
            return false;
        }
        $collection = $this->collectionFactory->create()
            ->addFieldToFilter('event_id', $eventId)
            ->addFieldToFilter('direction', SyncLog::DIRECTION_INBOUND)
            ->addFieldToFilter('success', 1)
            ->setPageSize(1);
        return $collection->getSize() > 0;
    }

    /**
     * @param array<string,mixed>|string|null $payload
     */
    public function logInbound(
        string $eventType,
        $payload,
        ?int $orderId = null,
        ?string $eventId = null,
        ?int $httpStatus = null,
        bool $success = true
    ): void {
        $this->write(SyncLog::DIRECTION_INBOUND, $eventType, $payload, $orderId, $eventId, $httpStatus, $success);
    }

    /**
     * @param array<string,mixed>|string|null $payload
     */
    public function logOutbound(
        string $eventType,
        $payload,
        ?int $orderId = null,
        ?int $httpStatus = null,
        bool $success = true
    ): void {
        $this->write(SyncLog::DIRECTION_OUTBOUND, $eventType, $payload, $orderId, null, $httpStatus, $success);
    }

    /**
     * @param array<string,mixed>|string|null $payload
     */
    private function write(
        string $direction,
        string $eventType,
        $payload,
        ?int $orderId,
        ?string $eventId,
        ?int $httpStatus,
        bool $success
    ): void {
        try {
            $entry = $this->syncLogFactory->create();
            $entry->setData([
                'order_id'    => $orderId,
                'event_type'  => $eventType,
                'direction'   => $direction,
                'event_id'    => $eventId,
                'payload'     => $this->serialisePayload($payload),
                'http_status' => $httpStatus,
                'success'     => $success ? 1 : 0,
                'retry_count' => 0,
            ]);
            $this->syncLogResource->save($entry);
        } catch (\Throwable $e) {
            // Sync log writes must never break the calling flow.
            $this->logger->error('Bob Go: SyncLogger write failed', [
                'error' => $e->getMessage(),
                'event_type' => $eventType,
            ]);
        }
    }

    /**
     * @param array<string,mixed>|string|null $payload
     */
    private function serialisePayload($payload): ?string
    {
        if ($payload === null) {
            return null;
        }
        if (is_string($payload)) {
            return substr($payload, 0, self::MAX_PAYLOAD_BYTES);
        }
        $encoded = json_encode($payload);
        if ($encoded === false) {
            return null;
        }
        return substr($encoded, 0, self::MAX_PAYLOAD_BYTES);
    }
}
