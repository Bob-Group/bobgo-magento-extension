<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Service;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;

/**
 * Decides which orders Bob Go should hear about, and what status to forward.
 *
 * Kept separate from the push itself because the answer has to be checked
 * twice: once when the order is queued, and again inside the job that drains
 * the queue. A deferred job can run after the order has moved on — an order
 * queued while `processing` may be `canceled` by the time we get to it.
 */
class OrderSyncPolicy
{
    /**
     * States in which it is meaningful to CREATE an order on Bob Go.
     *
     * Excluded on purpose:
     *  - pending_payment / payment_review: the sale isn't real yet. Pushing on
     *    every abandoned card attempt fills the merchant's Bob Go account with
     *    orders that will never ship.
     *  - canceled / closed with no Bob Go link: there is nothing to fulfil, so
     *    creating it just to cancel it is noise.
     *
     * Orders that already have a link are always updatable regardless of state —
     * Bob Go needs to hear about a cancellation or a change after the fact.
     */
    private const CREATABLE_STATES = [
        Order::STATE_NEW,
        Order::STATE_PROCESSING,
        Order::STATE_HOLDED,
        Order::STATE_COMPLETE,
    ];

    /**
     * Order statuses Bob Go acts on, mapped from Magento state. Everything else
     * is inferred by Bob Go from the fulfilments it already owns.
     */
    private const FORWARDABLE = [
        Order::STATE_CANCELED => 'cancelled',
        Order::STATE_COMPLETE => 'completed',
    ];

    /**
     * Should this order be sent to Bob Go at all?
     */
    public function shouldPush(OrderInterface $order): bool
    {
        /** @var Order $order */
        if ($order->getIsVirtual()) {
            // No shipping address, so nothing to ship and nothing Bob Go can do
            // with it. OrderMapper would emit delivery_address: null and the API
            // would reject it on every save, forever.
            return false;
        }

        if ($this->hasLink($order)) {
            return true;
        }

        return in_array((string) $order->getState(), self::CREATABLE_STATES, true);
    }

    /**
     * The status to forward for this order, or null when there's nothing to say.
     *
     * Returns null when the status has already been forwarded — Bob Go treats a
     * repeat as a 200 no-op, but there's no reason to spend the call.
     */
    public function statusToForward(OrderInterface $order): ?string
    {
        if (!$this->hasLink($order)) {
            return null;
        }

        /** @var Order $order */
        $state = (string) $order->getState();
        if (!isset(self::FORWARDABLE[$state])) {
            return null;
        }

        $status = self::FORWARDABLE[$state];
        $alreadySent = (string) ($order->getData('bobgo_status_synced') ?? '');

        return $status === $alreadySent ? null : $status;
    }

    public function hasLink(OrderInterface $order): bool
    {
        $link = $order->getData('bobgo_order_id');
        return $link !== null && $link !== '';
    }
}
