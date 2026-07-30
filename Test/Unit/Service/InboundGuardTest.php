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
}
