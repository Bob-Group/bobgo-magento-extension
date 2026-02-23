<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Service;

use BobGroup\BobGo\Api\BobGoApiClient;
use BobGroup\BobGo\Api\BobGoApiException;
use BobGroup\BobGo\Model\Config\ApiConfig;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * Cron job that polls the Bob Go API for new fulfillments.
 *
 * Runs every 15 minutes (configured in etc/crontab.xml) as a fallback
 * mechanism alongside real-time webhooks. Finds all orders in "processing"
 * state that have a Bob Go order ID, fetches their fulfillments from the API,
 * and delegates to FulfillmentService to create Magento shipments.
 */
class FulfillmentCronService
{
    /**
     * @var BobGoApiClient
     */
    private BobGoApiClient $apiClient;

    /**
     * @var FulfillmentService
     */
    private FulfillmentService $fulfillmentService;

    /**
     * @var OrderRepositoryInterface
     */
    private OrderRepositoryInterface $orderRepository;

    /**
     * @var SearchCriteriaBuilder
     */
    private SearchCriteriaBuilder $searchCriteriaBuilder;

    /**
     * @var ApiConfig
     */
    private ApiConfig $apiConfig;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    public function __construct(
        BobGoApiClient $apiClient,
        FulfillmentService $fulfillmentService,
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        ApiConfig $apiConfig,
        LoggerInterface $logger
    ) {
        $this->apiClient = $apiClient;
        $this->fulfillmentService = $fulfillmentService;
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->apiConfig = $apiConfig;
        $this->logger = $logger;
    }

    /**
     * Cron job entry point - polls Bob Go for new fulfillments
     */
    public function execute(): void
    {
        if (!$this->apiConfig->isFulfillmentSyncEnabled()) {
            return;
        }

        if (!$this->apiConfig->isConfigured()) {
            $this->logger->warning('Bob Go fulfillment cron: API key not configured');
            return;
        }

        try {
            $orders = $this->getProcessingOrdersWithBobGoId();

            if (empty($orders)) {
                return;
            }

            $this->logger->info('Bob Go fulfillment cron: checking fulfillments', [
                'order_count' => count($orders),
            ]);

            foreach ($orders as $order) {
                $this->syncFulfillmentsForOrder($order);
            }
        } catch (\Exception $e) {
            $this->logger->error('Bob Go fulfillment cron: unexpected error', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Find orders that are still processing and have a Bob Go order ID
     *
     * @return \Magento\Sales\Api\Data\OrderInterface[]
     */
    private function getProcessingOrdersWithBobGoId(): array
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('state', Order::STATE_PROCESSING)
            ->addFilter('bobgo_order_id', true, 'notnull')
            ->create();

        $result = $this->orderRepository->getList($searchCriteria);
        return $result->getItems();
    }

    /**
     * Fetch and process fulfillments for a single order from Bob Go
     *
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     */
    private function syncFulfillmentsForOrder(\Magento\Sales\Api\Data\OrderInterface $order): void
    {
        $bobgoOrderId = $order->getData('bobgo_order_id');
        if (empty($bobgoOrderId)) {
            return;
        }

        try {
            $fulfillments = $this->apiClient->get('order-fulfillments', [
                'order_id' => $bobgoOrderId,
            ]);

            if (empty($fulfillments)) {
                return;
            }

            foreach ($fulfillments as $fulfillment) {
                // Ensure the channel_ref_id is set for FulfillmentService
                if (!isset($fulfillment['channel_ref_id'])) {
                    $fulfillment['channel_ref_id'] = (string) $order->getEntityId();
                }
                $this->fulfillmentService->processFulfillment($fulfillment);
            }
        } catch (BobGoApiException $e) {
            $this->logger->error('Bob Go fulfillment cron: API error for order', [
                'order_id' => $order->getEntityId(),
                'bobgo_order_id' => $bobgoOrderId,
                'error' => $e->getMessage(),
                'status_code' => $e->getStatusCode(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Bob Go fulfillment cron: error processing order', [
                'order_id' => $order->getEntityId(),
                'bobgo_order_id' => $bobgoOrderId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
