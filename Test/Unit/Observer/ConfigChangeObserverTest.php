<?php

namespace BobGroup\BobGo\Test\Unit\Observer;

use BobGroup\BobGo\Model\Carrier\BobGo;
use BobGroup\BobGo\Observer\ConfigChangeObserver;
use Magento\Framework\Event\Observer;
use Magento\Framework\Message\ManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ConfigChangeObserverTest extends TestCase
{
    /**
     * @var ConfigChangeObserver
     */
    private $observer;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $bobGoMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $loggerMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $messageManagerMock;

    protected function setUp(): void
    {
        // Mock the dependencies
        $this->bobGoMock = $this->createMock(BobGo::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->messageManagerMock = $this->createMock(ManagerInterface::class);

        // Instantiate the ConfigChangeObserver with the mocked dependencies
        $this->observer = new ConfigChangeObserver(
            $this->bobGoMock,
            $this->loggerMock,
            $this->messageManagerMock
        );
    }

    public function testExecuteWithActiveCarrierAndSuccessfulConnection()
    {
        // Set up the observer mock
        $observerMock = $this->createMock(Observer::class);

        // Mock the event data to simulate the carrier being active
        $observerMock->method('getEvent')->willReturnSelf();
        $observerMock->method('getData')->with('changed_paths')->willReturn(['carriers/bobgo/active']);

        // Mock the BobGo object methods
        $this->bobGoMock->method('isActive')->willReturn(true);
        $this->bobGoMock->method('triggerRatesTest')->willReturn(true);

        // Expect success message to be added
        $this->messageManagerMock->expects($this->once())
            ->method('addSuccessMessage')
            ->with(__('Bob Go rates at checkout connected.'));

        // Execute the observer
        $this->observer->execute($observerMock);
    }

    public function testExecuteWithActiveCarrierAndFailedConnection()
    {
        // Set up the observer mock
        $observerMock = $this->createMock(Observer::class);

        // Mock the event data to simulate the carrier being active
        $observerMock->method('getEvent')->willReturnSelf();
        $observerMock->method('getData')->with('changed_paths')->willReturn(['carriers/bobgo/active']);

        // Mock the BobGo object methods
        $this->bobGoMock->method('isActive')->willReturn(true);
        $this->bobGoMock->method('triggerRatesTest')->willReturn(false);

        // Expect error message to be added
        $this->messageManagerMock->expects($this->once())
            ->method('addErrorMessage')
            ->with(__('Failed to connect to rates at checkout. Please check your internet connection
                        and make sure Rates at checkout is enabled for your channel on Bob Go. Please visit Bob Go
                        settings page to make sure your Magento channel is enabled to receive rates.
                        https://my.bobgo.co.za/rates-at-checkout?tab=settings'));

        // Execute the observer
        $this->observer->execute($observerMock);
    }

    public function testExecuteWithInactiveCarrier()
    {
        // Set up the observer mock
        $observerMock = $this->createMock(Observer::class);

        // Mock the event data to simulate the carrier being inactive
        $observerMock->method('getEvent')->willReturnSelf();
        $observerMock->method('getData')->with('changed_paths')->willReturn(['carriers/bobgo/active']);

        // Mock the BobGo object method to return inactive
        $this->bobGoMock->method('isActive')->willReturn(false);

        // Ensure no messages are added
        $this->messageManagerMock->expects($this->never())
            ->method('addSuccessMessage');
        $this->messageManagerMock->expects($this->never())
            ->method('addErrorMessage');

        // Execute the observer
        $this->observer->execute($observerMock);
    }
}
