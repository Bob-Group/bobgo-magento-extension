<?php

namespace BobGroup\BobGo\Test\Unit\Block\System\Config\Form\Field;

use BobGroup\BobGo\Block\System\Config\Form\Field\Version;
use BobGroup\BobGo\Helper\Data;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Backend\Block\Template\Context;
use PHPUnit\Framework\TestCase;

class VersionTest extends TestCase
{
    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $helperMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $contextMock;

    /**
     * @var Version
     */
    private $versionBlock;

    protected function setUp(): void
    {
        $this->helperMock = $this->createMock(Data::class);
        $this->contextMock = $this->createMock(Context::class);

        // Instantiate the Version block
        $this->versionBlock = new Version($this->contextMock, $this->helperMock);
    }

    /**
     * @param object $object
     * @param string $methodName
     * @param array<int, mixed> $parameters
     * @return mixed
     */
    private function callProtectedMethod(object $object, string $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass($object);
        $method = $reflection->getMethod($methodName);

        return $method->invokeArgs($object, $parameters);
    }

    public function testGetElementHtml(): void
    {
        // Mock the AbstractElement
        $elementMock = $this->createMock(AbstractElement::class);

        // Set up the helper mock to return a specific version
        $this->helperMock->method('getExtensionVersion')->willReturn('1.0.0');

        // Set the expectation for the setData method to set the 'value'
        $elementMock->expects($this->once())
            ->method('setData')
            ->with('value', '<a href="https://www.bobgo.co.za" title="BobGo" target="_blank">1.0.0</a>');

        // Mock the getData method to return the value that was set
        $elementMock->method('getData')
            ->with('value')
            ->willReturn('<a href="https://www.bobgo.co.za" title="BobGo" target="_blank">1.0.0</a>');

        // Call the protected _getElementHtml method using reflection
        $result = $this->callProtectedMethod($this->versionBlock, '_getElementHtml', [$elementMock]);

        // Assert that the result is the expected HTML string
        $expectedHtml = '<a href="https://www.bobgo.co.za" title="BobGo" target="_blank">1.0.0</a>';
        $this->assertEquals($expectedHtml, $result);
    }

    public function testGetElementHtmlWithNonStringValue(): void
    {
        // Mock the AbstractElement
        $elementMock = $this->createMock(AbstractElement::class);

        // Set up the helper mock to return a specific version
        $this->helperMock->method('getExtensionVersion')->willReturn('1.0.0');

        // Return a non-string value for the 'value' key
        $elementMock->method('getData')
            ->with('value')
            ->willReturn(['not_a_string']);

        // Call the protected _getElementHtml method using reflection
        $result = $this->callProtectedMethod($this->versionBlock, '_getElementHtml', [$elementMock]);

        // Assert that the result is an empty string
        $this->assertEquals('', $result);
    }
}
