<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Observer;

use BobGroup\BobGo\Model\Config\ApiConfig;
use BobGroup\BobGo\Service\OrderPushService;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Pushes orders to Bob Go when they are saved in Magento.
 *
 * On the first save (no bobgo_order_id), the order is POSTed to Bob Go.
 * On subsequent saves, the order is PATCHed to keep Bob Go in sync.
 * Errors are caught and logged — order saving is never blocked.
 */
class OrderSaveObserver implements ObserverInterface
{
    /**
     * @var OrderPushService
     */
    private OrderPushService $orderPushService;

    /**
     * @var ApiConfig
     */
    private ApiConfig $apiConfig;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Re-entrancy guard. When pushOrder() saves the bobgo_order_id back to the
     * order, it re-triggers sales_order_save_after. This flag prevents the
     * observer from firing again during that nested save.
     *
     * @var bool
     */
    private bool $processing = false;

    public function __construct(
        OrderPushService $orderPushService,
        ApiConfig $apiConfig,
        LoggerInterface $logger
    ) {
        $this->orderPushService = $orderPushService;
        $this->apiConfig = $apiConfig;
        $this->logger = $logger;
    }

    /**
     * Handle order save event — push new orders or update existing ones in Bob Go.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        if ($this->processing) {
            return;
        }

        try {
            $order = $observer->getEvent()->getOrder();
            if (!$order) {
                $this->logger->warning('Bob Go: OrderSaveObserver received event without order');
                return;
            }

            if (!$this->apiConfig->isOrderPushEnabled() || !$this->apiConfig->isConfigured()) {
                return;
            }

            $bobgoOrderId = $order->getData('bobgo_order_id');

            $this->processing = true;
            try {
                if (empty($bobgoOrderId)) {
                    $this->orderPushService->pushOrder($order);
                } else {
                    $this->orderPushService->updateOrder($order);
                }
            } finally {
                $this->processing = false;
            }
        } catch (\Exception $e) {
            $this->logger->error('Bob Go: OrderSaveObserver failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
