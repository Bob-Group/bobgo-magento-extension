<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Plugin;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderExtensionFactory;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

class OrderRepositoryPlugin
{
    /**
     * @var OrderExtensionFactory
     */
    private OrderExtensionFactory $orderExtensionFactory;

    public function __construct(OrderExtensionFactory $orderExtensionFactory)
    {
        $this->orderExtensionFactory = $orderExtensionFactory;
    }

    public function afterGet(
        OrderRepositoryInterface $subject,
        OrderInterface $order
    ): OrderInterface {
        $this->loadBobGoOrderId($order);
        return $order;
    }

    public function afterGetList(
        OrderRepositoryInterface $subject,
        OrderSearchResultInterface $searchResult
    ): OrderSearchResultInterface {
        foreach ($searchResult->getItems() as $order) {
            $this->loadBobGoOrderId($order);
        }
        return $searchResult;
    }

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
