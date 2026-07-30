<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Service;

use BobGroup\BobGo\Api\OrderMapperInterface;
use BobGroup\BobGo\Model\Carrier\BobGo;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\UrlInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Store\Model\ScopeInterface;
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
    private const LBS_TO_KG = 0.45359237;

    /** @var ProductRepositoryInterface */
    private $productRepository;

    /** @var StoreManagerInterface */
    private $storeManager;

    /** @var ScopeConfigInterface */
    private $scopeConfig;

    /** @var DisplayOptionsMapper */
    private $displayOptions;

    /**
     * Per-payload memo for product loads.
     *
     * @var array<int,mixed>
     */
    private $productCache = [];

    public function __construct(
        ProductRepositoryInterface $productRepository,
        StoreManagerInterface $storeManager,
        ScopeConfigInterface $scopeConfig,
        DisplayOptionsMapper $displayOptions
    ) {
        $this->productRepository = $productRepository;
        $this->storeManager = $storeManager;
        $this->scopeConfig = $scopeConfig;
        $this->displayOptions = $displayOptions;
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

        $payload = [
            'channel_ref_id'                    => (string) $order->getEntityId(),
            'channel_order_number'              => $order->getIncrementId(),
            'customer_name'                     => $order->getCustomerFirstname() ?: ($billingAddress ? $billingAddress->getFirstname() : ''),
            'customer_surname'                  => $order->getCustomerLastname() ?: ($billingAddress ? $billingAddress->getLastname() : ''),
            'customer_email'                    => $order->getCustomerEmail() ?: '',
            'customer_phone'                    => $billingAddress ? ($billingAddress->getTelephone() ?: '') : '',
            'currency'                          => $order->getOrderCurrencyCode(),
            'buyer_selected_service_code'       => $this->serviceCode($order),
            'buyer_selected_shipping_cost'      => (float) $order->getData('shipping_incl_tax'),
            'buyer_selected_shipping_method'    => $order->getShippingDescription(),
            'payment_status'                    => $this->mapPaymentStatus($order),
            'delivery_address'                  => $this->mapShippingAddress($order),
            'order_items'                       => $this->mapItems($order),
        ];

        // Omitted when empty or zero rather than sent as blanks, so the payload
        // hash doesn't churn on fields the store doesn't populate.
        $note = trim((string) ($order->getCustomerNote() ?? ''));
        if ($note !== '') {
            $payload['note'] = $note;
        }

        $tax = round((float) $order->getTaxAmount(), 2);
        if ($tax > 0.0) {
            $payload['total_tax'] = $tax;
        }

        // Magento records discounts as negative; Bob Go wants the magnitude.
        $discount = round(abs((float) $order->getDiscountAmount()), 2);
        if ($discount > 0.0) {
            $payload['total_discount'] = $discount;
        }

        $placedAt = $this->toIso8601($order->getCreatedAt());
        if ($placedAt !== null) {
            $payload['date_placed_on_channel'] = $placedAt;
        }

        return $payload;
    }

    /**
     * Magento stores created_at as a UTC 'Y-m-d H:i:s' string.
     *
     * @param mixed $value
     */
    private function toIso8601($value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * The Bob Go service code the shopper picked, or null when there isn't one.
     *
     * The stored shipping method round-trips as the service code because
     * _formatRates() strips the `bobgo_` prefix Bob Go sends and Magento
     * re-prepends the carrier code. The exception is our free-shipping rate,
     * which is a local sentinel rather than anything Bob Go can resolve — for
     * those the field is omitted and the merchant picks the courier on Bob Go.
     */
    private function serviceCode(OrderInterface $order): ?string
    {
        $method = (string) ($order->getShippingMethod() ?: '');
        if ($method === '' || $method === BobGo::CODE . '_' . BobGo::FREE_SHIPPING_METHOD) {
            return null;
        }
        return $method;
    }

    /**
     * @param OrderInterface $order
     * @return string
     */
    private function mapPaymentStatus(OrderInterface $order): string
    {
        // Refunded wins: an order refunded in full is not "paid", and telling Bob
        // Go it is invites a shipment for something the customer got money back for.
        if ((float) $order->getTotalRefunded() > 0.0) {
            return 'refunded';
        }

        // Awaiting an offline payment or a gateway review — genuinely different
        // from a customer who simply hasn't paid.
        if (in_array((string) $order->getState(), ['pending_payment', 'payment_review'], true)) {
            return 'pending';
        }

        return (float) $order->getTotalDue() <= 0.0 ? 'paid' : 'unpaid';
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

        $suburb = $this->extractSuburb($address);
        $city = (string) ($address->getCity() ?? '');

        return [
            'company'        => $address->getCompany() ?: '',
            'street_address' => $streetAddress,
            'local_area'     => $suburb !== '' ? $suburb : $city,
            'city'           => $city,
            'zone'           => $address->getRegion(),
            'country'        => $address->getCountryId(),
            'code'           => $address->getPostcode(),
        ];
    }

    /**
     * Pull the suburb off the order shipping address.
     *
     * The canonical channel is the OrderAddressInterface extension attribute
     * (set by ToOrderAddressPlugin at conversion time). Falls back to the
     * legacy custom-attribute API and then to raw `getData('suburb')` so
     * orders placed before the plugin was wired still resolve correctly.
     */
    private function extractSuburb(\Magento\Sales\Api\Data\OrderAddressInterface $address): string
    {
        if (method_exists($address, 'getExtensionAttributes')) {
            $ext = $address->getExtensionAttributes();
            if ($ext && method_exists($ext, 'getSuburb')) {
                $value = $ext->getSuburb();
                if (is_scalar($value) && (string) $value !== '') {
                    return (string) $value;
                }
            }
        }

        if (method_exists($address, 'getCustomAttribute')) {
            $attr = $address->getCustomAttribute('suburb');
            if ($attr && $attr->getValue() !== null && $attr->getValue() !== '') {
                return (string) $attr->getValue();
            }
        }

        if (method_exists($address, 'getData')) {
            $direct = $address->getData('suburb');
            if (is_scalar($direct) && (string) $direct !== '') {
                return (string) $direct;
            }
        }

        return '';
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
            'unit_weight_kg'    => $this->normaliseWeightKg((float) $item->getWeight()),
            'channel_image_url' => $this->getProductImageUrl($item),
        ];

        $bobgoItemId = $item->getData('bobgo_order_item_id');
        if ($bobgoItemId !== null && $bobgoItemId !== '') {
            $mapped['id'] = (int) $bobgoItemId;
        }

        // What the customer chose: variant attributes, custom options,
        // personalisation text. This is what a picker in the warehouse needs.
        $displayOptions = $this->displayOptions->map($item);
        if (!empty($displayOptions)) {
            $mapped['display_options'] = $displayOptions;
        }

        foreach ($this->itemDimensions($item) as $key => $value) {
            $mapped[$key] = $value;
        }

        return $mapped;
    }

    /**
     * Per-item dimensions, when the merchant has told us which product attributes
     * hold them.
     *
     * Magento has no native length/width/height attributes, so there is nothing to
     * read by default — hence the config. Omitted entirely when unset or zero,
     * because a zero is a claim about the parcel rather than an absence of one.
     *
     * @return array<string,float>
     */
    private function itemDimensions(OrderItemInterface $item): array
    {
        $codes = [
            'unit_length_cm' => $this->dimensionAttribute('length'),
            'unit_width_cm' => $this->dimensionAttribute('width'),
            'unit_height_cm' => $this->dimensionAttribute('height'),
        ];
        if (array_filter($codes) === []) {
            return [];
        }

        $product = $this->loadProduct($item);
        if ($product === null) {
            return [];
        }

        $dimensions = [];
        foreach ($codes as $payloadKey => $attributeCode) {
            if ($attributeCode === null) {
                continue;
            }
            $value = $product->getData($attributeCode);
            if (is_numeric($value) && (float) $value > 0.0) {
                $dimensions[$payloadKey] = round((float) $value, 2);
            }
        }
        return $dimensions;
    }

    private function dimensionAttribute(string $which): ?string
    {
        $code = $this->scopeConfig->getValue(
            'carriers/bobgo/dimension_attribute_' . $which,
            ScopeInterface::SCOPE_STORE
        );
        return is_string($code) && trim($code) !== '' ? trim($code) : null;
    }

    /**
     * @param OrderItemInterface $item
     * @return string|null
     */
    /**
     * Convert a Magento order item weight to kilograms.
     *
     * Bob Go expects kilograms. Magento stores the item weight in the store's
     * configured weight unit (KGS or LBS). We normalise to KG at payload-build
     * time so the order row itself stays in its native unit — earlier versions
     * mutated the row in a beforeSave plugin and re-converted on every save,
     * silently corrupting the data.
     */
    private function normaliseWeightKg(float $weight): float
    {
        if ($weight <= 0.0) {
            return 0.0;
        }

        $unit = $this->scopeConfig->getValue('general/locale/weight_unit', ScopeInterface::SCOPE_STORE);
        if (is_string($unit) && strtolower($unit) === 'lbs') {
            $weight = $weight * self::LBS_TO_KG;
        }

        // One decimal, to match what Bob Go persists server-side. Sending more
        // precision than the server keeps means the value we send and the value
        // it stores differ, which matters the moment anything compares them.
        return round($weight, 1);
    }

    private function getProductImageUrl(OrderItemInterface $item): ?string
    {
        $product = $this->loadProduct($item);
        if ($product === null) {
            return null;
        }

        $imagePath = $product->getImage();
        if (!$imagePath || $imagePath === 'no_selection') {
            return null;
        }

        $mediaBaseUrl = $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);

        return rtrim($mediaBaseUrl, '/') . '/catalog/product' . $imagePath;
    }

    /**
     * Load an item's product once per payload build.
     *
     * Both the image URL and the dimensions need it, and mapping an order calls
     * this once per line — so without the memo a ten-line order did twenty
     * product loads.
     *
     * @return \Magento\Catalog\Api\Data\ProductInterface|null
     */
    private function loadProduct(OrderItemInterface $item)
    {
        $productId = (int) $item->getProductId();
        if ($productId <= 0) {
            return null;
        }
        if (array_key_exists($productId, $this->productCache)) {
            return $this->productCache[$productId];
        }

        try {
            $this->productCache[$productId] = $this->productRepository->getById($productId);
        } catch (NoSuchEntityException $e) {
            $this->productCache[$productId] = null;
        }

        return $this->productCache[$productId];
    }
}
