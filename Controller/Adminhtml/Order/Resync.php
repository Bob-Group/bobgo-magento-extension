<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Controller\Adminhtml\Order;

use BobGroup\BobGo\Service\OrderPushService;
use BobGroup\BobGo\Service\ReconciliationService;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Admin "Resync" button on the order detail page.
 *
 * Re-pushes the order to Bob Go (POST if it has no bobgo_order_id yet,
 * PATCH otherwise — the dirty-check is bypassed because the operator
 * explicitly asked for a push) and then refetches the authoritative
 * shipment state via ReconciliationService.
 */
class Resync extends Action
{
    public const ADMIN_RESOURCE = 'Magento_Sales::actions_edit';

    private OrderRepositoryInterface $orderRepository;
    private OrderPushService $orderPushService;
    private ReconciliationService $reconciliationService;

    public function __construct(
        Context $context,
        OrderRepositoryInterface $orderRepository,
        OrderPushService $orderPushService,
        ReconciliationService $reconciliationService
    ) {
        parent::__construct($context);
        $this->orderRepository = $orderRepository;
        $this->orderPushService = $orderPushService;
        $this->reconciliationService = $reconciliationService;
    }

    public function execute()
    {
        $orderId = (int) $this->getRequest()->getParam('order_id');
        if ($orderId === 0) {
            $this->messageManager->addErrorMessage(__('Missing order id.'));
            return $this->_redirect('sales/order/index');
        }

        try {
            $order = $this->orderRepository->get($orderId);

            // Clear the sync hash so updateOrder definitely fires.
            $order->setData('bobgo_sync_hash', null);

            if ($order->getData('bobgo_order_id')) {
                $this->orderPushService->updateOrder($order);
            } else {
                $this->orderPushService->pushOrder($order);
            }

            $this->reconciliationService->reconcileOrder($order);

            $this->messageManager->addSuccessMessage(__('Bob Go resync triggered.'));
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Bob Go resync failed: %1', $e->getMessage()));
        }

        return $this->_redirect('sales/order/view', ['order_id' => $orderId]);
    }
}
