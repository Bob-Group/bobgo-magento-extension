<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Service;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves an inbound Bob Go webhook payload to the local order it belongs to.
 *
 * WHY THIS IS NOT A ONE-LINE LOOKUP
 *
 * Bob Go webhook subscriptions are account-wide, not channel-scoped: this
 * endpoint receives every shipment event on the merchant's Bob Go account,
 * including orders belonging to other channels, CSV imports and standalone
 * manual shipments. Magento makes that dangerous, because every store is
 * handed the same increment_id sequence by default (000000001, 1000000001…) —
 * so a foreign order very plausibly carries an "order number" that also exists
 * in this store.
 *
 * A lookup that falls back to "some order that looks close enough" attaches a
 * stranger's shipment to a real customer order, overwrites its tracking, and
 * marks it synced so it is never sent to Bob Go at all. That happened in
 * production on the WooCommerce integration. Hence:
 *
 *   1. Prefer channel_ref_id — the Magento entity_id we wrote ourselves on
 *      push. It is authoritative AND terminal: if it is present we either
 *      match on it or give up. Never fall through to number guessing.
 *   2. Then Bob Go's own order id, against the link we stored.
 *   3. Then order_ref, against the stored ref and id.
 *   4. Only then the human order number, and only ever against increment_id.
 *      An order number must never be matched against a stored Bob Go id —
 *      different namespaces, and matching across them is a hijack.
 *
 * Plus three invariants that apply at every rung:
 *   - Require exactly ONE match. More than one → refuse and log.
 *   - Never re-point an order that is already linked to a different Bob Go order.
 *   - Verify the row we got back actually carries the value we filtered on
 *     (see findExactlyOneBy).
 */
class OrderResolver
{
    public const TOPIC_FULFILLMENT_CREATED = 'fulfillment/created';
    public const TOPIC_TRACKING_UPDATED = 'tracking/updated';
    public const TOPIC_ORDER_UPDATED = 'order/updated';

    /**
     * Payload keys that carry the human-facing order number.
     */
    private const ORDER_NUMBER_KEYS = ['channel_order_number', 'order_number', 'custom_order_name'];

    private OrderRepositoryInterface $orderRepository;
    private SearchCriteriaBuilder $searchCriteriaBuilder;
    private LoggerInterface $logger;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        LoggerInterface $logger
    ) {
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->logger = $logger;
    }

    /**
     * @param array<string,mixed> $data Decoded webhook body
     * @param string $topic Resolved webhook topic — decides which keys carry an order id
     */
    public function resolve(array $data, string $topic): OrderResolution
    {
        $channelRefId = $this->reference($data, ['channel_ref_id']);
        $bobgoOrderId = $this->extractBobGoOrderId($data, $topic);
        $orderRef     = $this->reference($data, ['order_ref']);
        $orderNumber  = $this->reference($data, self::ORDER_NUMBER_KEYS);

        if ($channelRefId === null && $bobgoOrderId === null && $orderRef === null && $orderNumber === null) {
            return OrderResolution::noReference();
        }

        // 1. channel_ref_id — authoritative and terminal.
        if ($channelRefId !== null) {
            return $this->resolveByChannelRefId($channelRefId, $bobgoOrderId, $orderNumber);
        }

        // 2. Bob Go's order id against the link we stored on push.
        if ($bobgoOrderId !== null) {
            $order = $this->findExactlyOneBy('bobgo_order_id', $bobgoOrderId);
            if ($order !== null) {
                return OrderResolution::matched($order);
            }
        }

        // 3. order_ref — the immutable string reference, then the numeric id
        //    (older payload shapes put the numeric id here).
        if ($orderRef !== null) {
            foreach (['bobgo_order_ref', 'bobgo_order_id'] as $field) {
                $order = $this->findExactlyOneBy($field, $orderRef);
                if ($order !== null) {
                    return OrderResolution::matched($order);
                }
            }
        }

        // 4. Human order number → increment_id only.
        if ($orderNumber !== null) {
            return $this->resolveByOrderNumber($orderNumber, $bobgoOrderId);
        }

        return OrderResolution::unresolved('no local order matched the references in the payload');
    }

    /**
     * Bob Go's numeric order id, if this topic's payload carries one.
     *
     * Topic-specific on purpose — the top-level `id` means something different
     * in each payload, and reading it blindly corrupts the link:
     *   - fulfillment/created: `id` is the FULFILMENT id; the order id is `order_id`.
     *   - tracking/updated:    `id` is the tracking-reference STRING, and the
     *                          payload carries no Bob Go order id at all.
     *   - order/updated:       full order object, so `id` IS the order id.
     *
     * @param array<string,mixed> $data
     */
    private function extractBobGoOrderId(array $data, string $topic): ?string
    {
        if ($topic === self::TOPIC_ORDER_UPDATED) {
            return $this->reference($data, ['order_id', 'id']);
        }
        if ($topic === self::TOPIC_FULFILLMENT_CREATED) {
            return $this->reference($data, ['order_id']);
        }
        return null;
    }

    /**
     * The Magento entity_id we sent as channel_ref_id when the order was pushed.
     *
     * entity_ids are unique per store but NOT per Bob Go account, and delivery
     * is account-wide, so the id on its own is not proof the event is ours —
     * one corroborating field is required before we accept the match.
     */
    private function resolveByChannelRefId(
        string $channelRefId,
        ?string $bobgoOrderId,
        ?string $orderNumber
    ): OrderResolution {
        if (!ctype_digit($channelRefId)) {
            return OrderResolution::unresolved(
                sprintf('channel_ref_id "%s" is not a Magento entity id', $channelRefId)
            );
        }

        try {
            $order = $this->orderRepository->get((int) $channelRefId);
        } catch (\Throwable $e) {
            // Deliberately terminal: no fall-through to order-number matching.
            return OrderResolution::unresolved(
                sprintf('channel_ref_id %s does not exist in this store', $channelRefId)
            );
        }

        $storedLink = $this->stored($order, 'bobgo_order_id');

        if ($storedLink !== null && $bobgoOrderId !== null && $storedLink !== $bobgoOrderId) {
            $this->logger->warning('Bob Go webhook: refusing to relink an order to a different Bob Go order', [
                'order_id' => $order->getEntityId(),
                'stored_bobgo_order_id' => $storedLink,
                'payload_bobgo_order_id' => $bobgoOrderId,
            ]);
            return OrderResolution::unresolved(sprintf(
                'order %s is already linked to Bob Go order %s, payload claims %s',
                $channelRefId,
                $storedLink,
                $bobgoOrderId
            ));
        }

        if ($storedLink !== null && $bobgoOrderId !== null) {
            return OrderResolution::matched($order);
        }

        if ($orderNumber !== null && $this->numbersMatch($order->getIncrementId(), $orderNumber)) {
            return OrderResolution::matched($order);
        }

        return OrderResolution::unresolved(sprintf(
            'channel_ref_id %s resolved locally but nothing in the payload corroborates ownership',
            $channelRefId
        ));
    }

    /**
     * Weakest rung: the human order number against increment_id.
     *
     * Only reachable when the payload gave us nothing better, which in practice
     * means the order was never successfully pushed (so it has no link) or the
     * payload predates channel_ref_id echo. Both are legitimate, but so is the
     * mis-link case, so an unlinked match is logged loudly.
     */
    private function resolveByOrderNumber(string $orderNumber, ?string $bobgoOrderId): OrderResolution
    {
        // Some channels store the number with a leading '#'; Magento never does.
        $candidates = array_values(array_unique([$orderNumber, ltrim($orderNumber, '#')]));

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }
            $order = $this->findExactlyOneBy('increment_id', $candidate);
            if ($order === null) {
                continue;
            }

            $storedLink = $this->stored($order, 'bobgo_order_id');
            if ($storedLink !== null && $bobgoOrderId !== null && $storedLink !== $bobgoOrderId) {
                $this->logger->warning('Bob Go webhook: order number matched an order linked elsewhere', [
                    'order_id' => $order->getEntityId(),
                    'increment_id' => $candidate,
                    'stored_bobgo_order_id' => $storedLink,
                    'payload_bobgo_order_id' => $bobgoOrderId,
                ]);
                return OrderResolution::unresolved(sprintf(
                    'order number %s matched an order already linked to Bob Go order %s',
                    $candidate,
                    $storedLink
                ));
            }

            if ($storedLink === null) {
                $this->logger->warning('Bob Go webhook: order matched on order number alone', [
                    'order_id' => $order->getEntityId(),
                    'increment_id' => $candidate,
                    'note' => 'no Bob Go link stored; increment_id sequences collide across stores',
                ]);
            }

            return OrderResolution::matched($order);
        }

        return OrderResolution::unresolved(
            sprintf('no local order has increment_id %s', $orderNumber)
        );
    }

    /**
     * Load the single order whose $field equals $value, or null.
     *
     * Two guards, both load-bearing:
     *
     * 1. More than one match → refuse. Picking the first row is how a stranger's
     *    shipment ends up on a customer's order.
     * 2. Verify the returned row actually carries $value. Magento can silently
     *    ignore a filter on an attribute it doesn't recognise, in which case
     *    getList() happily returns an unfiltered page — and the first row of
     *    that page looks exactly like a legitimate match. This tripwire turns
     *    that class of bug into a logged refusal instead of a silent mis-link.
     */
    private function findExactlyOneBy(string $field, string $value): ?OrderInterface
    {
        $criteria = $this->searchCriteriaBuilder
            ->addFilter($field, $value)
            ->setPageSize(2)
            ->create();

        try {
            $items = $this->orderRepository->getList($criteria)->getItems();
        } catch (\Throwable $e) {
            $this->logger->error('Bob Go webhook: order lookup failed', [
                'field' => $field,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        if (count($items) > 1) {
            $this->logger->warning('Bob Go webhook: refusing to guess between multiple matching orders', [
                'field' => $field,
                'matches' => count($items),
            ]);
            return null;
        }

        $order = reset($items);
        if (!$order instanceof OrderInterface) {
            return null;
        }

        $actual = $field === 'increment_id'
            ? $this->normalise($order->getIncrementId())
            : $this->stored($order, $field);

        if ($actual !== $value) {
            $this->logger->error(
                'Bob Go webhook: order lookup returned a non-matching row — filter may have been ignored',
                [
                    'field' => $field,
                    'expected' => $value,
                    'actual' => $actual,
                    'order_id' => $order->getEntityId(),
                ]
            );
            return null;
        }

        return $order;
    }

    /**
     * First non-empty value among $keys, as a trimmed string.
     *
     * @param array<string,mixed> $data
     * @param string[] $keys
     */
    private function reference(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $value = $this->normalise($data[$key]);
            if ($value !== null) {
                return $value;
            }
        }
        return null;
    }

    private function stored(OrderInterface $order, string $field): ?string
    {
        return $this->normalise($order->getData($field));
    }

    /**
     * @param mixed $value
     */
    private function normalise($value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    /**
     * Compare two order numbers, tolerating a leading '#' on either side.
     *
     * @param mixed $local
     */
    private function numbersMatch($local, string $payloadNumber): bool
    {
        $local = $this->normalise($local);
        if ($local === null) {
            return false;
        }
        return ltrim($local, '#') === ltrim($payloadNumber, '#');
    }
}
