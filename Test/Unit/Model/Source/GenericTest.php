<?php

namespace BobGroup\BobGo\Test\Unit\Model\Source;

use BobGroup\BobGo\Model\Carrier\BobGo;
use BobGroup\BobGo\Model\Source\Generic;
use PHPUnit\Framework\TestCase;

class GenericTest extends TestCase
{
    /**
     * @var Generic
     */
    private $generic;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $bobGoMock;

    protected function setUp(): void
    {
        // Mock the BobGo class dependency
        $this->bobGoMock = $this->createMock(BobGo::class);

        // Instantiate the Generic class with the required constructor arguments
        $this->generic = new Generic($this->bobGoMock);

        // Set the carrier code for the test
        $reflection = new \ReflectionClass($this->generic);
        $codeProperty = $reflection->getProperty('_code');
        $codeProperty->setValue($this->generic, 'test_code');
    }

    public function testToOptionArray(): void
    {
        // Set up the expected return value from the BobGo's getCode method
        $this->bobGoMock->method('getCode')->with('test_code')->willReturn([
            'code1' => 'Title 1',
            'code2' => 'Title 2',
        ]);

        // Call the method under test
        $result = $this->generic->toOptionArray();

        // The expected result
        $expected = [
            ['value' => 'code1', 'label' => 'Title 1'],
            ['value' => 'code2', 'label' => 'Title 2'],
        ];

        $this->assertEquals($expected, $result);
    }

    public function testToOptionArrayWithEmptyConfig(): void
    {
        // Set up the getCode method to return null
        $this->bobGoMock->method('getCode')->with('test_code')->willReturn(null);

        // Call the method under test
        $result = $this->generic->toOptionArray();

        // The expected result is an empty array
        $this->assertEquals([], $result);
    }
}
