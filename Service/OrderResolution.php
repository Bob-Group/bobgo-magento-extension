<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Service;

use Magento\Sales\Api\Data\OrderInterface;

/**
 * Outcome of resolving an inbound Bob Go webhook payload to a local order.
 *
 * Three outcomes rather than a nullable order, because the HTTP response
 * policy depends on which one it is (see Controller\Webhook\Receive):
 *
 *  - MATCHED       This order is ours. Process the event.
 *  - NO_REFERENCE  The payload carries no order reference at all. Bob Go
 *                  webhook subscriptions are account-wide, so our endpoint
 *                  receives every event on the merchant's account — manual
 *                  shipments, CSV imports, other channels. This is routine
 *                  foreign traffic: acknowledge with 200 and don't log it,
 *                  or the volume drowns the sync log.
 *  - UNRESOLVED    The payload carried a reference we could not safely tie to
 *                  a local order. Acknowledge with 200 (a non-2xx here counts
 *                  against Bob Go's 3-day delivery-failure window and can get
 *                  the whole subscription disabled) but keep the log row —
 *                  this is the shape an attempted mis-link takes.
 */
final class OrderResolution
{
    public const MATCHED = 'matched';
    public const NO_REFERENCE = 'no_reference';
    public const UNRESOLVED = 'unresolved';

    private string $outcome;

    /** @var OrderInterface|null */
    private $order;

    private string $reason;

    private function __construct(string $outcome, ?OrderInterface $order, string $reason)
    {
        $this->outcome = $outcome;
        $this->order = $order;
        $this->reason = $reason;
    }

    public static function matched(OrderInterface $order): self
    {
        return new self(self::MATCHED, $order, '');
    }

    public static function noReference(): self
    {
        return new self(self::NO_REFERENCE, null, 'payload carries no order reference');
    }

    public static function unresolved(string $reason): self
    {
        return new self(self::UNRESOLVED, null, $reason);
    }

    public function isMatched(): bool
    {
        return $this->outcome === self::MATCHED;
    }

    /**
     * False only for NO_REFERENCE — i.e. the payload gave us nothing to look up.
     */
    public function hasReference(): bool
    {
        return $this->outcome !== self::NO_REFERENCE;
    }

    /**
     * Non-null exactly when isMatched() is true.
     */
    public function getOrder(): ?OrderInterface
    {
        return $this->order;
    }

    public function getOutcome(): string
    {
        return $this->outcome;
    }

    /**
     * Operator-facing explanation, recorded on the sync-log row for
     * unresolved payloads.
     */
    public function getReason(): string
    {
        return $this->reason;
    }
}
