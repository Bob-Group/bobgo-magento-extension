<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Service;

use BobGroup\BobGo\Service\OrderResolution;
use BobGroup\BobGo\Service\OrderResolver;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Resolution is the guard against the mis-link incident: a webhook for a Bob Go
 * order that isn't ours getting attached to a real customer order, overwriting
 * its tracking, and marking it synced so it's never pushed at all.
 *
 * Magento makes this sharper than it was on WooCommerce, because every store is
 * handed the same increment_id sequence — so "000000042" existing in this store
 * is no evidence at all that a payload mentioning it belongs to this store.
 */
class OrderResolverTest extends TestCase
{
    private $orderRepository;
    private $searchCriteriaBuilder;
    private $logger;
    /** @var OrderResolver */
    private $resolver;

    /** @var array<int,array{field:string,value:string}> */
    private $filtersApplied = [];

    /** @var array<string,array<int,object>> Keyed "field=value" */
    private $rowsByFilter = [];

    /** @var array<int,object> Keyed by entity id, for repository->get() */
    private $rowsById = [];

    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Record every filter passed, and serve rows only to a matching filter.
        // A lookup issued with NO filter returns everything — which is exactly
        // the silent-filter-drop failure the resolver's tripwire must catch.
        $this->searchCriteriaBuilder->method('addFilter')->willReturnCallback(
            function ($field, $value = null) {
                $this->filtersApplied[] = ['field' => (string) $field, 'value' => (string) $value];
                return $this->searchCriteriaBuilder;
            }
        );
        $this->searchCriteriaBuilder->method('setPageSize')->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')->willReturnCallback(
            function () {
                return $this->createMock(SearchCriteria::class);
            }
        );

        $this->orderRepository->method('getList')->willReturnCallback(
            function () {
                $last = end($this->filtersApplied);
                $this->filtersApplied = [];
                $key = $last === false ? '*' : $last['field'] . '=' . $last['value'];

                $result = $this->createMock(OrderSearchResultInterface::class);
                $result->method('getItems')->willReturn($this->rowsByFilter[$key] ?? []);
                return $result;
            }
        );

        $this->orderRepository->method('get')->willReturnCallback(
            function ($id) {
                if (!isset($this->rowsById[(int) $id])) {
                    throw new \Magento\Framework\Exception\NoSuchEntityException(__('nope'));
                }
                return $this->rowsById[(int) $id];
            }
        );

        $this->resolver = new OrderResolver(
            $this->orderRepository,
            $this->searchCriteriaBuilder,
            $this->logger
        );
    }

    /**
     * @param array<string,mixed> $data
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    private function order(int $entityId, string $incrementId, array $data = [])
    {
        $order = $this->createMock(\Magento\Sales\Model\Order::class);
        $order->method('getEntityId')->willReturn($entityId);
        $order->method('getIncrementId')->willReturn($incrementId);
        $order->method('getData')->willReturnCallback(static function ($key = null) use ($data) {
            return $key === null ? $data : ($data[$key] ?? null);
        });
        return $order;
    }

    /**
     * Make $order discoverable by a lookup filtering $field = $value.
     *
     * @param object $order
     */
    private function findableBy(string $field, string $value, $order): void
    {
        $this->rowsByFilter[$field . '=' . $value][] = $order;
    }

    // -------------------------------------------------------------- no reference

    public function testPayloadWithoutAnyReferenceYieldsNoReference(): void
    {
        $resolution = $this->resolver->resolve(
            ['status' => 'in-transit', 'checkpoints' => []],
            OrderResolver::TOPIC_TRACKING_UPDATED
        );

        $this->assertSame(OrderResolution::NO_REFERENCE, $resolution->getOutcome());
        $this->assertFalse($resolution->hasReference());
        $this->assertNull($resolution->getOrder());
    }

    // ------------------------------------------------------------- channel_ref_id

    public function testChannelRefIdMatchesWhenBobGoOrderIdCorroborates(): void
    {
        $order = $this->order(42, '000000042', ['bobgo_order_id' => '987']);
        $this->rowsById[42] = $order;

        $resolution = $this->resolver->resolve(
            ['channel_ref_id' => '42', 'order_id' => '987'],
            OrderResolver::TOPIC_FULFILLMENT_CREATED
        );

        $this->assertTrue($resolution->isMatched());
        $this->assertSame($order, $resolution->getOrder());
    }

    public function testChannelRefIdMatchesWhenOrderNumberCorroborates(): void
    {
        // Order pushed but link not yet stored: order number is the corroboration.
        $order = $this->order(42, '000000042');
        $this->rowsById[42] = $order;

        $resolution = $this->resolver->resolve(
            ['channel_ref_id' => '42', 'channel_order_number' => '000000042'],
            OrderResolver::TOPIC_FULFILLMENT_CREATED
        );

        $this->assertTrue($resolution->isMatched());
    }

    /**
     * The core anti-hijack rule. entity_ids are unique per store but not per Bob
     * Go account, and delivery is account-wide — so an entity_id that happens to
     * exist here is not proof of ownership on its own.
     */
    public function testChannelRefIdAloneIsNotEnoughToClaimOwnership(): void
    {
        $this->rowsById[42] = $this->order(42, '000000042');

        $resolution = $this->resolver->resolve(
            ['channel_ref_id' => '42'],
            OrderResolver::TOPIC_FULFILLMENT_CREATED
        );

        $this->assertFalse($resolution->isMatched());
        $this->assertTrue($resolution->hasReference());
        $this->assertStringContainsString('corroborates', $resolution->getReason());
    }

    public function testChannelRefIdRefusesToRelinkAnOrderBoundElsewhere(): void
    {
        $this->rowsById[42] = $this->order(42, '000000042', ['bobgo_order_id' => '111']);

        $resolution = $this->resolver->resolve(
            ['channel_ref_id' => '42', 'order_id' => '999', 'channel_order_number' => '000000042'],
            OrderResolver::TOPIC_FULFILLMENT_CREATED
        );

        $this->assertFalse($resolution->isMatched());
        $this->assertStringContainsString('already linked', $resolution->getReason());
    }

    /**
     * Terminal on purpose: a channel_ref_id we can't resolve must NOT fall
     * through to order-number matching. That fall-through is how a foreign
     * order's number gets glued to a local order.
     */
    public function testUnresolvableChannelRefIdDoesNotFallThroughToOrderNumber(): void
    {
        $decoy = $this->order(7, '000000042');
        $this->findableBy('increment_id', '000000042', $decoy);

        $resolution = $this->resolver->resolve(
            ['channel_ref_id' => '999999', 'channel_order_number' => '000000042'],
            OrderResolver::TOPIC_FULFILLMENT_CREATED
        );

        $this->assertFalse($resolution->isMatched());
        $this->assertStringContainsString('does not exist', $resolution->getReason());
    }

    public function testNonNumericChannelRefIdIsRejected(): void
    {
        $resolution = $this->resolver->resolve(
            ['channel_ref_id' => 'woo_1234'],
            OrderResolver::TOPIC_FULFILLMENT_CREATED
        );

        $this->assertFalse($resolution->isMatched());
        $this->assertStringContainsString('not a Magento entity id', $resolution->getReason());
    }

    // --------------------------------------------------------- bobgo order id / ref

    public function testResolvesByStoredBobGoOrderId(): void
    {
        $order = $this->order(42, '000000042', ['bobgo_order_id' => '987']);
        $this->findableBy('bobgo_order_id', '987', $order);

        $resolution = $this->resolver->resolve(
            ['order_id' => '987'],
            OrderResolver::TOPIC_FULFILLMENT_CREATED
        );

        $this->assertTrue($resolution->isMatched());
        $this->assertSame($order, $resolution->getOrder());
    }

    public function testResolvesByOrderRefAgainstStoredRef(): void
    {
        $order = $this->order(42, '000000042', ['bobgo_order_ref' => 'BG-REF-1']);
        $this->findableBy('bobgo_order_ref', 'BG-REF-1', $order);

        $resolution = $this->resolver->resolve(
            ['order_ref' => 'BG-REF-1'],
            OrderResolver::TOPIC_FULFILLMENT_CREATED
        );

        $this->assertTrue($resolution->isMatched());
    }

    /**
     * On order/updated the top-level `id` IS the Bob Go order id...
     */
    public function testOrderUpdatedTreatsTopLevelIdAsTheOrderId(): void
    {
        $order = $this->order(42, '000000042', ['bobgo_order_id' => '987']);
        $this->findableBy('bobgo_order_id', '987', $order);

        $resolution = $this->resolver->resolve(['id' => '987'], OrderResolver::TOPIC_ORDER_UPDATED);

        $this->assertTrue($resolution->isMatched());
    }

    /**
     * ...but on fulfillment/created it is the FULFILMENT id, and on
     * tracking/updated it is the tracking-reference string. Reading it as an
     * order id there corrupts the link.
     */
    public function testFulfillmentAndTrackingTopicsIgnoreTopLevelId(): void
    {
        $wrong = $this->order(42, '000000042', ['bobgo_order_id' => '987']);
        $this->findableBy('bobgo_order_id', '987', $wrong);

        $fulfilment = $this->resolver->resolve(
            ['id' => '987'],
            OrderResolver::TOPIC_FULFILLMENT_CREATED
        );
        $tracking = $this->resolver->resolve(
            ['id' => '987'],
            OrderResolver::TOPIC_TRACKING_UPDATED
        );

        $this->assertSame(OrderResolution::NO_REFERENCE, $fulfilment->getOutcome());
        $this->assertSame(OrderResolution::NO_REFERENCE, $tracking->getOutcome());
    }

    // ------------------------------------------------------------- order number

    public function testResolvesByOrderNumberAsLastResort(): void
    {
        $order = $this->order(42, '000000042');
        $this->findableBy('increment_id', '000000042', $order);

        $resolution = $this->resolver->resolve(
            ['channel_order_number' => '000000042'],
            OrderResolver::TOPIC_TRACKING_UPDATED
        );

        $this->assertTrue($resolution->isMatched());
    }

    public function testOrderNumberToleratesLeadingHash(): void
    {
        $order = $this->order(42, '000000042');
        $this->findableBy('increment_id', '000000042', $order);

        $resolution = $this->resolver->resolve(
            ['channel_order_number' => '#000000042'],
            OrderResolver::TOPIC_TRACKING_UPDATED
        );

        $this->assertTrue($resolution->isMatched());
    }

    /**
     * An order number must never be able to steal an order that is already
     * linked to a different Bob Go order.
     */
    public function testOrderNumberRefusesAnOrderLinkedElsewhere(): void
    {
        $order = $this->order(42, '000000042', ['bobgo_order_id' => '111']);
        $this->findableBy('increment_id', '000000042', $order);

        $resolution = $this->resolver->resolve(
            ['channel_order_number' => '000000042', 'order_id' => '999'],
            OrderResolver::TOPIC_FULFILLMENT_CREATED
        );

        $this->assertFalse($resolution->isMatched());
        $this->assertStringContainsString('already linked', $resolution->getReason());
    }

    // ---------------------------------------------------------------- invariants

    /**
     * Two orders matching one filter means we cannot tell which is meant.
     * Picking the first is how a stranger's shipment lands on a real order.
     */
    public function testAmbiguousMatchIsRefused(): void
    {
        $this->findableBy('bobgo_order_id', '987', $this->order(42, '000000042', ['bobgo_order_id' => '987']));
        $this->findableBy('bobgo_order_id', '987', $this->order(43, '000000043', ['bobgo_order_id' => '987']));

        $resolution = $this->resolver->resolve(
            ['order_id' => '987'],
            OrderResolver::TOPIC_FULFILLMENT_CREATED
        );

        $this->assertFalse($resolution->isMatched());
    }

    /**
     * The tripwire. If the repository ever ignores the filter we passed and
     * returns an arbitrary page, the first row would look like a perfectly good
     * match. Verifying the row actually carries the value we filtered on turns
     * that into a logged refusal instead of a silent mis-link.
     */
    public function testRowThatDoesNotCarryTheFilteredValueIsRefused(): void
    {
        // A row served for the right key, but whose data doesn't actually match.
        $this->findableBy('bobgo_order_id', '987', $this->order(42, '000000042', ['bobgo_order_id' => '123']));

        $this->logger->expects($this->atLeastOnce())->method('error');

        $resolution = $this->resolver->resolve(
            ['order_id' => '987'],
            OrderResolver::TOPIC_FULFILLMENT_CREATED
        );

        $this->assertFalse($resolution->isMatched());
    }

    public function testEmptyStringReferencesAreTreatedAsAbsent(): void
    {
        $resolution = $this->resolver->resolve(
            ['channel_ref_id' => '', 'channel_order_number' => '   ', 'order_ref' => ''],
            OrderResolver::TOPIC_FULFILLMENT_CREATED
        );

        $this->assertSame(OrderResolution::NO_REFERENCE, $resolution->getOutcome());
    }
}
