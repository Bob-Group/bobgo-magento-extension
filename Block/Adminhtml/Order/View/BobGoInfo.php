<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Block\Adminhtml\Order\View;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Registry;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Bob Go panel on the admin order detail page.
 *
 * Renders sync status, last-synced/last-webhook timestamps, the Bob Go
 * order id + reference, and the JSON shipments array if reconciliation
 * has populated it. The Resync button posts to Order/Resync controller.
 */
class BobGoInfo extends Template
{
    private Registry $registry;

    public function __construct(
        Context $context,
        Registry $registry,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->registry = $registry;
    }

    public function getOrder(): ?OrderInterface
    {
        $order = $this->registry->registry('current_order')
            ?? $this->registry->registry('sales_order');
        return $order instanceof OrderInterface ? $order : null;
    }

    public function getResyncUrl(): string
    {
        $order = $this->getOrder();
        if ($order === null) {
            return '#';
        }
        return $this->getUrl('bobgo/order/resync', ['order_id' => $order->getEntityId()]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getShipments(): array
    {
        $order = $this->getOrder();
        if ($order === null) {
            return [];
        }
        $raw = $order->getData('bobgo_shipments');
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
