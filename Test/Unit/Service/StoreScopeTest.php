<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Service;

use BobGroup\BobGo\Service\StoreScope;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Everything read per-store — API key, environment, the channel identifier built
 * from the store base URL — resolves from the *current* store. That is right
 * inside a storefront request and wrong in cron (no store context, so the default
 * store) and on the webhook endpoint (whichever store the delivery URL mapped to,
 * which is a single URL for the whole account).
 *
 * On one store those coincide. On several, an order from store B would be pushed
 * with store A's credentials — into the wrong Bob Go channel.
 *
 * The emulation is driven with `force = false` deliberately. Magento's own guard
 * is `if ($storeId == currentStoreId && !$force) return;`, so `true` would only
 * buy the right to emulate a store we are already in — every order on a
 * single-store install — and emulation reloads theme, locale and translations.
 * These tests therefore model the current store as mutable state, so the
 * short-circuit is exercised rather than assumed.
 */
class StoreScopeTest extends TestCase
{
    private $emulation;
    private $storeManager;
    private $logger;
    /** @var StoreScope */
    private $scope;

    /** Current store id, as the fake StoreManager reports it. */
    private $currentStoreId = 1;

    /** @var int Times emulation actually changed scope. */
    private $started = 0;

    /** @var int */
    private $stopped = 0;

    protected function setUp(): void
    {
        $this->emulation = $this->createMock(Emulation::class);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->storeManager->method('getStore')->willReturnCallback(
            function () {
                $store = $this->createMock(StoreInterface::class);
                $store->method('getId')->willReturn($this->currentStoreId);
                return $store;
            }
        );

        // Mirrors Magento: same store + force=false is a no-op, otherwise the
        // current store changes.
        $this->emulation->method('startEnvironmentEmulation')->willReturnCallback(
            function ($storeId, $area = 'frontend', $force = false) {
                if ((int) $storeId === $this->currentStoreId && !$force) {
                    return null;
                }
                $this->currentStoreId = (int) $storeId;
                $this->started++;
                return null;
            }
        );
        $this->emulation->method('stopEnvironmentEmulation')->willReturnCallback(
            function () {
                $this->stopped++;
                return null;
            }
        );

        $this->scope = new StoreScope($this->emulation, $this->storeManager, $this->logger);
    }

    public function testEmulatesWhenTheOrderBelongsToAnotherStore(): void
    {
        $this->currentStoreId = 1;

        $ran = false;
        $result = $this->scope->forOrder($this->order(7), function () use (&$ran) {
            $ran = true;
            $this->assertSame(7, $this->currentStoreId, 'callback runs inside the emulated scope');
            return 'value';
        });

        $this->assertTrue($ran);
        $this->assertSame('value', $result);
        $this->assertSame(1, $this->started);
        $this->assertSame(1, $this->stopped);
    }

    /**
     * The single-store case, i.e. almost every install. Emulation short-circuits,
     * so there is nothing to tear down either — and paying for a theme/locale
     * reload per order in a 50-order batch would be pure waste.
     */
    public function testDoesNotEmulateOrStopWhenAlreadyInTheOrdersStore(): void
    {
        $this->currentStoreId = 1;

        $this->scope->forOrder($this->order(1), static function () {
            return null;
        });

        $this->assertSame(0, $this->started);
        $this->assertSame(0, $this->stopped, 'nothing was emulated, so nothing must be restored');
    }

    /**
     * Leaving the scope emulated would poison everything that runs after us in
     * the same process — the rest of a cron batch, the rest of a request.
     */
    public function testRestoresTheScopeEvenWhenTheCallbackThrows(): void
    {
        $this->currentStoreId = 1;

        try {
            $this->scope->forOrder($this->order(7), static function () {
                throw new \RuntimeException('boom');
            });
            $this->fail('Expected the exception to propagate');
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertSame(1, $this->started);
        $this->assertSame(1, $this->stopped);
    }

    public function testSkipsEmulationWithoutAStoreId(): void
    {
        $this->assertSame('value', $this->scope->forOrder($this->order(0), static function () {
            return 'value';
        }));

        $this->assertSame(0, $this->started);
    }

    /**
     * Doing the work in the default scope beats not doing it — on a single-store
     * install that is the same scope anyway.
     */
    public function testStillRunsTheCallbackWhenEmulationFails(): void
    {
        $emulation = $this->createMock(Emulation::class);
        $emulation->method('startEnvironmentEmulation')
            ->willThrowException(new \RuntimeException('no such store'));
        $emulation->expects($this->never())->method('stopEnvironmentEmulation');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $scope = new StoreScope($emulation, $this->storeManager, $logger);

        $this->assertSame('value', $scope->forOrder($this->order(7), static function () {
            return 'value';
        }));
    }

    /**
     * Magento permits only one level: a nested start() logs an error and returns,
     * leaving the outer emulation's saved state in place. An unconditional stop()
     * would then restore it out from under the outer scope, so the inner call must
     * notice it changed nothing and keep its hands off.
     */
    public function testDoesNotTearDownAnOuterEmulationIfNested(): void
    {
        $emulation = $this->createMock(Emulation::class);
        // Already emulating: start() is a no-op that changes no store.
        $emulation->method('startEnvironmentEmulation')->willReturn(null);
        $emulation->expects($this->never())->method('stopEnvironmentEmulation');

        $scope = new StoreScope($emulation, $this->storeManager, $this->logger);

        $scope->forOrder($this->order(7), static function () {
            return null;
        });
    }

    private function order(int $storeId): OrderInterface
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getStoreId')->willReturn($storeId);
        return $order;
    }
}
