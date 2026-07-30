<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Service;

use BobGroup\BobGo\Service\ConnectionHealth;
use Magento\Framework\FlagManager;
use PHPUnit\Framework\TestCase;

/**
 * "An API key is saved" is not "we can talk to Bob Go". A key revoked or rotated
 * on the Bob Go side left the config page looking perfectly healthy while every
 * call 401'd, so the state is written through from real traffic.
 *
 * The rule that carries the most weight here is the inconclusive one: a 404 means
 * the key is fine but the channel isn't enrolled, and a timeout means we learned
 * nothing. Treating either as "invalid" would have the config page cry wolf on
 * every blip.
 */
class ConnectionHealthTest extends TestCase
{
    private $flagManager;
    /** @var ConnectionHealth */
    private $health;

    /** @var array<string,mixed> */
    private $flags = [];

    protected function setUp(): void
    {
        $this->flagManager = $this->createMock(FlagManager::class);
        $this->flagManager->method('getFlagData')->willReturnCallback(
            function ($code) {
                return $this->flags[$code] ?? null;
            }
        );
        $this->flagManager->method('saveFlag')->willReturnCallback(
            function ($code, $value) {
                $this->flags[$code] = $value;
                return true;
            }
        );

        $this->health = new ConnectionHealth($this->flagManager, $this->createMock(\Psr\Log\LoggerInterface::class));
    }

    public function testStartsUnknown(): void
    {
        $this->assertSame(ConnectionHealth::STATE_UNKNOWN, $this->health->getState());
        $this->assertNull($this->health->getCheckedAt());
    }

    /**
     * @dataProvider successStatusProvider
     */
    public function testAnySuccessMeansValid(int $status): void
    {
        $this->health->observe($status);

        $this->assertSame(ConnectionHealth::STATE_VALID, $this->health->getState());
        $this->assertNotNull($this->health->getCheckedAt());
    }

    /**
     * @return array<string,array{0:int}>
     */
    public function successStatusProvider(): array
    {
        return ['200' => [200], '201' => [201], '204' => [204]];
    }

    public function testUnauthorisedMeansInvalid(): void
    {
        $this->health->observe(401);

        $this->assertSame(ConnectionHealth::STATE_INVALID, $this->health->getState());
    }

    /**
     * The important one. 404 = key fine, channel not enrolled. 500 = Bob Go's
     * problem. 0 = no response at all. None of them says anything about the key.
     *
     * @dataProvider inconclusiveStatusProvider
     */
    public function testInconclusiveStatusesLeaveAKnownGoodStateAlone(int $status): void
    {
        $this->health->observe(200);
        $this->health->observe($status);

        $this->assertSame(ConnectionHealth::STATE_VALID, $this->health->getState());
    }

    /**
     * @return array<string,array{0:int}>
     */
    public function inconclusiveStatusProvider(): array
    {
        return [
            'not enrolled' => [404],
            'server error' => [500],
            'bad gateway' => [502],
            'transport failure' => [0],
        ];
    }

    public function testInconclusiveStatusesDoNotInventAState(): void
    {
        $this->health->observe(0);

        $this->assertSame(ConnectionHealth::STATE_UNKNOWN, $this->health->getState());
    }

    /**
     * Only transitions are written, so this costs nothing on the hot path.
     */
    public function testRepeatedSameStateIsNotWrittenAgain(): void
    {
        $flagManager = $this->createMock(FlagManager::class);
        $flagManager->method('getFlagData')->willReturnCallback(
            function ($code) {
                return $this->flags[$code] ?? null;
            }
        );
        // Two saves for the one transition (state + timestamp), and nothing after.
        $flagManager->expects($this->exactly(2))->method('saveFlag')
            ->willReturnCallback(function ($code, $value) {
                $this->flags[$code] = $value;
                return true;
            });

        $health = new ConnectionHealth($flagManager, $this->createMock(\Psr\Log\LoggerInterface::class));
        $health->observe(200);
        $health->observe(200);
        $health->observe(200);
    }

    /**
     * FlagManager reloads from the database on every getFlagData() call, and
     * observe() runs on every API response — including each rates-at-checkout
     * call. Without the memo, health tracking would add a query to the checkout
     * hot path.
     */
    public function testReadsTheStoredStateAtMostOncePerRequest(): void
    {
        $flagManager = $this->createMock(FlagManager::class);
        $flagManager->expects($this->once())->method('getFlagData')->willReturn(null);
        $flagManager->method('saveFlag')->willReturn(true);

        $health = new ConnectionHealth($flagManager, $this->createMock(\Psr\Log\LoggerInterface::class));

        // First call reads; the transition seeds the memo; the rest are free.
        $health->observe(200);
        $health->observe(200);
        $health->observe(404);
        $this->assertSame(ConnectionHealth::STATE_VALID, $health->getState());
    }

    public function testRecoversFromInvalidToValid(): void
    {
        $this->health->observe(401);
        $this->health->observe(200);

        $this->assertSame(ConnectionHealth::STATE_VALID, $this->health->getState());
    }
}
