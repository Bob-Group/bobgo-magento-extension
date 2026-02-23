<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Observer;

use BobGroup\BobGo\Model\Config\ApiConfig;
use BobGroup\BobGo\Service\OrderPushService;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

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

    public function __construct(
        OrderPushService $orderPushService,
        ApiConfig $apiConfig,
        LoggerInterface $logger
    ) {
        $this->orderPushService = $orderPushService;
        $this->apiConfig = $apiConfig;
        $this->logger = $logger;
    }

    public function execute(Observer $observer): void
    {
        try {
            $order = $observer->getEvent()->getOrder();
            if (!$order) {
                return;
            }

            if (!$this->apiConfig->isOrderPushEnabled() || !$this->apiConfig->isConfigured()) {
                return;
            }

            $bobgoOrderId = $order->getData('bobgo_order_id');

            if (empty($bobgoOrderId)) {
                $this->orderPushService->pushOrder($order);
            } else {
                $this->orderPushService->updateOrder($order);
            }
        } catch (\Exception $e) {
            $this->logger->error('Bob Go: OrderSaveObserver failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
