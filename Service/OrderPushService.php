<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Service;

use BobGroup\BobGo\Api\BobGoApiClient;
use BobGroup\BobGo\Api\OrderMapperInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

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
