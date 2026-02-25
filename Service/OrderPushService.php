<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Service;

use BobGroup\BobGo\Api\BobGoApiClient;
use BobGroup\BobGo\Api\OrderMapperInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Pushes and updates Magento orders to the Bob Go API.
 *
 * New orders are POSTed to /v2/orders and the returned Bob Go order ID is
 * stored on the Magento order. Existing orders (those already pushed) are
 * PATCHed to keep Bob Go in sync with status and item changes.
 */
class OrderPushService
{
    /**
     * @var BobGoApiClient
     */
    private BobGoApiClient $apiClient;

    /**
     * @var OrderMapperInterface
     */
    private OrderMapperInterface $orderMapper;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var OrderRepositoryInterface
     */
    private OrderRepositoryInterface $orderRepository;

    public function __construct(
        BobGoApiClient $apiClient,
        OrderMapperInterface $orderMapper,
        LoggerInterface $logger,
        OrderRepositoryInterface $orderRepository
    ) {
        $this->apiClient = $apiClient;
        $this->orderMapper = $orderMapper;
        $this->logger = $logger;
        $this->orderRepository = $orderRepository;
    }

    /**
     * Push a new order to Bob Go via POST /v2/orders.
     *
     * @param OrderInterface $order
     * @return void
     */
    public function pushOrder(OrderInterface $order): void
    {
        try {
            $payload = $this->orderMapper->mapOrderToPayload($order);
            $response = $this->apiClient->post('orders', $payload);

            $bobgoOrderId = $response['id'] ?? null;
            if ($bobgoOrderId) {
                $order->setData('bobgo_order_id', $bobgoOrderId);
                $this->saveOrderItemIds($order, $response['order_items'] ?? []);
                $this->orderRepository->save($order);
            }

            $this->logger->info('Bob Go: Order pushed successfully', [
                'order_id' => $order->getEntityId(),
                'increment_id' => $order->getIncrementId(),
                'bobgo_order_id' => $bobgoOrderId,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Bob Go: Failed to push order', [
                'order_id' => $order->getEntityId(),
                'increment_id' => $order->getIncrementId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Save Bob Go order item IDs from the POST response onto Magento order items.
     *
     * Matches response items to Magento items by SKU. When duplicate SKUs exist,
     * positional order is used as a tie-breaker.
     *
     * @param OrderInterface $order
     * @param array<int,array<string,mixed>> $responseItems
     * @return void
     */
    private function saveOrderItemIds(OrderInterface $order, array $responseItems): void
    {
        if (empty($responseItems)) {
            return;
        }

        // Group response items by SKU, preserving order for positional tie-breaking
        $responseBySku = [];
        foreach ($responseItems as $responseItem) {
            $sku = $responseItem['sku'] ?? null;
            $id = $responseItem['id'] ?? null;
            if ($sku !== null && $id !== null) {
                $responseBySku[$sku][] = $id;
            }
        }

        // Track how many items of each SKU we've matched (for duplicate SKU tie-breaking)
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

    /**
     * Update an existing order in Bob Go via PATCH /v2/orders.
     *
     * @param OrderInterface $order
     * @return void
     */
    public function updateOrder(OrderInterface $order): void
    {
        try {
            $payload = $this->orderMapper->mapOrderToUpdatePayload($order);
            $this->apiClient->patch('orders', $payload);

            $this->logger->info('Bob Go: Order updated successfully', [
                'order_id' => $order->getEntityId(),
                'increment_id' => $order->getIncrementId(),
                'bobgo_order_id' => $order->getData('bobgo_order_id'),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Bob Go: Failed to update order', [
                'order_id' => $order->getEntityId(),
                'increment_id' => $order->getIncrementId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
