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
            'channel_ref_id'                    => (string) $order->getEntityId(),
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
     * Bob Go expects ONE line per shipped product. For configurable products,
     * Magento records two order items — the configurable parent (customer-paid
     * price, base product name) and the simple child (variant name + SKU, price 0).
     * We send the simple child (it has the variant info Bob Go needs) and copy the
     * parent's price onto it so Bob Go sees the right amount.
     *
     * @param OrderInterface $order
     * @return array<int,array<string,mixed>>
     */
    private function mapItems(OrderInterface $order): array
    {
        $allItems = $order->getItems() ?: [];

        // Build id → item lookup so children can find their parent for price.
        $byId = [];
        foreach ($allItems as $item) {
            $byId[(int) $item->getItemId()] = $item;
        }

        $mapped = [];
        foreach ($allItems as $item) {
            // Skip configurable parents — the simple child carries the variant
            // name and SKU and is what should be shipped/fulfilled.
            if ($item->getProductType() === 'configurable') {
                continue;
            }

            $entry = $this->mapItem($item);

            // If this simple is a child of a configurable, the customer-paid price
            // sits on the parent (child's price is 0). Mirror it over.
            $parentItemId = $item->getParentItemId();
            if (
                $parentItemId !== null
                && $parentItemId !== ''
                && isset($byId[(int) $parentItemId])
                && (float) $entry['unit_price'] === 0.0
            ) {
                $entry['unit_price'] = (float) $byId[(int) $parentItemId]->getPriceInclTax();
            }

            $mapped[] = $entry;
        }

        return $mapped;
    }

    /**
     * @param OrderItemInterface $item
     * @return array<string,mixed>
     */
    private function mapItem(OrderItemInterface $item): array
    {
        $mapped = [
            'channel_ref_id'    => (int) $item->getItemId(),
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
