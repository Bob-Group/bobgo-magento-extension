<?php

namespace BobGroup\BobGo\Test\Unit\Plugin\Checkout\Block;

use BobGroup\BobGo\Plugin\Checkout\Block\LayoutProcessorPlugin;
use Magento\Checkout\Block\Checkout\LayoutProcessor;
use PHPUnit\Framework\TestCase;

class LayoutProcessorPluginTest extends TestCase
{
    /**
     * @var LayoutProcessorPlugin
     */
    private $plugin;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $layoutProcessorMock;

    protected function setUp(): void
    {
        // Instantiate the LayoutProcessorPlugin
        $this->plugin = new LayoutProcessorPlugin();

        // Mock the LayoutProcessor class
        $this->layoutProcessorMock = $this->createMock(LayoutProcessor::class);
    }

    public function testAfterProcessWithValidJsLayout()
    {
        // Mock the jsLayout array with the necessary structure
        $jsLayout = [
            'components' => [
                'checkout' => [
                    'children' => [
                        'steps' => [
                            'children' => [
                                'shipping-step' => [
                                    'children' => [
                                        'shippingAddress' => [
                                            'children' => [
                                                'shipping-address-fieldset' => [
                                                    'children' => []
                                                ]
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];

        // Call the plugin's afterProcess method
        $result = $this->plugin->afterProcess($this->layoutProcessorMock, $jsLayout);

        // Assert that the suburb field has been added to the jsLayout
        $this->assertArrayHasKey('suburb', $result['components']['checkout']['children']['steps']['children']
        ['shipping-step']['children']['shippingAddress']['children']['shipping-address-fieldset']['children']);

        $suburbField = $result['components']['checkout']['children']['steps']['children']['shipping-step']['children']
        ['shippingAddress']['children']['shipping-address-fieldset']['children']['suburb'];

        $this->assertEquals('Suburb', $suburbField['label']);
        $this->assertEquals(true, $suburbField['visible']);
        $this->assertEquals('shippingAddress.custom_attributes.suburb', $suburbField['dataScope']);
    }

    public function testAfterProcessWithInvalidJsLayout()
    {
        // Mock the jsLayout array with an incomplete structure
        $jsLayout = [
            'components' => []
        ];

        // Call the plugin's afterProcess method
        $result = $this->plugin->afterProcess($this->layoutProcessorMock, $jsLayout);

        // Assert that the jsLayout remains unchanged
        $this->assertEquals($jsLayout, $result);
    }
}
