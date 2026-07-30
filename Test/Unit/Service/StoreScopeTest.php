<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Service;

use BobGroup\BobGo\Service\StoreScope;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Model\App\Emulation;
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
 */
class StoreScopeTest extends TestCase
{
    private $emulation;
    private $logger;
    /** @var StoreScope */
    private $scope;

    protected function setUp(): void
    {
        $this->emulation = $this->createMock(Emulation::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->scope = new StoreScope($this->emulation, $this->logger);
    }

    public function testEmulatesTheOrderStoreAroundTheCallback(): void
    {
        $this->emulation->expects($this->once())
            ->method('startEnvironmentEmulation')
            ->with(7, 'frontend', true);
        $this->emulation->expects($this->once())->method('stopEnvironmentEmulation');

        $ran = false;
        $result = $this->scope->forOrder($this->order(7), static function () use (&$ran) {
            $ran = true;
            return 'value';
        });

        $this->assertTrue($ran);
        $this->assertSame('value', $result);
    }

    /**
     * Leaving the scope emulated would poison everything that runs after us in
     * the same process — the rest of a cron batch, the rest of a request.
     */
    public function testRestoresTheScopeEvenWhenTheCallbackThrows(): void
    {
        $this->emulation->expects($this->once())->method('startEnvironmentEmulation');
        $this->emulation->expects($this->once())->method('stopEnvironmentEmulation');

        $this->expectException(\RuntimeException::class);

        $this->scope->forOrder($this->order(7), static function () {
            throw new \RuntimeException('boom');
        });
    }

    public function testSkipsEmulationWithoutAStoreId(): void
    {
        $this->emulation->expects($this->never())->method('startEnvironmentEmulation');

        $this->assertSame('value', $this->scope->forOrder($this->order(0), static function () {
            return 'value';
        }));
    }

    /**
     * Doing the work in the default scope beats not doing it — on a single-store
     * install that is the same scope anyway.
     */
    public function testStillRunsTheCallbackWhenEmulationFails(): void
    {
        $this->emulation->method('startEnvironmentEmulation')
            ->willThrowException(new \RuntimeException('no such store'));
        $this->logger->expects($this->once())->method('warning');

        $this->assertSame('value', $this->scope->forOrder($this->order(7), static function () {
            return 'value';
        }));
    }

    private function order(int $storeId): OrderInterface
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getStoreId')->willReturn($storeId);
        return $order;
    }
}
