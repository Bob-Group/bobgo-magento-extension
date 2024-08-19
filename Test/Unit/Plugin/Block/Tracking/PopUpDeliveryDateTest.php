<?php

namespace BobGroup\BobGo\Test\Unit\Plugin\Block\Tracking;

use BobGroup\BobGo\Plugin\Block\Tracking\PopupDeliveryDate;
use Magento\Shipping\Block\Tracking\Popup;
use Magento\Shipping\Model\Tracking\Result\Status;
use PHPUnit\Framework\TestCase;

class PopupDeliveryDateTest extends TestCase
{
    /**
     * @var PopupDeliveryDate
     */
    private $plugin;

    protected function setUp(): void
    {
        // Instantiate the PopupDeliveryDate plugin
        $this->plugin = new PopupDeliveryDate();
    }

    public function testAfterFormatDeliveryDateTimeWithBobGoCarrier()
    {
        // Mock the Status class with BobGo carrier code
        $statusMock = $this->createMock(Status::class);
        $statusMock->method('getCarrier')->willReturn('bobgo_carrier_code');

        // Mock the Popup class
        $popupMock = $this->createMock(Popup::class);
        $popupMock->method('getTrackingInfo')->willReturn([
            ['tracking_info' => $statusMock],
        ]);

        // Mock the formatDeliveryDate method
        $popupMock->method('formatDeliveryDate')->with('2024-08-19')->willReturn('Aug 19, 2024');

        // Call the plugin method afterFormatDeliveryDateTime
        $result = $this->plugin->afterFormatDeliveryDateTime($popupMock, 'Aug 19, 2024 10:00 AM', '2024-08-19', '10:00 AM');

        // Assert that the time was stripped for BobGo carrier
        $this->assertEquals('Aug 19, 2024', $result);
    }

    public function testAfterFormatDeliveryDateTimeWithOtherCarrier()
    {
        // Mock the Status class with a different carrier code
        $statusMock = $this->createMock(Status::class);
        $statusMock->method('getCarrier')->willReturn('other_carrier_code');

        // Mock the Popup class
        $popupMock = $this->createMock(Popup::class);
        $popupMock->method('getTrackingInfo')->willReturn([
            ['tracking_info' => $statusMock],
        ]);

        // Call the plugin method afterFormatDeliveryDateTime
        $result = $this->plugin->afterFormatDeliveryDateTime($popupMock, 'Aug 19, 2024 10:00 AM', '2024-08-19', '10:00 AM');

        // Assert that the time remains unchanged for other carriers
        $this->assertEquals('Aug 19, 2024 10:00 AM', $result);
    }

    public function testGetCarrierWithNoTrackingInfo()
    {
        // Mock the Popup class with no tracking info
        $popupMock = $this->createMock(Popup::class);
        $popupMock->method('getTrackingInfo')->willReturn([]);

        // Call the getCarrier method directly
        $reflection = new \ReflectionClass($this->plugin);
        $method = $reflection->getMethod('getCarrier');
        $method->setAccessible(true);

        $result = $method->invokeArgs($this->plugin, [$popupMock]);

        // Assert that an empty string is returned when no tracking info is available
        $this->assertEquals('', $result);
    }
}
