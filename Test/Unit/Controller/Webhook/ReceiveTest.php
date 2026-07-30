<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Test\Unit\Controller\Webhook;

use BobGroup\BobGo\Model\Config\ApiConfig;
use BobGroup\BobGo\Model\SyncLog;
use BobGroup\BobGo\Service\FulfillmentService;
use BobGroup\BobGo\Service\OrderResolution;
use BobGroup\BobGo\Service\OrderResolver;
use BobGroup\BobGo\Service\StoreScope;
use BobGroup\BobGo\Service\SyncLogger;
use BobGroup\BobGo\Service\TransientWebhookException;
use BobGroup\BobGo\Service\WebhookSignatureVerifier;
use PHPUnit\Framework\TestCase;

/**
 * The webhook controller's job is 10% routing and 90% choosing an HTTP status.
 *
 * Two incident classes are pinned down here.
 *
 * 1. THE DEDUP SLOT (Codex found the first half of this).
 *    Any row written with a non-null event_id occupies the
 *    UNIQUE(event_id, direction) slot for good, so the next delivery of that
 *    event fails claimEventId() and is answered "duplicate, ignored". Every
 *    non-success outcome must therefore log with event_id = NULL. Originally
 *    only the transient branch got this right; the 403/400 branches also wrote
 *    the id, which meant a merchant who enabled fulfilment sync before pasting
 *    the webhook secret permanently lost every delivery in between.
 *
 * 2. THE SUBSCRIPTION DISABLE WINDOW.
 *    Bob Go counts any non-2xx as a delivery failure and disables the whole
 *    subscription after three days without a success — telling only the
 *    merchant. Subscriptions are account-wide, so this endpoint receives events
 *    for orders that aren't ours at all. Those must be acknowledged with 200,
 *    not rejected.
 */
class ReceiveTest extends TestCase
{
    private $signatureVerifier;
    private $apiConfig;
    private $fulfillmentService;
    private $syncLogger;
    private $logger;
    private $orderResolver;
    private $storeScope;

    protected function setUp(): void
    {
        $this->signatureVerifier = $this->createMock(WebhookSignatureVerifier::class);
        $this->apiConfig = $this->createMock(ApiConfig::class);
        $this->fulfillmentService = $this->createMock(FulfillmentService::class);
        $this->syncLogger = $this->createMock(SyncLogger::class);
        $this->logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $this->orderResolver = $this->createMock(OrderResolver::class);

        // The real StoreScope emulates the order's store around routing;
        // here it just needs to invoke the callback.
        $this->storeScope = $this->createMock(StoreScope::class);
        $this->storeScope->method('forOrder')->willReturnCallback(
            static function ($order, callable $callback) {
                return $callback();
            }
        );
    }

    // ---------------------------------------------------------------- dedup slot

    public function testTransientFailureReleasesClaimAndLogsWithoutEventId(): void
    {
        $eventId = 'evt_xyz';
        $this->passSignature();
        $this->resolvesTo($this->anOrder());

        $this->syncLogger->method('claimEventId')->willReturn(true);
        $this->fulfillmentService->method('processFulfillment')
            ->willThrowException(new TransientWebhookException('shipment creation race'));

        //   1. The claim is released (DELETE) so the retry can re-claim.
        $this->syncLogger->expects($this->once())
            ->method('releaseEventIdClaim')
            ->with($eventId);

        //   2. The failure row carries event_id = NULL.
        $this->syncLogger->expects($this->once())
            ->method('logInbound')
            ->with(
                SyncLog::EVENT_WEBHOOK_RECEIVED,
                $this->callback(function ($payload) use ($eventId) {
                    return is_array($payload) && ($payload['event_id'] ?? null) === $eventId;
                }),
                42,
                null, // <-- the assertion that pins down the fix
                500,
                false
            );

        $this->runController($eventId, 'fulfillment/created', 500);
    }

    /**
     * The branch that was missed: a signature rejection must not claim the slot
     * either, or the delivery can never be retried once the secret is fixed.
     */
    public function testRejectedSignatureLogsWithoutEventId(): void
    {
        $eventId = 'evt_unsigned';
        $this->signatureVerifier->method('verify')->willReturn(false);

        $this->syncLogger->expects($this->once())
            ->method('logInbound')
            ->with(
                SyncLog::EVENT_WEBHOOK_REJECTED,
                $this->callback(function ($payload) use ($eventId) {
                    return is_array($payload) && ($payload['event_id'] ?? null) === $eventId;
                }),
                null,
                null, // <-- must not occupy the dedup slot
                403,
                false
            );

        // Nothing else may happen on an unverified body.
        $this->syncLogger->expects($this->never())->method('claimEventId');
        $this->orderResolver->expects($this->never())->method('resolve');

        $this->runController($eventId, 'fulfillment/created', 403);
    }

    public function testUnparseableBodyLogsWithoutEventId(): void
    {
        $eventId = 'evt_garbage';
        $this->passSignature();

        $this->syncLogger->expects($this->once())
            ->method('logInbound')
            ->with(
                SyncLog::EVENT_WEBHOOK_REJECTED,
                $this->anything(),
                null,
                null,
                400,
                false
            );

        $this->runController($eventId, 'fulfillment/created', 400, 'not json at all');
    }

    public function testSuccessfulProcessingDoesNotReleaseClaim(): void
    {
        $this->passSignature();
        $this->resolvesTo($this->anOrder());
        $this->syncLogger->method('claimEventId')->willReturn(true);

        // The claim is *not* released on success — it stays in the table
        // (upgraded to success=1 via SyncLogger::logInbound's upgradeClaim
        // path) so future retries see a duplicate and 200 cleanly.
        $this->syncLogger->expects($this->never())->method('releaseEventIdClaim');

        $this->runController('evt_ok', 'fulfillment/created', 200);
    }

    public function testDuplicateClaimShortCircuitsTo200(): void
    {
        $this->passSignature();
        $this->resolvesTo($this->anOrder());
        // claimEventId returns false → another worker is processing
        $this->syncLogger->method('claimEventId')->willReturn(false);

        $this->fulfillmentService->expects($this->never())->method('processFulfillment');
        $this->fulfillmentService->expects($this->never())->method('processTrackingUpdate');

        $this->runController('evt_dup', 'fulfillment/created', 200);
    }

    public function testDisabledFulfillmentSyncDoesNotProcess(): void
    {
        $this->signatureVerifier->method('verify')->willReturn(true);
        $this->apiConfig->method('isFulfillmentSyncEnabled')->willReturn(false);

        $this->syncLogger->expects($this->never())->method('claimEventId');
        $this->fulfillmentService->expects($this->never())->method('processFulfillment');

        $this->runController('evt_anything', 'fulfillment/created', 200);
    }

    // ------------------------------------------------- subscription-disable window

    /**
     * Account-wide delivery means most inbound traffic can be for orders that
     * have nothing to do with this store. Acknowledge it, and don't log it —
     * the volume would drown the sync log.
     */
    public function testPayloadWithNoOrderReferenceIsAcknowledgedSilently(): void
    {
        $this->passSignature();
        $this->orderResolver->method('resolve')->willReturn(OrderResolution::noReference());

        $this->syncLogger->expects($this->never())->method('logInbound');
        $this->syncLogger->expects($this->never())->method('claimEventId');
        $this->fulfillmentService->expects($this->never())->method('processFulfillment');

        $this->runController('evt_foreign', 'fulfillment/created', 200);
    }

    /**
     * A reference we couldn't tie to a local order is still a 200 — but it's
     * worth a row, because this is the shape an attempted mis-link takes.
     */
    public function testUnresolvableReferenceIsAcknowledgedButLogged(): void
    {
        $this->passSignature();
        $this->orderResolver->method('resolve')
            ->willReturn(OrderResolution::unresolved('no local order has increment_id 000000009'));

        $this->syncLogger->expects($this->once())
            ->method('logInbound')
            ->with(
                SyncLog::EVENT_WEBHOOK_IGNORED,
                $this->callback(function ($payload) {
                    return is_array($payload)
                        && strpos((string) ($payload['reason'] ?? ''), '000000009') !== false;
                }),
                null,
                null,
                200,
                false
            );
        $this->syncLogger->expects($this->never())->method('claimEventId');
        $this->fulfillmentService->expects($this->never())->method('processFulfillment');

        $this->runController('evt_unknown_order', 'fulfillment/created', 200);
    }

    /**
     * An unhandled topic is not malformed input — rejecting it would burn the
     * subscription's delivery-success budget for no reason.
     */
    public function testUnknownTopicIsAcknowledged(): void
    {
        $this->passSignature();

        $this->syncLogger->expects($this->once())
            ->method('logInbound')
            ->with(SyncLog::EVENT_WEBHOOK_UNKNOWN_TOPIC, $this->anything(), null, null, 200, false);
        $this->orderResolver->expects($this->never())->method('resolve');

        $this->runController('evt_other', 'order/deleted', 200);
    }

    /**
     * A body-level topic must be honoured. If we only ever read headers and Bob
     * Go moves the topic into the body, every delivery 400s and the whole
     * subscription is disabled within three days.
     */
    public function testTopicIsReadFromBodyWhenNoHeaderIsPresent(): void
    {
        $this->passSignature();
        $this->resolvesTo($this->anOrder());
        $this->syncLogger->method('claimEventId')->willReturn(true);

        $this->fulfillmentService->expects($this->once())->method('processTrackingUpdate');

        $body = json_encode([
            'topic' => 'tracking/updated',
            'event_id' => 'evt_body_topic',
            'shipment_tracking_reference' => 'TRK-1',
        ]);

        $this->runControllerWithHeaders(['Bobgo-Webhook-Signature' => 'sig'], (string) $body, 200);
    }

    /**
     * The body's event_id must beat a generic X-Request-Id header. CDNs, load
     * balancers and nginx routinely stamp X-Request-Id with a fresh value per
     * request, so preferring it would give every retry of the same event a
     * different id and quietly defeat dedup entirely.
     */
    public function testBodyEventIdWinsOverGenericRequestIdHeader(): void
    {
        $this->passSignature();
        $this->resolvesTo($this->anOrder());

        $this->syncLogger->expects($this->once())
            ->method('claimEventId')
            ->with('evt_from_body', 'fulfillment/created')
            ->willReturn(true);

        $body = json_encode([
            'topic' => 'fulfillment/created',
            'event_id' => 'evt_from_body',
            'channel_ref_id' => '42',
        ]);

        $this->runControllerWithHeaders(
            [
                'Bobgo-Webhook-Signature' => 'sig',
                'X-Request-Id' => 'proxy-uuid-unique-per-request',
            ],
            (string) $body,
            200
        );
    }

    /**
     * The order id reaches the sync log, so operators can correlate a delivery
     * with the order it touched.
     */
    public function testSuccessRowCarriesTheResolvedOrderId(): void
    {
        $this->passSignature();
        $this->resolvesTo($this->anOrder());
        $this->syncLogger->method('claimEventId')->willReturn(true);

        $this->syncLogger->expects($this->once())
            ->method('logInbound')
            ->with(SyncLog::EVENT_FULFILLMENT_RECEIVED, $this->anything(), 42, 'evt_ok', 200, true);

        $this->runController('evt_ok', 'fulfillment/created', 200);
    }

    // ---------------------------------------------------------------- helpers

    private function passSignature(): void
    {
        $this->signatureVerifier->method('verify')->willReturn(true);
        $this->apiConfig->method('isFulfillmentSyncEnabled')->willReturn(true);
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    private function anOrder()
    {
        $order = $this->createMock(\Magento\Sales\Model\Order::class);
        $order->method('getEntityId')->willReturn(42);
        $order->method('getIncrementId')->willReturn('000000042');
        $order->method('getStoreId')->willReturn(1);
        return $order;
    }

    /**
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     */
    private function resolvesTo($order): void
    {
        $this->orderResolver->method('resolve')->willReturn(OrderResolution::matched($order));
    }

    private function runController(
        string $eventId,
        string $topic,
        int $expectedStatus,
        ?string $rawBody = null
    ): void {
        if ($rawBody === null) {
            $rawBody = (string) json_encode([
                'event_id' => $eventId,
                'channel_ref_id' => '42',
                'channel_order_number' => '000000042',
                'method_reference' => 'TRK-1',
            ]);
        }

        $this->runControllerWithHeaders(
            [
                'Bobgo-Webhook-Signature' => 'sig',
                'Bobgo-Webhook-Event-Id' => $eventId,
                'X-Bobgroup-Topic' => $topic,
            ],
            $rawBody,
            $expectedStatus
        );
    }

    /**
     * Stand up just enough of the controller to exercise execute().
     *
     * @param array<string,string> $headers
     */
    private function runControllerWithHeaders(array $headers, string $rawBody, int $expectedStatus): void
    {
        $request = $this->createMock(\Magento\Framework\App\RequestInterface::class);
        $request->method('getContent')->willReturn($rawBody);
        $request->method('getHeader')->willReturnCallback(static function ($name) use ($headers) {
            return $headers[$name] ?? null;
        });

        $jsonResult = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['setHttpResponseCode', 'setData'])
            ->getMock();
        $actualStatus = 200;
        $jsonResult->method('setHttpResponseCode')->willReturnCallback(
            function ($code) use (&$actualStatus, $jsonResult) {
                $actualStatus = $code;
                return $jsonResult;
            }
        );
        $jsonResult->method('setData')->willReturnSelf();

        $jsonFactory = $this->getMockBuilder(\Magento\Framework\Controller\Result\JsonFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $jsonFactory->method('create')->willReturn($jsonResult);

        $controller = new \BobGroup\BobGo\Controller\Webhook\Receive(
            $this->createMock(\Magento\Framework\App\Action\Context::class),
            $this->fulfillmentService,
            $jsonFactory,
            $this->logger,
            $this->signatureVerifier,
            $this->syncLogger,
            $this->apiConfig,
            $this->orderResolver,
            $this->storeScope
        );

        $reflection = new \ReflectionClass($controller);
        $requestProperty = $reflection->getParentClass()->getProperty('_request');
        $requestProperty->setValue($controller, $request);

        $controller->execute();
        $this->assertSame($expectedStatus, $actualStatus);
    }
}
