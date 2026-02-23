<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Model;

use BobGroup\BobGo\Model\WebhookReceiver;
use BobGroup\BobGo\Service\FulfillmentService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class WebhookReceiverTest extends TestCase
{
    /**
     * @var WebhookReceiver
     */
    private $receiver;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $fulfillmentServiceMock;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $loggerMock;

    protected function setUp(): void
    {
        $this->fulfillmentServiceMock = $this->createMock(FulfillmentService::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);

        $this->receiver = new WebhookReceiver(
            $this->fulfillmentServiceMock,
            $this->loggerMock
        );
    }

    public function testReceiveFulfillmentCreated(): void
    {
        $payload = [
            'channel_ref_id' => '42',
            'fulfillment_id' => 'ful_123',
            'tracking_numbers' => [['number' => 'TRACK001', 'carrier' => 'Bob Go']],
        ];

        $this->fulfillmentServiceMock->expects($this->once())
            ->method('processFulfillment')
            ->with($payload);

        $result = $this->receiver->receive('fulfillment/created', $payload);

        $this->assertSame('fulfillment processed', $result);
    }

    public function testReceiveTrackingUpdated(): void
    {
        $payload = [
            'channel_ref_id' => '42',
            'tracking_numbers' => [['number' => 'TRACK002', 'carrier' => 'FastShip']],
        ];

        $this->fulfillmentServiceMock->expects($this->once())
            ->method('processTrackingUpdate')
            ->with($payload);

        $result = $this->receiver->receive('tracking/updated', $payload);

        $this->assertSame('tracking update processed', $result);
    }

    public function testReceiveUnknownTopic(): void
    {
        $this->loggerMock->expects($this->once())
            ->method('warning')
            ->with('Bob Go webhook: unknown topic', ['topic' => 'order/cancelled']);

        $this->fulfillmentServiceMock->expects($this->never())->method('processFulfillment');
        $this->fulfillmentServiceMock->expects($this->never())->method('processTrackingUpdate');

        $result = $this->receiver->receive('order/cancelled', []);

        $this->assertSame('unknown topic', $result);
    }

    public function testReceiveHandlesException(): void
    {
        $payload = ['channel_ref_id' => '42'];

        $this->fulfillmentServiceMock->expects($this->once())
            ->method('processFulfillment')
            ->willThrowException(new \RuntimeException('Something went wrong'));

        $this->loggerMock->expects($this->once())
            ->method('error')
            ->with(
                'Bob Go webhook processing failed',
                $this->callback(function ($context) {
                    return $context['topic'] === 'fulfillment/created'
                        && $context['error'] === 'Something went wrong';
                })
            );

        $result = $this->receiver->receive('fulfillment/created', $payload);

        $this->assertSame('error processing webhook', $result);
    }
}
