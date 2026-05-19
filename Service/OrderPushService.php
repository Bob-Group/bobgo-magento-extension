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
     */
    public function pushOrder(OrderInterface $order): void
    {
        $payload = $this->orderMapper->mapOrderToPayload($order);
        $hash = $this->computeHash($payload);

        try {
            $response = $this->apiClient->post('orders', $payload);
            $this->applySuccess($order, $response, $hash);
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
        }
    }

    /**
     * Update an existing order in Bob Go via PATCH /v2/orders.
     *
     * Skipped entirely (no API call, no log entry) when the canonical payload
     * hash matches the last successfully-synced hash on the order.
     */
    public function updateOrder(OrderInterface $order): void
    {
        $payload = $this->orderMapper->mapOrderToUpdatePayload($order);
        $hash = $this->computeHash($payload);

        $lastHash = (string) ($order->getData('bobgo_sync_hash') ?? '');
        if ($lastHash !== '' && hash_equals($lastHash, $hash)) {
            return;
        }

        try {
            $this->apiClient->patch('orders', $payload);
            $this->applySuccess($order, [], $hash);
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
        }
    }

    /**
     * @param array<string,mixed> $response
     */
    private function applySuccess(OrderInterface $order, array $response, string $hash): void
    {
        $bobgoOrderId = $response['id'] ?? $order->getData('bobgo_order_id');
        if ($bobgoOrderId !== null && $bobgoOrderId !== '') {
            $order->setData('bobgo_order_id', $bobgoOrderId);
        }

        // Bob Go may return an immutable string ref alongside the numeric id;
        // capture whichever key it uses so reconciliation/lookups have it.
        $orderRef = $response['order_ref'] ?? $response['reference'] ?? null;
        if ($orderRef !== null && $orderRef !== '') {
            $order->setData('bobgo_order_ref', (string) $orderRef);
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
            if ($item->getParentItemId()) {
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
