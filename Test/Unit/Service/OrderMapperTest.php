<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Service;

use BobGroup\BobGo\Service\OrderMapper;
use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use PHPUnit\Framework\TestCase;

class OrderMapperTest extends TestCase
{
    /**
     * @var OrderMapper
     */
    private $mapper;

    protected function setUp(): void
    {
        $this->mapper = new OrderMapper();
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
        $order->method('getItems')->willReturn([$item]);
        $order->method('getData')->willReturnMap([
            ['tax_amount', 59.99],
            ['shipping_incl_tax', 75.00],
            ['bobgo_order_id', null],
        ]);

        $payload = $this->mapper->mapOrderToPayload($order);

        $this->assertSame('100', $payload['ChannelRefID']);
        $this->assertSame('000000100', $payload['ChannelOrderNumber']);
        $this->assertSame(399.98, $payload['TotalPrice']);
        $this->assertSame(59.99, $payload['TotalTax']);
        $this->assertSame(50.00, $payload['TotalDiscount']);
        $this->assertSame('ZAR', $payload['Currency']);
        $this->assertSame('Active', $payload['Status']);
        $this->assertSame('Paid', $payload['PaymentStatus']);
        $this->assertSame(['bobgo_standard'], $payload['BuyerSelectedShippingMethodCodes']);
        $this->assertSame(75.00, $payload['BuyerSelectedShippingCost']);
        $this->assertSame('Standard Delivery', $payload['BuyerSelectedShippingMethod']);
        $this->assertSame('2026-01-15 10:00:00', $payload['DatePlacedOnChannel']);
        $this->assertSame('2026-01-15 10:05:00', $payload['LastModifiedOnChannel']);

        $this->assertSame('123 Test St, Apt 4', $payload['DeliveryAddress']['StreetAddress']);
        $this->assertSame('Cape Town', $payload['DeliveryAddress']['City']);
        $this->assertSame('8001', $payload['DeliveryAddress']['Code']);
        $this->assertSame('Western Cape', $payload['DeliveryAddress']['Zone']);
        $this->assertSame('ZA', $payload['DeliveryAddress']['Country']);
        $this->assertSame('Test Co', $payload['DeliveryAddress']['Company']);

        $this->assertCount(1, $payload['Items']);
        $this->assertSame('42', $payload['Items'][0]['ChannelRefID']);
        $this->assertSame('TEST-SKU', $payload['Items'][0]['SKU']);
        $this->assertSame('Test Product', $payload['Items'][0]['Description']);
        $this->assertSame(199.99, $payload['Items'][0]['UnitPrice']);
        $this->assertSame(2, $payload['Items'][0]['Qty']);
        $this->assertSame(1.5, $payload['Items'][0]['UnitWeightKg']);
    }

    public function testStatusMappingCanceled(): void
    {
        $order = $this->createOrderMock(['status' => 'canceled']);
        $payload = $this->mapper->mapOrderToPayload($order);

        $this->assertSame('Cancelled', $payload['Status']);
    }

    public function testStatusMappingComplete(): void
    {
        $order = $this->createOrderMock(['status' => 'complete']);
        $payload = $this->mapper->mapOrderToPayload($order);

        $this->assertSame('Completed', $payload['Status']);
    }

    public function testStatusMappingDefault(): void
    {
        $order = $this->createOrderMock(['status' => 'some_unknown_status']);
        $payload = $this->mapper->mapOrderToPayload($order);

        $this->assertSame('Active', $payload['Status']);
    }

    public function testPaymentStatusPaid(): void
    {
        $order = $this->createOrderMock(['totalDue' => 0.0, 'grandTotal' => 100.00]);
        $payload = $this->mapper->mapOrderToPayload($order);

        $this->assertSame('Paid', $payload['PaymentStatus']);
    }

    public function testPaymentStatusUnpaid(): void
    {
        $order = $this->createOrderMock(['totalDue' => 100.00, 'grandTotal' => 100.00]);
        $payload = $this->mapper->mapOrderToPayload($order);

        $this->assertSame('Unpaid', $payload['PaymentStatus']);
    }

    public function testPaymentStatusPartiallyPaid(): void
    {
        $order = $this->createOrderMock(['totalDue' => 50.00, 'grandTotal' => 100.00]);
        $payload = $this->mapper->mapOrderToPayload($order);

        $this->assertSame('Partially Paid', $payload['PaymentStatus']);
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

        $this->assertCount(1, $payload['Items']);
        $this->assertSame('PARENT-SKU', $payload['Items'][0]['SKU']);
    }

    public function testDiscountAbsoluteValue(): void
    {
        $order = $this->createOrderMock(['discountAmount' => -25.50]);
        $payload = $this->mapper->mapOrderToPayload($order);

        $this->assertSame(25.50, $payload['TotalDiscount']);
    }

    public function testMapOrderToUpdatePayloadIncludesId(): void
    {
        $order = $this->createOrderMock(['bobgo_order_id' => 'bg-order-abc-123']);
        $payload = $this->mapper->mapOrderToUpdatePayload($order);

        $this->assertSame('bg-order-abc-123', $payload['id']);
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
            'discountAmount' => 0.0,
            'currencyCode' => 'ZAR',
            'status' => 'processing',
            'shippingMethod' => 'bobgo_standard',
            'shippingDescription' => 'Standard',
            'createdAt' => '2026-01-01 00:00:00',
            'updatedAt' => '2026-01-01 00:00:00',
            'taxAmount' => 0.0,
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
        $order->method('getDiscountAmount')->willReturn($config['discountAmount']);
        $order->method('getOrderCurrencyCode')->willReturn($config['currencyCode']);
        $order->method('getStatus')->willReturn($config['status']);
        $order->method('getShippingMethod')->willReturn($config['shippingMethod']);
        $order->method('getShippingDescription')->willReturn($config['shippingDescription']);
        $order->method('getCreatedAt')->willReturn($config['createdAt']);
        $order->method('getUpdatedAt')->willReturn($config['updatedAt']);
        $order->method('getShippingAddress')->willReturn(null);
        $order->method('getItems')->willReturn($config['items']);
        $order->method('getData')->willReturnMap([
            ['tax_amount', $config['taxAmount']],
            ['shipping_incl_tax', $config['shippingInclTax']],
            ['bobgo_order_id', $config['bobgo_order_id']],
        ]);

        return $order;
    }
}
