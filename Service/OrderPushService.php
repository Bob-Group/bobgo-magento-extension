<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Service;

use BobGroup\BobGo\Api\BobGoApiClient;
use BobGroup\BobGo\Api\BobGoApiException;
use BobGroup\BobGo\Api\OrderMapperInterface;
use BobGroup\BobGo\Model\SyncLog;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Pushes and updates Magento orders to the Bob Go API.
 *
 * New orders are POSTed to /v2/orders and the returned Bob Go order id +
 * reference are stored on the Magento order. Existing orders are PATCHed,
 * but only when the canonicalised payload has actually changed (sync-hash
 * dirty check) — repeated saves no longer hammer the API.
 *
 * Every API attempt — success or failure — is recorded in bobgo_sync_log
 * via SyncLogger so the operator has an end-to-end audit trail.
 */
class OrderPushService
{
    private BobGoApiClient $apiClient;
    private OrderMapperInterface $orderMapper;
    private LoggerInterface $logger;
    private OrderRepositoryInterface $orderRepository;
    private SyncLogger $syncLogger;
    private DateTime $dateTime;

    public function __construct(
        BobGoApiClient $apiClient,
        OrderMapperInterface $orderMapper,
        LoggerInterface $logger,
        OrderRepositoryInterface $orderRepository,
        SyncLogger $syncLogger,
        DateTime $dateTime
    ) {
        $this->apiClient = $apiClient;
        $this->orderMapper = $orderMapper;
        $this->logger = $logger;
        $this->orderRepository = $orderRepository;
        $this->syncLogger = $syncLogger;
        $this->dateTime = $dateTime;
    }

    /**
     * Push a new order to Bob Go via POST /v2/orders.
     *
     * Returns true on success, false on a caught API/transient error.
     * The observer at sales_order_save_after ignores the return value
     * (order saving must never be blocked); the admin Resync controller
     * uses it to decide whether to surface success or an error toast.
     */
    public function pushOrder(OrderInterface $order): bool
    {
        $payload = $this->orderMapper->mapOrderToPayload($order);
        $hash = $this->computeHash($payload);

        try {
            $response = $this->apiClient->post('orders', $payload);

            $bobgoOrderId = $this->resolveBobGoOrderId($order, $response);
            if ($bobgoOrderId === null) {
                return $this->reportMissingOrderId($order, SyncLog::EVENT_ORDER_CREATED, $payload, $response);
            }

            $this->applySuccess($order, $response, $hash, $bobgoOrderId);
            $this->syncLogger->logOutbound(
                SyncLog::EVENT_ORDER_CREATED,
                ['request' => $payload, 'response' => $response],
                (int) $order->getEntityId(),
                200,
                true
            );
            $this->logger->info('Bob Go: Order pushed successfully', [
                'order_id' => $order->getEntityId(),
                'increment_id' => $order->getIncrementId(),
                'bobgo_order_id' => $order->getData('bobgo_order_id'),
            ]);
            return true;
        } catch (\Exception $e) {
            $this->applyFailure($order);
            $this->syncLogger->logOutbound(
                SyncLog::EVENT_ORDER_CREATED,
                ['request' => $payload, 'error' => $e->getMessage()],
                (int) $order->getEntityId(),
                $e instanceof BobGoApiException ? $e->getStatusCode() : null,
                false
            );
            $this->logger->error('Bob Go: Failed to push order', [
                'order_id' => $order->getEntityId(),
                'increment_id' => $order->getIncrementId(),
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Update an existing order in Bob Go via PATCH /v2/orders.
     *
     * Skipped entirely (no API call, no log entry) when the canonical payload
     * hash matches the last successfully-synced hash on the order — returns
     * true in that case (nothing went wrong; we just had nothing to send).
     * Returns false on a caught API/transient error.
     */
    public function updateOrder(OrderInterface $order): bool
    {
        $payload = $this->orderMapper->mapOrderToUpdatePayload($order);
        $hash = $this->computeHash($payload);

        $lastHash = (string) ($order->getData('bobgo_sync_hash') ?? '');
        if ($lastHash !== '' && hash_equals($lastHash, $hash)) {
            return true;
        }

        try {
            $response = $this->apiClient->patch('orders', $payload);

            $bobgoOrderId = $this->resolveBobGoOrderId($order, $response);
            if ($bobgoOrderId === null) {
                return $this->reportMissingOrderId(
                    $order,
                    SyncLog::EVENT_ORDER_UPDATED_OUTBOUND,
                    $payload,
                    $response
                );
            }

            $this->applySuccess($order, $response, $hash, $bobgoOrderId);
            $this->syncLogger->logOutbound(
                SyncLog::EVENT_ORDER_UPDATED_OUTBOUND,
                ['request' => $payload],
                (int) $order->getEntityId(),
                200,
                true
            );
            $this->logger->info('Bob Go: Order updated successfully', [
                'order_id' => $order->getEntityId(),
                'increment_id' => $order->getIncrementId(),
                'bobgo_order_id' => $order->getData('bobgo_order_id'),
            ]);
            return true;
        } catch (\Exception $e) {
            $this->applyFailure($order);
            $this->syncLogger->logOutbound(
                SyncLog::EVENT_ORDER_UPDATED_OUTBOUND,
                ['request' => $payload, 'error' => $e->getMessage()],
                (int) $order->getEntityId(),
                $e instanceof BobGoApiException ? $e->getStatusCode() : null,
                false
            );
            $this->logger->error('Bob Go: Failed to update order', [
                'order_id' => $order->getEntityId(),
                'increment_id' => $order->getIncrementId(),
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Forward a terminal order status to Bob Go via PATCH /v2/orders.
     *
     * Separate from updateOrder() on purpose:
     *  - the create POST accepts no status field at all, so status can only ever
     *    travel on a PATCH;
     *  - keeping `status` out of the routine update payload means the catch-up
     *    PATCH wave that follows any payload-shape change can't re-assert a
     *    terminal status as a side effect.
     *
     * Bob Go treats a repeated `completed` as a 200 no-op, but completing an
     * already-cancelled order is a 400 — so the caller checks what it has
     * already sent (see OrderSyncPolicy::statusToForward) rather than relying on
     * idempotency.
     */
    public function pushStatus(OrderInterface $order, string $status): bool
    {
        $bobgoOrderId = $this->resolveBobGoOrderId($order, []);
        if ($bobgoOrderId === null) {
            $this->logger->warning('Bob Go: cannot forward status without an order link', [
                'order_id' => $order->getEntityId(),
                'status' => $status,
            ]);
            return false;
        }

        $payload = ['id' => (int) $bobgoOrderId, 'status' => $status];

        try {
            $this->apiClient->patch('orders', $payload);

            $order->setData('bobgo_status_synced', $status);
            $order->setData('bobgo_last_synced', $this->dateTime->gmtDate());
            $this->orderRepository->save($order);

            $this->syncLogger->logOutbound(
                SyncLog::EVENT_STATUS_UPDATED,
                ['request' => $payload],
                (int) $order->getEntityId(),
                200,
                true
            );
            $this->logger->info('Bob Go: order status forwarded', [
                'order_id' => $order->getEntityId(),
                'status' => $status,
            ]);
            return true;
        } catch (\Exception $e) {
            $this->syncLogger->logOutbound(
                SyncLog::EVENT_STATUS_UPDATED,
                ['request' => $payload, 'error' => $e->getMessage()],
                (int) $order->getEntityId(),
                $e instanceof BobGoApiException ? $e->getStatusCode() : null,
                false
            );
            $this->logger->error('Bob Go: failed to forward order status', [
                'order_id' => $order->getEntityId(),
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Re-baseline the stored sync hash against the order's current payload,
     * without calling Bob Go.
     *
     * For changes that Bob Go itself told us about: re-sending them would be a
     * pointless echo, and in the cancellation case an actively misleading one
     * (cancelling zeroes total_due, which flips the derived payment_status from
     * unpaid to paid). Storing the post-change hash makes the dirty check
     * recognise the order as already in sync.
     */
    public function refreshSyncHash(OrderInterface $order): void
    {
        try {
            $hash = $this->computeHash($this->orderMapper->mapOrderToUpdatePayload($order));
            $order->setData('bobgo_sync_hash', $hash);
            $this->orderRepository->save($order);
        } catch (\Throwable $e) {
            // Worst case the next save sends a redundant PATCH. Not worth
            // failing the caller over.
            $this->logger->warning('Bob Go: failed to refresh sync hash', [
                'order_id' => $order->getEntityId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The Bob Go order id to link this order to: preferring the one the API
     * just returned, falling back to the one already stored. Returns null when
     * neither yields a usable (positive, numeric) id.
     *
     * @param array<string,mixed> $response
     */
    private function resolveBobGoOrderId(OrderInterface $order, array $response): ?string
    {
        $candidates = [$response['id'] ?? null, $order->getData('bobgo_order_id')];
        foreach ($candidates as $candidate) {
            if (!is_scalar($candidate)) {
                continue;
            }
            $value = trim((string) $candidate);
            if ($value !== '' && is_numeric($value) && (float) $value > 0) {
                return $value;
            }
        }
        return null;
    }

    /**
     * A 2xx that yields no usable Bob Go order id is a failure, not a success.
     *
     * Recording it as synced orphans the order: reconciliation skips orders
     * with no bobgo_order_id, fulfilment webhooks can't resolve to it, and the
     * sync-hash dirty check suppresses every future PATCH — so it would sit
     * invisible and never be retried. Marking it failed (and deliberately NOT
     * storing the hash) keeps it in the retry population.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $response
     */
    private function reportMissingOrderId(
        OrderInterface $order,
        string $eventType,
        array $payload,
        array $response
    ): bool {
        $this->applyFailure($order);
        $this->syncLogger->logOutbound(
            $eventType,
            [
                'request' => $payload,
                'response' => $response,
                'error' => 'Bob Go returned a success status but no usable order id',
            ],
            (int) $order->getEntityId(),
            200,
            false
        );
        $this->logger->error('Bob Go: API reported success but returned no order id', [
            'order_id' => $order->getEntityId(),
            'increment_id' => $order->getIncrementId(),
            'event_type' => $eventType,
        ]);
        return false;
    }

    /**
     * @param array<string,mixed> $response
     */
    private function applySuccess(
        OrderInterface $order,
        array $response,
        string $hash,
        string $bobgoOrderId
    ): void {
        $order->setData('bobgo_order_id', $bobgoOrderId);

        // Persist the immutable Bob Go reference when present — schema has
        // a column for it; this is the first place it actually gets written.
        $bobgoOrderRef = $response['reference'] ?? $response['order_ref'] ?? null;
        if (is_scalar($bobgoOrderRef) && (string) $bobgoOrderRef !== '') {
            $order->setData('bobgo_order_ref', (string) $bobgoOrderRef);
        }

        $order->setData('bobgo_sync_hash', $hash);
        $order->setData('bobgo_sync_status', 'success');
        $order->setData('bobgo_last_synced', $this->dateTime->gmtDate());

        $this->saveOrderItemIds($order, $response['order_items'] ?? []);
        $this->orderRepository->save($order);
    }

    private function applyFailure(OrderInterface $order): void
    {
        try {
            $order->setData('bobgo_sync_status', 'failed');
            $this->orderRepository->save($order);
        } catch (\Throwable $e) {
            // Don't let a logging save shadow the original error.
            $this->logger->warning('Bob Go: failed to persist failure marker', [
                'order_id' => $order->getEntityId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Hash of the canonicalised payload. md5 is used here as a fingerprint
     * (collision resistance is not security-critical — this only gates
     * whether we send a PATCH); not for any cryptographic purpose.
     *
     * @param array<string,mixed> $payload
     */
    private function computeHash(array $payload): string
    {
        $normalised = $this->canonicalise($payload);
        return md5((string) json_encode($normalised));
    }

    /**
     * Recursively sort array keys so logically-equal payloads produce identical hashes.
     *
     * @param mixed $value
     * @return mixed
     */
    private function canonicalise($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        $isAssoc = array_keys($value) !== range(0, count($value) - 1);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = $this->canonicalise($v);
        }
        if ($isAssoc) {
            ksort($out);
        }
        return $out;
    }

    /**
     * Save Bob Go order item IDs from the POST response onto Magento order items.
     *
     * Matches response items to Magento items by SKU. When duplicate SKUs exist,
     * positional order is used as a tie-breaker.
     *
     * Must mirror OrderMapper::mapItems() — skip configurable parents and target
     * the simple child so the id we store lines up with the SKU we send on PATCH.
     *
     * @param array<int,array<string,mixed>> $responseItems
     */
    private function saveOrderItemIds(OrderInterface $order, array $responseItems): void
    {
        if (empty($responseItems)) {
            return;
        }

        $responseBySku = [];
        foreach ($responseItems as $responseItem) {
            $sku = $responseItem['sku'] ?? null;
            $id = $responseItem['id'] ?? null;
            if ($sku !== null && $id !== null) {
                $responseBySku[$sku][] = $id;
            }
        }

        $skuIndex = [];

        foreach ($order->getItems() as $item) {
            if ($item->getProductType() === 'configurable') {
                continue;
            }

            $sku = $item->getSku();
            $position = $skuIndex[$sku] ?? 0;

            if (isset($responseBySku[$sku][$position])) {
                $item->setData('bobgo_order_item_id', (string) $responseBySku[$sku][$position]);
            }

            $skuIndex[$sku] = $position + 1;
        }
    }
}
