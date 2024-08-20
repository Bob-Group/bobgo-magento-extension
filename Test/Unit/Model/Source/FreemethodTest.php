<?php

namespace BobGroup\BobGo\Test\Unit\Model\Source;

use BobGroup\BobGo\Model\Carrier\BobGo;
use BobGroup\BobGo\Model\Source\Freemethod;
use PHPUnit\Framework\TestCase;

class FreemethodTest extends TestCase
{
    /**
     * @var Freemethod
     */
    private $freemethod;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $bobGoMock;

    protected function setUp(): void
    {
        // Mock the BobGo dependency required by the parent class
        $this->bobGoMock = $this->createMock(BobGo::class);

        // Instantiate the Freemethod class with the required constructor arguments
        $this->freemethod = new Freemethod($this->bobGoMock);
    }

    public function testToOptionArray(): void
    {
        // Call the method under test
        $result = $this->freemethod->toOptionArray();

        // Check that the first option is 'None'
        $this->assertArrayHasKey(0, $result);
        $this->assertEquals(['value' => '', 'label' => 'None'], $result[0]);

        // Ensure the array is not empty after adding the 'None' option
        $this->assertNotEmpty($result);
    }
}
