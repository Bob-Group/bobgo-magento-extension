<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Service;

use BobGroup\BobGo\Service\OrderMapper;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
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

    protected function setUp(): void
    {
        $this->productRepository = $this->createMock(ProductRepositoryInterface::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getBaseUrl')
            ->with(UrlInterface::URL_TYPE_MEDIA)
            ->willReturn('https://example.com/media/');
        $this->storeManager->method('getStore')->willReturn($store);

        // Default: product not found (tests that don't care about images)
        $this->productRepository->method('getById')
            ->willThrowException(new NoSuchEntityException());

        $this->mapper = new OrderMapper($this->productRepository, $this->storeManager);
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

    public function testChildItemsFiltered(): void
    {
        $parentItem = $this->createMock(OrderItemInterface::class);
        $parentItem->method('getParentItemId')->willReturn(null);
        $parentItem->method('getItemId')->willReturn(1);
        $parentItem->method('getSku')->willReturn('PARENT-SKU');
        $parentItem->method('getName')->willReturn('Parent Product');
        $parentItem->method('getPriceInclTax')->willReturn(100.00);
        $parentItem->method('getQtyOrdered')->willReturn(1.0);
        $parentItem->method('getWeight')->willReturn(1.0);

        $childItem = $this->createMock(OrderItemInterface::class);
        $childItem->method('getParentItemId')->willReturn(1);

        $order = $this->createOrderMock(['items' => [$parentItem, $childItem]]);
        $payload = $this->mapper->mapOrderToPayload($order);

        $this->assertCount(1, $payload['order_items']);
        $this->assertSame('PARENT-SKU', $payload['order_items'][0]['sku']);
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

        $mapper = new OrderMapper($productRepo, $this->storeManager);

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

        $mapper = new OrderMapper($productRepo, $this->storeManager);

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
        $order->method('getShippingAddress')->willReturn(null);
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
}
