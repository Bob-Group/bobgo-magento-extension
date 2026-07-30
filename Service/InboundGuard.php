<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Service;

/**
 * Marks orders currently being changed *because* Bob Go told us to.
 *
 * Inbound handlers save the order — a webhook timestamp, a status comment, a
 * shipment — and every order save queues an outbound push. Sending Bob Go's own
 * change back to Bob Go is pointless at best.
 *
 * The sync-hash dirty check already stops the wasted API call, so this is not
 * load-bearing for correctness. It is here because relying on the hash means the
 * loop protection is accidental: it works only as long as nothing inbound ever
 * touches a field that appears in the outbound payload. Inbound field mapping is
 * on the roadmap, and the day it lands the accident stops holding.
 *
 * Request-scoped, which is all that is needed: the guard only has to span a single
 * inbound handler's own saves.
 *
 * Re-entrant, because the scopes legitimately nest: the webhook controller marks
 * the whole delivery inbound, and FulfilmentSyncService marks its own refresh —
 * so a webhook-driven refresh enters twice. Counting depth rather than storing a
 * flag is what stops the inner scope's exit from unguarding the outer one, which
 * would silently re-open the very loop this class exists to close.
 */
class InboundGuard
{
    /**
     * Order id => nesting depth. Absent means not guarded.
     *
     * @var array<int,int>
     */
    private array $depth = [];

    public function enter(int $orderId): void
    {
        if ($orderId > 0) {
            $this->depth[$orderId] = ($this->depth[$orderId] ?? 0) + 1;
        }
    }

    public function leave(int $orderId): void
    {
        if (!isset($this->depth[$orderId])) {
            return;
        }

        if (--$this->depth[$orderId] <= 0) {
            unset($this->depth[$orderId]);
        }
    }

    public function isActive(int $orderId): bool
    {
        return isset($this->depth[$orderId]);
    }

    /**
     * Run $callback with the order marked as inbound-driven.
     *
     * @param callable $callback
     * @return mixed
     */
    public function around(int $orderId, callable $callback)
    {
        $this->enter($orderId);
        try {
            return $callback();
        } finally {
            $this->leave($orderId);
        }
    }
}
