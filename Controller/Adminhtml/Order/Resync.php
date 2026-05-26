<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Controller\Adminhtml\Order;

use BobGroup\BobGo\Service\OrderPushService;
use BobGroup\BobGo\Service\ReconciliationService;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Admin "Resync" button on the order detail page.
 *
 * Re-pushes the order to Bob Go (POST if it has no bobgo_order_id yet,
 * PATCH otherwise — the dirty-check is bypassed because the operator
 * explicitly asked for a push) and then refetches the authoritative
 * shipment state via ReconciliationService.
 *
 * POST-only — implements HttpPostActionInterface so Magento enforces
 * form-key verification automatically. Triggered from the admin order
 * panel via a form button (see view/adminhtml/templates/order/view/bobgo_info.phtml).
 */
class Resync extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magento_Sales::actions_edit';

    private OrderRepositoryInterface $orderRepository;
    private OrderPushService $orderPushService;
    private ReconciliationService $reconciliationService;
    private LoggerInterface $bobgoLogger;

    public function __construct(
        Context $context,
        OrderRepositoryInterface $orderRepository,
        OrderPushService $orderPushService,
        ReconciliationService $reconciliationService,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->orderRepository = $orderRepository;
        $this->orderPushService = $orderPushService;
        $this->reconciliationService = $reconciliationService;
        $this->bobgoLogger = $logger;
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

            $pushSucceeded = $order->getData('bobgo_order_id')
                ? $this->orderPushService->updateOrder($order)
                : $this->orderPushService->pushOrder($order);

            // Reconciliation is best-effort and runs even on push failure so
            // the panel can still reflect shipment state Bob Go knows about.
            $this->reconciliationService->reconcileOrder($order);

            if ($pushSucceeded) {
                $this->messageManager->addSuccessMessage(__('Bob Go resync completed.'));
            } else {
                $this->messageManager->addErrorMessage(
                    __('Bob Go resync: push to Bob Go failed. See bobgo_sync_log for details.')
                );
            }
        } catch (\Throwable $e) {
            $this->bobgoLogger->error('Bob Go: admin resync failed', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->messageManager->addErrorMessage(__('Bob Go resync failed: %1', $e->getMessage()));
        }

        return $this->_redirect('sales/order/view', ['order_id' => $orderId]);
    }
}
