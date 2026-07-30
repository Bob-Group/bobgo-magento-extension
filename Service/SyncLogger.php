<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Service;

use BobGroup\BobGo\Model\ResourceModel\SyncLog as SyncLogResource;
use BobGroup\BobGo\Model\ResourceModel\SyncLog\CollectionFactory as SyncLogCollectionFactory;
use BobGroup\BobGo\Model\SyncLog;
use BobGroup\BobGo\Model\SyncLogFactory;
use Magento\Framework\Exception\AlreadyExistsException;
use Psr\Log\LoggerInterface;

/**
 * Centralised writer for the bobgo_sync_log table.
 *
 * Every inbound webhook and every outbound API call should pass through here
 * so that operators have a single timeline to debug delivery and sync issues
 * from. Also provides race-safe event_id deduplication for inbound webhooks
 * via a UNIQUE (event_id, direction) constraint on the table.
 */
class SyncLogger
{
    private const MAX_PAYLOAD_BYTES = 65535;

    /**
     * Payload keys whose values are replaced with "***" before persistence.
     * Customer PII is canonical on the order; the address block is canonical
     * on the order address — neither needs to live again in the log.
     */
    private const PII_KEYS = [
        // Direct customer PII
        'customer_email',
        'customer_phone',
        'customer_name',
        'customer_surname',
        'telephone',
        'phone',
        'email',
        // Address fields — present at the top of address blocks in both
        // inbound (delivery_address, billing_address) and outbound payloads.
        'street_address',
        'street',
        'street1',
        'street2',
        'address_line_1',
        'address_line_2',
        'local_area',
        'suburb',
        'city',
        'postcode',
        'postal_code',
        'zip',
        'code', // Bob Go v2 uses "code" as the postal code field.
    ];

    private const REDACTED = '***';

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
     * Try to atomically claim an event_id for processing.
     *
     * Inserts a sentinel "claim" row with the UNIQUE (event_id, direction)
     * constraint doing the heavy lifting: if a concurrent worker already
     * claimed the same event_id, the INSERT fails with a duplicate-key
     * violation and we return false. The caller should then 200 the request
     * (the other worker is handling — or has handled — this event).
     *
     * Empty/null event_ids cannot be claimed (the constraint allows multiple
     * NULL rows by design), so callers should treat the absence of an
     * event_id as "no dedup possible" and fall through to processing.
     */
    public function claimEventId(?string $eventId, ?string $topic = null): bool
    {
        if ($eventId === null || $eventId === '') {
            return true;
        }
        try {
            $entry = $this->syncLogFactory->create();
            $entry->setData([
                'order_id'    => null,
                'event_type'  => SyncLog::EVENT_WEBHOOK_CLAIM,
                'direction'   => SyncLog::DIRECTION_INBOUND,
                'event_id'    => $eventId,
                'payload'     => $topic !== null ? substr((string) json_encode(['topic' => $topic]), 0, 256) : null,
                'http_status' => null,
                'success'     => 0,
                'retry_count' => 0,
            ]);
            $this->syncLogResource->save($entry);
            return true;
        } catch (AlreadyExistsException $e) {
            return false;
        } catch (\Throwable $e) {
            // Detect MySQL duplicate-key errors that bubble up as something
            // other than AlreadyExistsException (driver-dependent).
            if ($this->isDuplicateKeyError($e)) {
                return false;
            }
            // Anything else (table missing, connection lost, ...) — don't
            // silently dedup. Log and fall through to processing so the
            // event isn't lost.
            $this->logger->error('Bob Go: claimEventId write failed', [
                'event_id' => $eventId,
                'error'    => $e->getMessage(),
            ]);
            return true;
        }
    }

    /**
     * Release a previously-acquired claim so a retry can re-claim the same
     * event_id. Used by the controller on transient processing failures.
     */
    public function releaseEventIdClaim(?string $eventId): void
    {
        if ($eventId === null || $eventId === '') {
            return;
        }
        try {
            $collection = $this->collectionFactory->create()
                ->addFieldToFilter('event_id', $eventId)
                ->addFieldToFilter('direction', SyncLog::DIRECTION_INBOUND)
                ->addFieldToFilter('event_type', SyncLog::EVENT_WEBHOOK_CLAIM)
                ->addFieldToFilter('success', 0);
            foreach ($collection as $row) {
                $this->syncLogResource->delete($row);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Bob Go: releaseEventIdClaim failed', [
                'event_id' => $eventId,
                'error'    => $e->getMessage(),
            ]);
        }
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
        // The claim row already occupies the unique (event_id, direction)
        // slot. If we're recording the final outcome for an event we
        // successfully claimed, UPDATE the claim row instead of inserting a
        // new one — otherwise the second INSERT would hit the unique
        // constraint and the outcome would be lost.
        if ($eventId !== null && $eventId !== '' && $this->upgradeClaim($eventId, $eventType, $payload, $orderId, $httpStatus, $success)) {
            return;
        }
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
     * Convert the claim row into the final outcome row. Returns true if a
     * claim was found and upgraded, false otherwise (caller should then do
     * a normal insert).
     *
     * @param array<string,mixed>|string|null $payload
     */
    private function upgradeClaim(
        string $eventId,
        string $eventType,
        $payload,
        ?int $orderId,
        ?int $httpStatus,
        bool $success
    ): bool {
        try {
            $collection = $this->collectionFactory->create()
                ->addFieldToFilter('event_id', $eventId)
                ->addFieldToFilter('direction', SyncLog::DIRECTION_INBOUND)
                ->addFieldToFilter('event_type', SyncLog::EVENT_WEBHOOK_CLAIM)
                ->setPageSize(1);
            $entry = $collection->getFirstItem();
            if (!$entry || !$entry->getId()) {
                return false;
            }
            $entry->setData('event_type', $eventType);
            $entry->setData('order_id', $orderId);
            $entry->setData('payload', $this->serialisePayload($payload));
            $entry->setData('http_status', $httpStatus);
            $entry->setData('success', $success ? 1 : 0);
            $this->syncLogResource->save($entry);
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Bob Go: SyncLogger upgradeClaim failed', [
                'error'      => $e->getMessage(),
                'event_id'   => $eventId,
                'event_type' => $eventType,
            ]);
            return false;
        }
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
            return $this->truncate($payload);
        }
        $redacted = $this->redactPii($payload);
        $encoded = json_encode($redacted);
        if ($encoded === false) {
            return null;
        }
        return $this->truncate($encoded);
    }

    /**
     * Trim to the TEXT column's byte limit without splitting a UTF-8 character.
     *
     * A plain substr() can cut mid-sequence, and MySQL in strict mode rejects the
     * resulting invalid string outright ("Incorrect string value") — which
     * SyncLogger then swallows, so the row is simply lost. mb_strcut counts bytes
     * like substr but only ever cuts on a character boundary.
     */
    private function truncate(string $value): string
    {
        return function_exists('mb_strcut')
            ? mb_strcut($value, 0, self::MAX_PAYLOAD_BYTES, 'UTF-8')
            : substr($value, 0, self::MAX_PAYLOAD_BYTES);
    }

    /**
     * Recursively replace PII values with a redaction marker.
     *
     * @param mixed $value
     * @return mixed
     */
    private function redactPii($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $k => $v) {
            if (is_string($k) && in_array(strtolower($k), self::PII_KEYS, true)) {
                $out[$k] = self::REDACTED;
                continue;
            }
            $out[$k] = $this->redactPii($v);
        }
        return $out;
    }

    /**
     * Recognise MySQL duplicate-entry errors from the driver layer even when
     * Magento didn't wrap them in AlreadyExistsException.
     */
    private function isDuplicateKeyError(\Throwable $e): bool
    {
        if ($e instanceof \PDOException && in_array($e->getCode(), ['23000', 23000], true)) {
            return true;
        }
        $msg = strtolower($e->getMessage());
        return strpos($msg, 'duplicate entry') !== false
            || strpos($msg, 'integrity constraint violation') !== false;
    }
}
