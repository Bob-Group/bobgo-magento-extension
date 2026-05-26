<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Controller\Webhook;

use BobGroup\BobGo\Model\Config\ApiConfig;
use BobGroup\BobGo\Model\SyncLog;
use BobGroup\BobGo\Service\FulfillmentService;
use BobGroup\BobGo\Service\SyncLogger;
use BobGroup\BobGo\Service\TransientWebhookException;
use BobGroup\BobGo\Service\WebhookSignatureVerifier;
use PHPUnit\Framework\TestCase;

/**
 * The blocker Codex found:
 *
 *   On a transient failure, the controller would release the claim row
 *   and then immediately re-insert a "failure" row with the same
 *   event_id. The UNIQUE(event_id, direction) constraint then made the
 *   row look like a duplicate to Bob Go's retry, which 200'd without
 *   reprocessing — the event was lost.
 *
 * These tests pin down the new contract: the failure log entry MUST NOT
 * carry the event_id, so the unique slot stays free for the retry.
 */
class ReceiveTest extends TestCase
{
    private $signatureVerifier;
    private $apiConfig;
    private $fulfillmentService;
    private $syncLogger;
    private $logger;

    protected function setUp(): void
    {
        $this->signatureVerifier = $this->createMock(WebhookSignatureVerifier::class);
        $this->apiConfig = $this->createMock(ApiConfig::class);
        $this->fulfillmentService = $this->createMock(FulfillmentService::class);
        $this->syncLogger = $this->createMock(SyncLogger::class);
        $this->logger = $this->createMock(\Psr\Log\LoggerInterface::class);
    }

    public function testTransientFailureReleasesClaimAndLogsWithoutEventId(): void
    {
        $eventId = 'evt_xyz';
        $this->signatureVerifier->method('verify')->willReturn(true);
        $this->apiConfig->method('isFulfillmentSyncEnabled')->willReturn(true);

        // claim succeeds, processing throws transient
        $this->syncLogger->method('claimEventId')->willReturn(true);
        $this->fulfillmentService->method('processFulfillment')
            ->willThrowException(new TransientWebhookException('shipment creation race'));

        // The crucial expectations:
        //   1. The claim is released (DELETE).
        $this->syncLogger->expects($this->once())
            ->method('releaseEventIdClaim')
            ->with($eventId);

        //   2. The failure log row is written with event_id = NULL — if this
        //      were $eventId, the unique-constraint slot would still be
        //      occupied and Bob Go's retry would dedup to a 200 instead
        //      of being reprocessed.
        $this->syncLogger->expects($this->once())
            ->method('logInbound')
            ->with(
                SyncLog::EVENT_WEBHOOK_RECEIVED,
                $this->callback(function ($payload) use ($eventId) {
                    return is_array($payload) && ($payload['event_id'] ?? null) === $eventId;
                }),
                null,
                null, // <-- the assertion that pins down the fix
                500,
                false
            );

        $this->runController($eventId, 'fulfillment/created', 500);
    }

    public function testSuccessfulProcessingDoesNotReleaseClaim(): void
    {
        $eventId = 'evt_ok';
        $this->signatureVerifier->method('verify')->willReturn(true);
        $this->apiConfig->method('isFulfillmentSyncEnabled')->willReturn(true);
        $this->syncLogger->method('claimEventId')->willReturn(true);
        // Processing succeeds; no exception.

        // The claim is *not* released on success — it stays in the table
        // (upgraded to success=1 via SyncLogger::logInbound's upgradeClaim
        // path) so future retries see a duplicate and 200 cleanly.
        $this->syncLogger->expects($this->never())->method('releaseEventIdClaim');

        $this->runController($eventId, 'fulfillment/created', 200);
    }

    public function testDuplicateClaimShortCircuitsTo200(): void
    {
        $eventId = 'evt_dup';
        $this->signatureVerifier->method('verify')->willReturn(true);
        $this->apiConfig->method('isFulfillmentSyncEnabled')->willReturn(true);
        // claimEventId returns false → another worker is processing
        $this->syncLogger->method('claimEventId')->willReturn(false);

        // We must not process when dedup says "already claimed."
        $this->fulfillmentService->expects($this->never())->method('processFulfillment');
        $this->fulfillmentService->expects($this->never())->method('processTrackingUpdate');

        $this->runController($eventId, 'fulfillment/created', 200);
    }

    public function testDisabledFulfillmentSyncDoesNotProcess(): void
    {
        $this->signatureVerifier->method('verify')->willReturn(true);
        $this->apiConfig->method('isFulfillmentSyncEnabled')->willReturn(false);

        // No claim attempted, no processing.
        $this->syncLogger->expects($this->never())->method('claimEventId');
        $this->fulfillmentService->expects($this->never())->method('processFulfillment');

        $this->runController('evt_anything', 'fulfillment/created', 200);
    }

    /**
     * Stand up just enough of the controller to exercise execute().
     */
    private function runController(string $eventId, string $topic, int $expectedStatus): void
    {
        $rawBody = json_encode([
            'event_id' => $eventId,
            'channel_order_number' => '000000001',
            'method_reference' => 'TRK-1',
        ]);

        $request = $this->createMock(\Magento\Framework\App\RequestInterface::class);
        $request->method('getContent')->willReturn($rawBody);
        $request->method('getHeader')->willReturnCallback(function ($name) use ($eventId, $topic) {
            if ($name === 'Bobgo-Webhook-Signature') {
                return 'sig';
            }
            if ($name === 'Bobgo-Webhook-Event-Id') {
                return $eventId;
            }
            if (stripos($name, 'topic') !== false) {
                return $topic;
            }
            return null;
        });

        $jsonResult = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['setHttpResponseCode', 'setData'])
            ->getMock();
        $actualStatus = 200;
        $jsonResult->method('setHttpResponseCode')->willReturnCallback(function ($code) use (&$actualStatus, $jsonResult) {
            $actualStatus = $code;
            return $jsonResult;
        });
        $jsonResult->method('setData')->willReturnSelf();

        $jsonFactory = $this->getMockBuilder(\Magento\Framework\Controller\Result\JsonFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $jsonFactory->method('create')->willReturn($jsonResult);

        // Build the controller. Magento's Action base touches a Context;
        // we hand it null and a stub-injected request via reflection.
        $controller = new \BobGroup\BobGo\Controller\Webhook\Receive(
            $this->createMock(\Magento\Framework\App\Action\Context::class),
            $this->fulfillmentService,
            $jsonFactory,
            $this->logger,
            $this->signatureVerifier,
            $this->syncLogger,
            $this->apiConfig
        );

        $reflection = new \ReflectionClass($controller);
        $requestProperty = $reflection->getParentClass()->getProperty('_request');
        $requestProperty->setValue($controller, $request);

        $controller->execute();
        $this->assertSame($expectedStatus, $actualStatus);
    }
}
