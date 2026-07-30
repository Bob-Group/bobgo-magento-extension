<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Service;

use BobGroup\BobGo\Service\InboundGuard;
use PHPUnit\Framework\TestCase;

/**
 * Inbound handlers save the order, and every order save queues an outbound push.
 * The sync-hash check already stops the wasted API call, so this guard is not
 * load-bearing for correctness — it is here so the loop protection is deliberate
 * rather than a side effect of nothing inbound yet touching a field that appears
 * in the outbound payload.
 */
class InboundGuardTest extends TestCase
{
    /** @var InboundGuard */
    private $guard;

    protected function setUp(): void
    {
        $this->guard = new InboundGuard();
    }

    public function testIsInactiveByDefault(): void
    {
        $this->assertFalse($this->guard->isActive(42));
    }

    public function testMarksAndClearsAnOrder(): void
    {
        $this->guard->enter(42);
        $this->assertTrue($this->guard->isActive(42));

        $this->guard->leave(42);
        $this->assertFalse($this->guard->isActive(42));
    }

    public function testTracksOrdersIndependently(): void
    {
        $this->guard->enter(42);

        $this->assertTrue($this->guard->isActive(42));
        $this->assertFalse($this->guard->isActive(43));
    }

    public function testAroundClearsTheMarkOnTheWayOut(): void
    {
        $seenInside = false;

        $result = $this->guard->around(42, function () use (&$seenInside) {
            $seenInside = $this->guard->isActive(42);
            return 'value';
        });

        $this->assertTrue($seenInside);
        $this->assertFalse($this->guard->isActive(42));
        $this->assertSame('value', $result);
    }

    /**
     * A stuck mark would suppress every future push for that order in this
     * request, so it has to clear even when the handler blows up.
     */
    public function testAroundClearsTheMarkWhenTheCallbackThrows(): void
    {
        try {
            $this->guard->around(42, static function () {
                throw new \RuntimeException('boom');
            });
            $this->fail('Expected the exception to propagate');
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertFalse($this->guard->isActive(42));
    }

    public function testIgnoresAnInvalidOrderId(): void
    {
        $this->guard->enter(0);

        $this->assertFalse($this->guard->isActive(0));
    }

    // ------------------------------------------------------------------ nesting

    /**
     * The scopes genuinely nest: the webhook controller marks a whole delivery
     * inbound, and FulfilmentSyncService marks its own refresh, so a
     * webhook-driven refresh enters twice. If the inner exit cleared the mark,
     * every save the outer scope made afterwards — the webhook timestamp, the
     * status-history comment — would queue an outbound push of Bob Go's own
     * change, which is precisely the loop the guard exists to close.
     */
    public function testInnerScopeExitDoesNotUnguardTheOuterOne(): void
    {
        $this->guard->enter(42);
        $this->guard->enter(42);

        $this->guard->leave(42);
        $this->assertTrue($this->guard->isActive(42), 'the outer scope is still open');

        $this->guard->leave(42);
        $this->assertFalse($this->guard->isActive(42));
    }

    public function testNestedAroundCallsUnwindInOrder(): void
    {
        $observed = [];

        $this->guard->around(42, function () use (&$observed) {
            $this->guard->around(42, function () use (&$observed) {
                $observed['inner'] = $this->guard->isActive(42);
            });
            $observed['after_inner'] = $this->guard->isActive(42);
        });
        $observed['after_outer'] = $this->guard->isActive(42);

        $this->assertSame(
            ['inner' => true, 'after_inner' => true, 'after_outer' => false],
            $observed
        );
    }

    /**
     * Depth is per order, so unwinding one must not affect another.
     */
    public function testNestingIsTrackedPerOrder(): void
    {
        $this->guard->enter(42);
        $this->guard->enter(42);
        $this->guard->enter(43);

        $this->guard->leave(43);
        $this->assertFalse($this->guard->isActive(43));
        $this->assertTrue($this->guard->isActive(42));
    }

    /**
     * An unbalanced leave() is a bug in the caller, but it must not drive the
     * depth negative and wedge the order into a permanently guarded state.
     */
    public function testAnUnbalancedLeaveIsHarmless(): void
    {
        $this->guard->leave(42);
        $this->assertFalse($this->guard->isActive(42));

        $this->guard->enter(42);
        $this->guard->leave(42);
        $this->guard->leave(42);
        $this->assertFalse($this->guard->isActive(42));

        $this->guard->enter(42);
        $this->assertTrue($this->guard->isActive(42), 'still usable afterwards');
    }
}
