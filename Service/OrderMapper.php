<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Service;

use BobGroup\BobGo\Api\OrderMapperInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;

class OrderMapper implements OrderMapperInterface
{
    /**
     * @var array<string,string>
     */
    private const STATUS_MAP = [
        'canceled' => 'Cancelled',
        'complete' => 'Completed',
        'closed'   => 'Closed',
        'holded'   => 'On Hold',
    ];

    /**
     * @param OrderInterface $order
     * @return array<string,mixed>
     */
    public function mapOrderToPayload(OrderInterface $order): array
    {
        return $this->buildPayload($order);
    }

    /**
     * @param OrderInterface $order
     * @return array<string,mixed>
     */
    public function mapOrderToUpdatePayload(OrderInterface $order): array
    {
        $payload = $this->buildPayload($order);

        $bobgoOrderId = $order->getData('bobgo_order_id');
        if ($bobgoOrderId) {
            $payload['id'] = $bobgoOrderId;
        }

        return $payload;
    }

    /**
     * @param OrderInterface $order
     * @return array<string,mixed>
     */
    private function buildPayload(OrderInterface $order): array
    {
        $payload = [
            'ChannelRefID'                     => (string) $order->getEntityId(),
            'ChannelOrderNumber'               => $order->getIncrementId(),
            'TotalPrice'                       => (float) $order->getGrandTotal(),
            'TotalTax'                         => (float) $order->getData('tax_amount'),
            'TotalDiscount'                    => abs((float) $order->getDiscountAmount()),
            'Currency'                         => $order->getOrderCurrencyCode(),
            'Status'                           => $this->mapStatus($order->getStatus()),
            'PaymentStatus'                    => $this->mapPaymentStatus($order),
            'BuyerSelectedShippingMethodCodes' => [$order->getShippingMethod()],
            'BuyerSelectedShippingCost'        => (float) $order->getData('shipping_incl_tax'),
            'BuyerSelectedShippingMethod'      => $order->getShippingDescription(),
            'DatePlacedOnChannel'              => $order->getCreatedAt(),
            'LastModifiedOnChannel'            => $order->getUpdatedAt(),
            'DeliveryAddress'                  => $this->mapShippingAddress($order),
            'Items'                            => $this->mapItems($order),
        ];

        return $payload;
    }

    /**
     * @param string|null $status
     * @return string
     */
    private function mapStatus(?string $status): string
    {
        if ($status === null) {
            return 'Active';
        }

        return self::STATUS_MAP[$status] ?? 'Active';
    }

    /**
     * @param OrderInterface $order
     * @return string
     */
    private function mapPaymentStatus(OrderInterface $order): string
    {
        $totalDue = (float) $order->getTotalDue();
        $grandTotal = (float) $order->getGrandTotal();

        if ($totalDue <= 0.0) {
            return 'Paid';
        }

        if (abs($totalDue - $grandTotal) < 0.01) {
            return 'Unpaid';
        }

        return 'Partially Paid';
    }

    /**
     * @param OrderInterface $order
     * @return array<string,mixed>|null
     */
    private function mapShippingAddress(OrderInterface $order): ?array
    {
        $address = $order->getShippingAddress();
        if ($address === null) {
            return null;
        }

        $street = $address->getStreet();
        $streetAddress = is_array($street) ? implode(', ', $street) : (string) $street;

        return [
            'StreetAddress' => $streetAddress,
            'LocalArea'     => $address->getCity(),
            'City'          => $address->getCity(),
            'Code'          => $address->getPostcode(),
            'Zone'          => $address->getRegion(),
            'Country'       => $address->getCountryId(),
            'Company'       => $address->getCompany(),
        ];
    }

    /**
     * @param OrderInterface $order
     * @return array<int,array<string,mixed>>
     */
    private function mapItems(OrderInterface $order): array
    {
        $items = [];

        foreach ($order->getItems() as $item) {
            // Skip child items (e.g. configurable product children)
            if ($item->getParentItemId()) {
                continue;
            }

            $items[] = $this->mapItem($item);
        }

        return $items;
    }

    /**
     * @param OrderItemInterface $item
     * @return array<string,mixed>
     */
    private function mapItem(OrderItemInterface $item): array
    {
        return [
            'ChannelRefID' => (string) $item->getItemId(),
            'SKU'          => $item->getSku(),
            'Description'  => $item->getName(),
            'UnitPrice'    => (float) $item->getPriceInclTax(),
            'Qty'          => (int) $item->getQtyOrdered(),
            'UnitWeightKg' => (float) $item->getWeight(),
        ];
    }
}
