<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Service;

use BobGroup\BobGo\Api\OrderMapperInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\UrlInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Transforms Magento orders into Bob Go API payload format.
 *
 * Handles mapping of order data, shipping address, line items, order status,
 * and payment status. Used by OrderPushService for both create (POST) and
 * update (PATCH) operations.
 */
class OrderMapper implements OrderMapperInterface
{
    /** @var ProductRepositoryInterface */
    private $productRepository;

    /** @var StoreManagerInterface */
    private $storeManager;

    public function __construct(
        ProductRepositoryInterface $productRepository,
        StoreManagerInterface $storeManager
    ) {
        $this->productRepository = $productRepository;
        $this->storeManager = $storeManager;
    }
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
            $payload['id'] = (int) $bobgoOrderId;
        }

        return $payload;
    }

    /**
     * @param OrderInterface $order
     * @return array<string,mixed>
     */
    private function buildPayload(OrderInterface $order): array
    {
        $billingAddress = $order->getBillingAddress();

        return [
            'channel_order_number'              => $order->getIncrementId(),
            'customer_name'                     => $order->getCustomerFirstname() ?: ($billingAddress ? $billingAddress->getFirstname() : ''),
            'customer_surname'                  => $order->getCustomerLastname() ?: ($billingAddress ? $billingAddress->getLastname() : ''),
            'customer_email'                    => $order->getCustomerEmail() ?: '',
            'customer_phone'                    => $billingAddress ? ($billingAddress->getTelephone() ?: '') : '',
            'currency'                          => $order->getOrderCurrencyCode(),
            'buyer_selected_service_code'       => $order->getShippingMethod(),
            'buyer_selected_shipping_cost'      => (float) $order->getData('shipping_incl_tax'),
            'buyer_selected_shipping_method'    => $order->getShippingDescription(),
            'payment_status'                    => $this->mapPaymentStatus($order),
            'delivery_address'                  => $this->mapShippingAddress($order),
            'order_items'                       => $this->mapItems($order),
        ];
    }

    /**
     * @param OrderInterface $order
     * @return string
     */
    private function mapPaymentStatus(OrderInterface $order): string
    {
        $totalDue = (float) $order->getTotalDue();

        if ($totalDue <= 0.0) {
            return 'paid';
        }

        return 'unpaid';
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
            'company'        => $address->getCompany() ?: '',
            'street_address' => $streetAddress,
            'local_area'     => $address->getCity(),
            'city'           => $address->getCity(),
            'zone'           => $address->getRegion(),
            'country'        => $address->getCountryId(),
            'code'           => $address->getPostcode(),
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
        $mapped = [
            'description'       => $item->getName() ?: '',
            'sku'               => $item->getSku(),
            'unit_price'        => (float) $item->getPriceInclTax(),
            'qty'               => (int) $item->getQtyOrdered(),
            'unit_weight_kg'    => (float) $item->getWeight(),
            'channel_image_url' => $this->getProductImageUrl($item),
        ];

        $bobgoItemId = $item->getData('bobgo_order_item_id');
        if ($bobgoItemId !== null && $bobgoItemId !== '') {
            $mapped['id'] = (int) $bobgoItemId;
        }

        return $mapped;
    }

    /**
     * @param OrderItemInterface $item
     * @return string|null
     */
    private function getProductImageUrl(OrderItemInterface $item): ?string
    {
        try {
            $product = $this->productRepository->getById((int) $item->getProductId());
            $imagePath = $product->getImage();

            if (!$imagePath || $imagePath === 'no_selection') {
                return null;
            }

            $mediaBaseUrl = $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);

            return rtrim($mediaBaseUrl, '/') . '/catalog/product' . $imagePath;
        } catch (NoSuchEntityException $e) {
            return null;
        }
    }
}
