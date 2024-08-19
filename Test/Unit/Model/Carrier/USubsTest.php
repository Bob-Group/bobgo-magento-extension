<?php

namespace BobGroup\BobGo\Test\Unit\Model\Carrier;

use BobGroup\BobGo\Model\Carrier\USubs;
use Magento\Framework\App\Request\Http;
use PHPUnit\Framework\TestCase;

class USubsTest extends TestCase
{
    /**
     * @var USubs
     */
    private $uSubs;

    /**
     * @var Http|\PHPUnit\Framework\MockObject\MockObject
     */
    private $requestMock;

    protected function setUp(): void
    {
        $this->requestMock = $this->createMock(Http::class);

        $this->uSubs = new USubs($this->requestMock);
    }

    public function testGetDestComp()
    {
        $requestBody = json_encode([
            'address' => [
                'company' => 'Test Company'
            ]
        ]);

        $this->requestMock->method('getContent')
            ->willReturn($requestBody);

        $result = $this->uSubs->getDestComp();

        $this->assertEquals('Test Company', $result);
    }

    public function testGetDestCompReturnsEmptyStringWhenNotSet()
    {
        $requestBody = json_encode([]);

        $this->requestMock->method('getContent')
            ->willReturn($requestBody);

        $result = $this->uSubs->getDestComp();

        $this->assertEquals('', $result);
    }

    public function testGetDestCompReturnsEmptyStringWhenInvalidStructure()
    {
        // Test case where the JSON structure is invalid
        $requestBody = json_encode([
            'invalid' => 'structure'
        ]);

        $this->requestMock->method('getContent')
            ->willReturn($requestBody);

        $result = $this->uSubs->getDestComp();

        $this->assertEquals('', $result);
    }
}
