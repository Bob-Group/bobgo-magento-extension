<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Service;

use BobGroup\BobGo\Service\DisplayOptionsMapper;
use BobGroup\BobGo\Service\OrderMapper;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\UrlInterface;
use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class OrderMapperTest extends TestCase
{
    /**
     * @var OrderMapper
     */
    private $mapper;

    /**
     * @var ProductRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private $productRepository;

    /**
     * @var StoreManagerInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private $storeManager;

    /**
     * @var ScopeConfigInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private $scopeConfig;

    protected function setUp(): void
    {
        $this->productRepository = $this->createMock(ProductRepositoryInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);

        // Default weight unit is KGS so tests don't have to think about it.
        $this->scopeConfig->method('getValue')->willReturn('kgs');

        $store = $this->createMock(StoreInterface::class);
        $store->method('getBaseUrl')
            ->with(UrlInterface::URL_TYPE_MEDIA)
            ->willReturn('https://example.com/media/');
        $this->storeManager->method('getStore')->willReturn($store);

        // Default: product not found (tests that don't care about images)
        $this->productRepository->method('getById')
            ->willThrowException(new NoSuchEntityException());

        $this->mapper = new OrderMapper(
            $this->productRepository,
            $this->storeManager,
            $this->scopeConfig,
            new DisplayOptionsMapper($this->scopeConfig)
        );
    }

    public function testMapOrderToPayload(): void
    {
        $item = $this->createMock(OrderItemInterface::class);
        $item->method('getParentItemId')->willReturn(null);
        $item->method('getItemId')->willReturn(42);
        $item->method('getSku')->willReturn('TEST-SKU');
        $item->method('getName')->willReturn('Test Product');
        $item->method('getPriceInclTax')->willReturn(199.99);
        $item->method('getQtyOrdered')->willReturn(2.0);
        $item->method('getWeight')->willReturn(1.5);

        $address = $this->createMock(OrderAddressInterface::class);
        $address->method('getStreet')->willReturn(['123 Test St', 'Apt 4']);
        $address->method('getCity')->willReturn('Cape Town');
        $address->method('getPostcode')->willReturn('8001');
        $address->method('getRegion')->willReturn('Western Cape');
        $address->method('getCountryId')->willReturn('ZA');
        $address->method('getCompany')->willReturn('Test Co');

        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(100);
        $order->method('getIncrementId')->willReturn('000000100');
        $order->method('getGrandTotal')->willReturn(399.98);
        $order->method('getTotalDue')->willReturn(0.0);
        $order->method('getDiscountAmount')->willReturn(-50.00);
        $order->method('getOrderCurrencyCode')->willReturn('ZAR');
        $order->method('getStatus')->willReturn('processing');
        $order->method('getShippingMethod')->willReturn('bobgo_standard');
        $order->method('getShippingDescription')->willReturn('Standard Delivery');
        $order->method('getCreatedAt')->willReturn('2026-01-15 10:00:00');
        $order->method('getUpdatedAt')->willReturn('2026-01-15 10:05:00');
        $order->method('getShippingAddress')->willReturn($address);
        $order->method('getBillingAddress')->willReturn(null);
        $order->method('getCustomerFirstname')->willReturn('Test');
        $order->method('getCustomerLastname')->willReturn('Customer');
        $order->method('getCustomerEmail')->willReturn('test@example.com');
        $order->method('getItems')->willReturn([$item]);
        $order->method('getData')->willReturnMap([
            ['shipping_incl_tax', 75.00],
            ['bobgo_order_id', null],
        ]);

        $payload = $this->mapper->mapOrderToPayload($order);

        $this->assertSame('000000100', $payload['channel_order_number']);
        $this->assertSame('Test', $payload['customer_name']);
        $this->assertSame('Customer', $payload['customer_surname']);
        $this->assertSame('test@example.com', $payload['customer_email']);
        $this->assertSame('ZAR', $payload['currency']);
        $this->assertSame('paid', $payload['payment_status']);
        $this->assertSame(75.00, $payload['buyer_selected_shipping_cost']);
        $this->assertSame('Standard Delivery', $payload['buyer_selected_shipping_method']);
        $this->assertSame('bobgo_standard', $payload['buyer_selected_service_code']);

        $this->assertSame('123 Test St, Apt 4', $payload['delivery_address']['street_address']);
        $this->assertSame('Cape Town', $payload['delivery_address']['city']);
        $this->assertSame('Cape Town', $payload['delivery_address']['local_area']);
        $this->assertSame('8001', $payload['delivery_address']['code']);
        $this->assertSame('Western Cape', $payload['delivery_address']['zone']);
        $this->assertSame('ZA', $payload['delivery_address']['country']);
        $this->assertSame('Test Co', $payload['delivery_address']['company']);

        $this->assertCount(1, $payload['order_items']);
        $this->assertSame('TEST-SKU', $payload['order_items'][0]['sku']);
        $this->assertSame('Test Product', $payload['order_items'][0]['description']);
        $this->assertSame(199.99, $payload['order_items'][0]['unit_price']);
        $this->assertSame(2, $payload['order_items'][0]['qty']);
        $this->assertSame(1.5, $payload['order_items'][0]['unit_weight_kg']);
    }

    public function testPaymentStatusPaid(): void
    {
        $order = $this->createOrderMock(['totalDue' => 0.0, 'grandTotal' => 100.00]);
        $payload = $this->mapper->mapOrderToPayload($order);

        $this->assertSame('paid', $payload['payment_status']);
    }

    public function testPaymentStatusUnpaid(): void
    {
        $order = $this->createOrderMock(['totalDue' => 100.00, 'grandTotal' => 100.00]);
        $payload = $this->mapper->mapOrderToPayload($order);

        $this->assertSame('unpaid', $payload['payment_status']);
    }

    public function testPaymentStatusPartialDueIsUnpaid(): void
    {
        $order = $this->createOrderMock(['totalDue' => 50.00, 'grandTotal' => 100.00]);
        $payload = $this->mapper->mapOrderToPayload($order);

        $this->assertSame('unpaid', $payload['payment_status']);
    }

    public function testConfigurableParentSkippedAndChildPriceTakenFromParent(): void
    {
        // Configurable parent: customer-paid price R22, base product name.
        $parentItem = $this->createMock(OrderItemInterface::class);
        $parentItem->method('getParentItemId')->willReturn(null);
        $parentItem->method('getProductType')->willReturn('configurable');
        $parentItem->method('getItemId')->willReturn(1);
        $parentItem->method('getSku')->willReturn('WS12');
        $parentItem->method('getName')->willReturn('Radiant Tee');
        $parentItem->method('getPriceInclTax')->willReturn(22.00);
        $parentItem->method('getQtyOrdered')->willReturn(1.0);
        $parentItem->method('getWeight')->willReturn(0.5);

        // Simple child: variant name + SKU, price 0 (Magento puts price on parent).
        $childItem = $this->createMock(OrderItemInterface::class);
        $childItem->method('getParentItemId')->willReturn(1);
        $childItem->method('getProductType')->willReturn('simple');
        $childItem->method('getItemId')->willReturn(2);
        $childItem->method('getSku')->willReturn('WS12-M-Orange');
        $childItem->method('getName')->willReturn('Radiant Tee-M-Orange');
        $childItem->method('getPriceInclTax')->willReturn(0.0);
        $childItem->method('getQtyOrdered')->willReturn(1.0);
        $childItem->method('getWeight')->willReturn(0.5);

        $order = $this->createOrderMock(['items' => [$parentItem, $childItem]]);
        $payload = $this->mapper->mapOrderToPayload($order);

        $this->assertCount(1, $payload['order_items']);
        $this->assertSame('WS12-M-Orange', $payload['order_items'][0]['sku']);
        $this->assertSame('Radiant Tee-M-Orange', $payload['order_items'][0]['description']);
        // Price was lifted from the configurable parent
        $this->assertSame(22.00, $payload['order_items'][0]['unit_price']);
    }

    public function testSimpleProductOrderUnaffected(): void
    {
        $item = $this->createMock(OrderItemInterface::class);
        $item->method('getParentItemId')->willReturn(null);
        $item->method('getProductType')->willReturn('simple');
        $item->method('getItemId')->willReturn(1);
        $item->method('getSku')->willReturn('SIMPLE-SKU');
        $item->method('getName')->willReturn('Simple Product');
        $item->method('getPriceInclTax')->willReturn(50.00);
        $item->method('getQtyOrdered')->willReturn(2.0);
        $item->method('getWeight')->willReturn(0.25);

        $order = $this->createOrderMock(['items' => [$item]]);
        $payload = $this->mapper->mapOrderToPayload($order);

        $this->assertCount(1, $payload['order_items']);
        $this->assertSame('SIMPLE-SKU', $payload['order_items'][0]['sku']);
        $this->assertSame(50.00, $payload['order_items'][0]['unit_price']);
    }

    public function testMapOrderToUpdatePayloadIncludesId(): void
    {
        $order = $this->createOrderMock(['bobgo_order_id' => '12345']);
        $payload = $this->mapper->mapOrderToUpdatePayload($order);

        $this->assertSame(12345, $payload['id']);
    }

    public function testPayloadIncludesBuyerSelectedServiceCode(): void
    {
        $order = $this->createOrderMock(['shippingMethod' => 'bobgo_334_1_1']);
        $payload = $this->mapper->mapOrderToPayload($order);

        $this->assertSame('bobgo_334_1_1', $payload['buyer_selected_service_code']);
    }

    public function testItemIncludesChannelImageUrl(): void
    {
        $productRepo = $this->createMock(ProductRepositoryInterface::class);
        $product = $this->createMock(ProductInterface::class);
        $product->method('getImage')->willReturn('/t/e/test-product.jpg');
        $productRepo->method('getById')->willReturn($product);

        $mapper = new OrderMapper(
            $productRepo, $this->storeManager, $this->scopeConfig,
            new DisplayOptionsMapper($this->scopeConfig)
        );

        $item = $this->createMock(OrderItemInterface::class);
        $item->method('getParentItemId')->willReturn(null);
        $item->method('getProductId')->willReturn(42);
        $item->method('getSku')->willReturn('TEST-SKU');
        $item->method('getName')->willReturn('Test Product');
        $item->method('getPriceInclTax')->willReturn(99.99);
        $item->method('getQtyOrdered')->willReturn(1.0);
        $item->method('getWeight')->willReturn(1.0);

        $order = $this->createOrderMock(['items' => [$item]]);
        $payload = $mapper->mapOrderToPayload($order);

        $this->assertSame(
            'https://example.com/media/catalog/product/t/e/test-product.jpg',
            $payload['order_items'][0]['channel_image_url']
        );
    }

    public function testItemImageUrlNullWhenProductNotFound(): void
    {
        $order = $this->createOrderMock();
        $payload = $this->mapper->mapOrderToPayload($order);

        $this->assertNull($payload['order_items'][0]['channel_image_url']);
    }

    public function testItemImageUrlNullWhenNoSelection(): void
    {
        $productRepo = $this->createMock(ProductRepositoryInterface::class);
        $product = $this->createMock(ProductInterface::class);
        $product->method('getImage')->willReturn('no_selection');
        $productRepo->method('getById')->willReturn($product);

        $mapper = new OrderMapper(
            $productRepo, $this->storeManager, $this->scopeConfig,
            new DisplayOptionsMapper($this->scopeConfig)
        );

        $order = $this->createOrderMock();
        $payload = $mapper->mapOrderToPayload($order);

        $this->assertNull($payload['order_items'][0]['channel_image_url']);
    }

    public function testMapItemIncludesBobGoItemIdWhenSet(): void
    {
        $item = $this->createMock(OrderItemInterface::class);
        $item->method('getParentItemId')->willReturn(null);
        $item->method('getItemId')->willReturn(1);
        $item->method('getSku')->willReturn('SKU-001');
        $item->method('getName')->willReturn('Product');
        $item->method('getPriceInclTax')->willReturn(100.00);
        $item->method('getQtyOrdered')->willReturn(1.0);
        $item->method('getWeight')->willReturn(1.0);
        $item->method('getData')
            ->with('bobgo_order_item_id')
            ->willReturn('456');

        $order = $this->createOrderMock(['items' => [$item]]);
        $payload = $this->mapper->mapOrderToPayload($order);

        $this->assertArrayHasKey('id', $payload['order_items'][0]);
        $this->assertSame(456, $payload['order_items'][0]['id']);
    }

    public function testMapItemOmitsIdWhenNotSet(): void
    {
        $item = $this->createMock(OrderItemInterface::class);
        $item->method('getParentItemId')->willReturn(null);
        $item->method('getItemId')->willReturn(1);
        $item->method('getSku')->willReturn('SKU-001');
        $item->method('getName')->willReturn('Product');
        $item->method('getPriceInclTax')->willReturn(100.00);
        $item->method('getQtyOrdered')->willReturn(1.0);
        $item->method('getWeight')->willReturn(1.0);
        $item->method('getData')
            ->with('bobgo_order_item_id')
            ->willReturn(null);

        $order = $this->createOrderMock(['items' => [$item]]);
        $payload = $this->mapper->mapOrderToPayload($order);

        $this->assertArrayNotHasKey('id', $payload['order_items'][0]);
    }

    public function testMapItemOmitsIdWhenEmptyString(): void
    {
        $item = $this->createMock(OrderItemInterface::class);
        $item->method('getParentItemId')->willReturn(null);
        $item->method('getItemId')->willReturn(1);
        $item->method('getSku')->willReturn('SKU-001');
        $item->method('getName')->willReturn('Product');
        $item->method('getPriceInclTax')->willReturn(100.00);
        $item->method('getQtyOrdered')->willReturn(1.0);
        $item->method('getWeight')->willReturn(1.0);
        $item->method('getData')
            ->with('bobgo_order_item_id')
            ->willReturn('');

        $order = $this->createOrderMock(['items' => [$item]]);
        $payload = $this->mapper->mapOrderToPayload($order);

        $this->assertArrayNotHasKey('id', $payload['order_items'][0]);
    }

    /**
     * Rounded to one decimal, matching what Bob Go persists. Sending more
     * precision than the server keeps means the value we sent and the value it
     * stored differ.
     */
    public function testWeightConvertsLbsToKgInPayload(): void
    {
        // Override scopeConfig with one that reports LBS.
        $lbsConfig = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $lbsConfig->method('getValue')->willReturn('lbs');
        $mapper = new OrderMapper(
            $this->productRepository, $this->storeManager, $lbsConfig,
            new DisplayOptionsMapper($this->scopeConfig)
        );

        $item = $this->createMock(OrderItemInterface::class);
        $item->method('getParentItemId')->willReturn(null);
        $item->method('getItemId')->willReturn(1);
        $item->method('getSku')->willReturn('SKU-LBS');
        $item->method('getName')->willReturn('Product');
        $item->method('getPriceInclTax')->willReturn(10.0);
        $item->method('getQtyOrdered')->willReturn(1.0);
        $item->method('getWeight')->willReturn(10.0); // 10 lbs

        $order = $this->createOrderMock(['items' => [$item]]);
        $payload = $mapper->mapOrderToPayload($order);

        $this->assertEqualsWithDelta(4.5, $payload['order_items'][0]['unit_weight_kg'], 0.0001);
    }

    public function testWeightPassesThroughForKgs(): void
    {
        $item = $this->createMock(OrderItemInterface::class);
        $item->method('getParentItemId')->willReturn(null);
        $item->method('getItemId')->willReturn(1);
        $item->method('getSku')->willReturn('SKU-KG');
        $item->method('getName')->willReturn('Product');
        $item->method('getPriceInclTax')->willReturn(10.0);
        $item->method('getQtyOrdered')->willReturn(1.0);
        $item->method('getWeight')->willReturn(2.5);

        $order = $this->createOrderMock(['items' => [$item]]);
        $payload = $this->mapper->mapOrderToPayload($order);

        $this->assertSame(2.5, $payload['order_items'][0]['unit_weight_kg']);
    }

    public function testSuburbCustomAttributeUsedAsLocalArea(): void
    {
        $attr = $this->createMock(\Magento\Framework\Api\AttributeInterface::class);
        $attr->method('getValue')->willReturn('Sandton');

        $address = $this->getMockBuilder(\Magento\Sales\Api\Data\OrderAddressInterface::class)
            ->addMethods(['getCustomAttribute', 'getData'])
            ->getMockForAbstractClass();
        $address->method('getStreet')->willReturn(['1 Test Rd']);
        $address->method('getCity')->willReturn('Johannesburg');
        $address->method('getPostcode')->willReturn('2196');
        $address->method('getRegion')->willReturn('Gauteng');
        $address->method('getCountryId')->willReturn('ZA');
        $address->method('getCompany')->willReturn('Acme');
        $address->method('getCustomAttribute')
            ->with('suburb')
            ->willReturn($attr);

        $order = $this->createOrderMock(['shippingAddress' => $address]);
        $payload = $this->mapper->mapOrderToPayload($order);

        $this->assertSame('Sandton', $payload['delivery_address']['local_area']);
        $this->assertSame('Johannesburg', $payload['delivery_address']['city']);
    }

    public function testLocalAreaFallsBackToCityWhenNoSuburb(): void
    {
        $address = $this->getMockBuilder(\Magento\Sales\Api\Data\OrderAddressInterface::class)
            ->addMethods(['getCustomAttribute', 'getData'])
            ->getMockForAbstractClass();
        $address->method('getStreet')->willReturn(['1 Test Rd']);
        $address->method('getCity')->willReturn('Cape Town');
        $address->method('getPostcode')->willReturn('8001');
        $address->method('getRegion')->willReturn('Western Cape');
        $address->method('getCountryId')->willReturn('ZA');
        $address->method('getCompany')->willReturn('Acme');
        $address->method('getCustomAttribute')->willReturn(null);

        $order = $this->createOrderMock(['shippingAddress' => $address]);
        $payload = $this->mapper->mapOrderToPayload($order);

        $this->assertSame('Cape Town', $payload['delivery_address']['local_area']);
    }

    /**
     * Create an order mock with configurable field overrides.
     *
     * @param array<string,mixed> $overrides
     * @return OrderInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private function createOrderMock(array $overrides = []): OrderInterface
    {
        $defaults = [
            'entityId' => 1,
            'incrementId' => '000000001',
            'grandTotal' => 100.00,
            'totalDue' => 0.0,
            'currencyCode' => 'ZAR',
            'status' => 'processing',
            'shippingMethod' => 'bobgo_standard',
            'shippingDescription' => 'Standard',
            'createdAt' => '2026-01-01 00:00:00',
            'updatedAt' => '2026-01-01 00:00:00',
            'shippingInclTax' => 0.0,
            'bobgo_order_id' => null,
        ];

        $config = array_merge($defaults, $overrides);

        // Create default item if not provided
        if (!isset($config['items'])) {
            $item = $this->createMock(OrderItemInterface::class);
            $item->method('getParentItemId')->willReturn(null);
            $item->method('getItemId')->willReturn(1);
            $item->method('getSku')->willReturn('SKU-001');
            $item->method('getName')->willReturn('Product');
            $item->method('getPriceInclTax')->willReturn(100.00);
            $item->method('getQtyOrdered')->willReturn(1.0);
            $item->method('getWeight')->willReturn(1.0);
            $config['items'] = [$item];
        }

        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn($config['entityId']);
        $order->method('getIncrementId')->willReturn($config['incrementId']);
        $order->method('getGrandTotal')->willReturn($config['grandTotal']);
        $order->method('getTotalDue')->willReturn($config['totalDue']);
        $order->method('getOrderCurrencyCode')->willReturn($config['currencyCode']);
        $order->method('getStatus')->willReturn($config['status']);
        $order->method('getShippingMethod')->willReturn($config['shippingMethod']);
        $order->method('getShippingDescription')->willReturn($config['shippingDescription']);
        $order->method('getCreatedAt')->willReturn($config['createdAt']);
        $order->method('getUpdatedAt')->willReturn($config['updatedAt']);
        $order->method('getShippingAddress')->willReturn($config['shippingAddress'] ?? null);
        $order->method('getBillingAddress')->willReturn(null);
        $order->method('getCustomerFirstname')->willReturn('Test');
        $order->method('getCustomerLastname')->willReturn('Customer');
        $order->method('getCustomerEmail')->willReturn('test@example.com');
        $order->method('getItems')->willReturn($config['items']);
        $order->method('getData')->willReturnMap([
            ['shipping_incl_tax', $config['shippingInclTax']],
            ['bobgo_order_id', $config['bobgo_order_id']],
        ]);

        return $order;
    }

    /**
     * End-to-end: the parent's attributes_info must reach the child's payload entry.
     *
     * A live order proved this was broken — a configurable tank in size L / yellow
     * went out with no display_options at all, because mapItems() sends the simple
     * child and Magento keeps attributes_info on the configurable parent.
     */
    public function testConfigurableVariantAttributesReachTheItemPayload(): void
    {
        $parent = $this->createMock(OrderItemInterface::class);
        $parent->method('getItemId')->willReturn(17);
        $parent->method('getProductType')->willReturn('configurable');
        $parent->method('getSku')->willReturn('WT09-L-Yellow');
        $parent->method('getPriceInclTax')->willReturn(34.0);
        $parent->method('getProductOptions')->willReturn([
            'info_buyRequest' => ['qty' => 1],
            'attributes_info' => [
                ['label' => 'Size', 'value' => 'L', 'option_value' => '169'],
                ['label' => 'Color', 'value' => 'Yellow', 'option_value' => '61'],
            ],
        ]);

        $child = $this->createMock(OrderItemInterface::class);
        $child->method('getItemId')->willReturn(18);
        $child->method('getParentItemId')->willReturn(17);
        $child->method('getProductType')->willReturn('simple');
        $child->method('getSku')->willReturn('WT09-L-Yellow');
        $child->method('getName')->willReturn('Breathe-Easy Tank-L-Yellow');
        $child->method('getPriceInclTax')->willReturn(0.0);
        $child->method('getQtyOrdered')->willReturn(1.0);
        $child->method('getWeight')->willReturn(0.5);
        $child->method('getProductOptions')->willReturn(['info_buyRequest' => ['qty' => 1]]);

        $payload = $this->mapper->mapOrderToPayload($this->orderWith(['getItems' => [$parent, $child]]));

        $this->assertCount(1, $payload['order_items'], 'the configurable parent is not sent');
        $item = $payload['order_items'][0];
        $this->assertSame(34.0, $item['unit_price'], "the parent's price is mirrored onto the child");
        $this->assertArrayHasKey('display_options', $item);
        $this->assertSame(['size', 'color'], array_column($item['display_options'], 'key'));
    }

    // -------------------------------------------------------- payload completeness

    /**
     * Omitted rather than sent as zeros/blanks, so the sync hash doesn't churn on
     * fields the store never populates.
     */
    public function testOptionalTotalsAndNoteAreOmittedWhenEmpty(): void
    {
        $payload = $this->mapper->mapOrderToPayload($this->orderWith([]));

        $this->assertArrayNotHasKey('note', $payload);
        $this->assertArrayNotHasKey('total_tax', $payload);
        $this->assertArrayNotHasKey('total_discount', $payload);
        $this->assertArrayNotHasKey('date_placed_on_channel', $payload);
    }

    public function testCarriesNoteTaxDiscountAndPlacedDate(): void
    {
        $payload = $this->mapper->mapOrderToPayload($this->orderWith([
            'getCustomerNote' => '  Leave at the back door  ',
            'getTaxAmount' => 19.5,
            // Magento records discounts as negative; Bob Go wants the magnitude.
            'getDiscountAmount' => -50.0,
            'getCreatedAt' => '2026-07-30 09:15:00',
        ]));

        $this->assertSame('Leave at the back door', $payload['note']);
        $this->assertSame(19.5, $payload['total_tax']);
        $this->assertSame(50.0, $payload['total_discount']);
        $this->assertSame('2026-07-30T09:15:00+00:00', $payload['date_placed_on_channel']);
    }

    /**
     * A fully refunded order is not "paid" — telling Bob Go it is invites a
     * shipment for something the customer got their money back for.
     */
    public function testRefundedBeatsPaid(): void
    {
        $payload = $this->mapper->mapOrderToPayload($this->orderWith([
            'getTotalDue' => 0.0,
            'getTotalRefunded' => 100.0,
        ]));

        $this->assertSame('refunded', $payload['payment_status']);
    }

    /**
     * Awaiting an offline payment or a gateway review is genuinely different from
     * a customer who simply hasn't paid.
     */
    public function testAwaitingPaymentIsPendingNotUnpaid(): void
    {
        $payload = $this->mapper->mapOrderToPayload($this->orderWith([
            'getTotalDue' => 100.0,
            'getState' => 'payment_review',
        ]));

        $this->assertSame('pending', $payload['payment_status']);
    }

    /**
     * Build an order whose only interesting values are the ones named.
     *
     * PHPUnit keeps the first stub for a method, so overriding after the fact
     * silently does nothing — every value a test cares about has to be set here.
     *
     * @param array<string,mixed> $values
     * @return OrderInterface
     */
    private function orderWith(array $values): OrderInterface
    {
        $order = $this->createMock(OrderInterface::class);
        $defaults = [
            'getEntityId' => 100,
            'getIncrementId' => '000000100',
            'getTotalDue' => 0.0,
            'getTotalRefunded' => 0.0,
            'getState' => 'processing',
            'getOrderCurrencyCode' => 'ZAR',
            'getShippingMethod' => 'bobgo_standard',
            'getShippingDescription' => 'Standard Delivery',
            'getItems' => [],
            'getShippingAddress' => null,
            'getBillingAddress' => null,
            'getCustomerNote' => null,
            'getTaxAmount' => 0.0,
            'getDiscountAmount' => 0.0,
            'getCreatedAt' => null,
        ];
        foreach (array_merge($defaults, $values) as $method => $value) {
            $order->method($method)->willReturn($value);
        }
        return $order;
    }
}
