<?php

namespace BobGroup\BobGo\Test\Unit\Plugin\Block\DataProviders\Tracking;

use BobGroup\BobGo\Plugin\Block\DataProviders\Tracking\ChangeTitle;
use BobGroup\BobGo\Model\Carrier\BobGo;
use Magento\Shipping\Model\Tracking\Result\Status;
use Magento\Shipping\Block\DataProviders\Tracking\DeliveryDateTitle as Subject;
use PHPUnit\Framework\TestCase;

class ChangeTitleTest extends TestCase
{
    /**
     * @var ChangeTitle
     */
    private $plugin;

    protected function setUp(): void
    {
        // Instantiate the ChangeTitle plugin
        $this->plugin = new ChangeTitle();
    }

    public function testAfterGetTitleWithBobGoCarrier(): void
    {
        // Create a custom Status object with BobGo carrier
        $status = $this->getMockBuilder(Status::class)
            ->setMethods(['getCarrier'])
            ->getMock();

        $status->method('getCarrier')->willReturn(BobGo::CODE);

        // Mock the Subject class
        $subjectMock = $this->createMock(Subject::class);

        // Call the plugin method afterGetTitle
        $result = $this->plugin->afterGetTitle($subjectMock, 'Original Title', $status);

        // Assert that the title was changed for BobGo carrier
        $this->assertEquals('Expected delivery:', $result);
    }

    public function testAfterGetTitleWithOtherCarrier(): void
    {
        // Create a custom Status object with a different carrier
        $status = $this->getMockBuilder(Status::class)
            ->setMethods(['getCarrier'])
            ->getMock();

        $status->method('getCarrier')->willReturn('other_carrier_code');

        // Mock the Subject class
        $subjectMock = $this->createMock(Subject::class);

        // Call the plugin method afterGetTitle
        $originalTitle = 'Original Title';
        $result = $this->plugin->afterGetTitle($subjectMock, $originalTitle, $status);

        // Assert that the title was not changed for other carriers
        $this->assertEquals($originalTitle, $result);
    }
}
