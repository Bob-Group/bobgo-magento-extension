<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Plugin;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderExtensionFactory;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Manages the bobgo_order_id extension attribute on orders.
 *
 * Ensures the Bob Go order ID is loaded into extension attributes when orders
 * are retrieved (afterGet/afterGetList) and persisted from extension attributes
 * back to the order data before save (beforeSave).
 */
class OrderRepositoryPlugin
{
    /**
     * @var OrderExtensionFactory
     */
    private OrderExtensionFactory $orderExtensionFactory;

    /**
     * @param OrderExtensionFactory $orderExtensionFactory
     */
    public function __construct(OrderExtensionFactory $orderExtensionFactory)
    {
        $this->orderExtensionFactory = $orderExtensionFactory;
    }

    /**
     * Load bobgo_order_id into extension attributes after fetching a single order.
     *
     * @param OrderRepositoryInterface $subject
     * @param OrderInterface $order
     * @return OrderInterface
     */
    public function afterGet(
        OrderRepositoryInterface $subject,
        OrderInterface $order
    ): OrderInterface {
        $this->loadBobGoOrderId($order);
        return $order;
    }

    /**
     * Load bobgo_order_id into extension attributes for each order in a search result.
     *
     * @param OrderRepositoryInterface $subject
     * @param OrderSearchResultInterface $searchResult
     * @return OrderSearchResultInterface
     */
    public function afterGetList(
        OrderRepositoryInterface $subject,
        OrderSearchResultInterface $searchResult
    ): OrderSearchResultInterface {
        foreach ($searchResult->getItems() as $order) {
            $this->loadBobGoOrderId($order);
        }
        return $searchResult;
    }

    /**
     * Sync bobgo_order_id from extension attributes to order data before saving.
     *
     * @param OrderRepositoryInterface $subject
     * @param OrderInterface $order
     * @return array{0: OrderInterface}
     */
    public function beforeSave(
        OrderRepositoryInterface $subject,
        OrderInterface $order
    ): array {
        $extensionAttributes = $order->getExtensionAttributes();
        if ($extensionAttributes && $extensionAttributes->getBobgoOrderId()) {
            $order->setData('bobgo_order_id', $extensionAttributes->getBobgoOrderId());
        }
        return [$order];
    }

    /**
     * Populate the bobgo_order_id extension attribute from order data.
     *
     * @param OrderInterface $order
     * @return void
     */
    private function loadBobGoOrderId(OrderInterface $order): void
    {
        $bobgoOrderId = $order->getData('bobgo_order_id');
        if ($bobgoOrderId) {
            $extensionAttributes = $order->getExtensionAttributes();
            if ($extensionAttributes === null) {
                $extensionAttributes = $this->orderExtensionFactory->create();
            }
            $extensionAttributes->setBobgoOrderId($bobgoOrderId);
            $order->setExtensionAttributes($extensionAttributes);
        }
    }
}
