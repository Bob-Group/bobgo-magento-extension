<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Controller\Webhook;

use BobGroup\BobGo\Model\Config\ApiConfig;
use BobGroup\BobGo\Model\SyncLog;
use BobGroup\BobGo\Service\FulfillmentService;
use BobGroup\BobGo\Service\OrderResolution;
use BobGroup\BobGo\Service\InboundGuard;
use BobGroup\BobGo\Service\OrderResolver;
use BobGroup\BobGo\Service\StoreScope;
use BobGroup\BobGo\Service\SyncLogger;
use BobGroup\BobGo\Service\TransientWebhookException;
use BobGroup\BobGo\Service\WebhookSignatureVerifier;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Psr\Log\LoggerInterface;

/**
 * Unified webhook controller for all Bob Go webhook events.
 *
 * Inbound flow:
 *   1. Read raw body (signature is computed over this exact byte sequence).
 *   2. Verify HMAC-SHA256 against the Bobgo-Webhook-Signature header using the
 *      merchant-issued secret. Constant-time compare. 403 on any mismatch or
 *      when the secret isn't configured — we never process unverified bodies.
 *   3. Decode JSON, resolve topic (body → header → payload shape).
 *   4. Resolve the payload to a local order (see OrderResolver).
 *   5. Dedup by event_id via an atomic claim, then route to a handler.
 *
 * RESPONSE POLICY — the most important thing in this class.
 *
 * Bob Go's delivery layer counts ANY non-2xx as a delivery failure and disables
 * the entire subscription after three days without a success. Only the merchant
 * is emailed; we are never told. And subscriptions are account-wide, so this
 * endpoint receives every event on the merchant's Bob Go account — manual
 * shipments, CSV imports, other channels' orders. A quiet trading period in
 * which foreign traffic is the only traffic is therefore enough to silently
 * kill fulfilment sync for the whole store.
 *
 * So 4xx/5xx is reserved for input that is genuinely malformed or unauthentic,
 * and for our own transient failures. "Not one of ours" is a 200:
 *
 *   processed                                    200
 *   signature missing/invalid                    403
 *   unparseable body / no resolvable topic       400
 *   known topic, no order reference at all       200 ignored, no log row
 *   known topic, reference matched nothing       200 ignored, log row kept
 *   unknown topic                                200 ignored, log row kept
 *   our own transient failure                    500 (Bob Go retries)
 *
 * Route: POST /bobgo/webhook/receive
 */
class Receive extends Action implements CsrfAwareActionInterface
{
    /**
     * Header names carrying the topic, in preference order. The body's own
     * `topic` field is checked first — that is Bob Go's primary channel, and
     * relying on headers alone risks 400-ing everything if they change.
     */
    private const TOPIC_HEADERS = [
        'X-Bobgroup-Topic',
        'X-BobGo-Topic',
        'X-Webhook-Topic',
        'X-Topic',
    ];

    /**
     * Header names carrying the delivery's unique event id, in preference order.
     *
     * The body's own `event_id` wins over all of these. That ordering matters:
     * X-Request-Id is a generic header that CDNs, load balancers and nginx
     * commonly stamp on every inbound request with a fresh value. Preferring it
     * would give each retry of the same event a different id, quietly defeating
     * dedup. It stays only as a last resort, below the two Bob Go-specific names.
     */
    private const EVENT_ID_HEADERS = [
        'Bobgo-Webhook-Event-Id',
        'Bob-Go-Request-Id',
        'X-Request-Id',
    ];

    private const SIGNATURE_HEADER = 'Bobgo-Webhook-Signature';

    /** Topics we act on. Anything else is acknowledged and logged, not rejected. */
    private const KNOWN_TOPICS = [
        OrderResolver::TOPIC_FULFILLMENT_CREATED,
        OrderResolver::TOPIC_TRACKING_UPDATED,
        OrderResolver::TOPIC_ORDER_UPDATED,
    ];

    /** Cap on how much of a rejected body we persist — anyone who fails signature
     *  verification can spray 64 KB requests at us, so we keep just enough to
     *  diagnose the rejection without giving them a free log-bloat vector. */
    private const REJECTED_BODY_CAP_BYTES = 256;

    private FulfillmentService $fulfillmentService;
    private JsonFactory $jsonFactory;
    private LoggerInterface $logger;
    private WebhookSignatureVerifier $signatureVerifier;
    private SyncLogger $syncLogger;
    private ApiConfig $apiConfig;
    private OrderResolver $orderResolver;
    private StoreScope $storeScope;
    private InboundGuard $inboundGuard;

    public function __construct(
        Context $context,
        FulfillmentService $fulfillmentService,
        JsonFactory $jsonFactory,
        LoggerInterface $logger,
        WebhookSignatureVerifier $signatureVerifier,
        SyncLogger $syncLogger,
        ApiConfig $apiConfig,
        OrderResolver $orderResolver,
        StoreScope $storeScope,
        InboundGuard $inboundGuard
    ) {
        parent::__construct($context);
        $this->fulfillmentService = $fulfillmentService;
        $this->jsonFactory = $jsonFactory;
        $this->logger = $logger;
        $this->signatureVerifier = $signatureVerifier;
        $this->syncLogger = $syncLogger;
        $this->apiConfig = $apiConfig;
        $this->orderResolver = $orderResolver;
        $this->storeScope = $storeScope;
        $this->inboundGuard = $inboundGuard;
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        $request = $this->getRequest();
        $rawBody = (string) $request->getContent();

        // Headers only for now — the body must not be parsed before the
        // signature is verified. Upgraded to the body's own event_id below.
        $eventId = $this->getEventIdFromHeaders($request);

        // 1. Signature verification — first gate. Never inspect the body before this.
        $providedSignature = $request->getHeader(self::SIGNATURE_HEADER);
        if (!$this->signatureVerifier->verify($rawBody, is_string($providedSignature) ? $providedSignature : null)) {
            $this->logWithoutClaimingEventId(
                SyncLog::EVENT_WEBHOOK_REJECTED,
                substr($rawBody, 0, self::REJECTED_BODY_CAP_BYTES),
                $eventId,
                403
            );
            return $result->setHttpResponseCode(403)->setData(['error' => 'Invalid signature']);
        }

        // 2. Parse body
        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            $this->logWithoutClaimingEventId(
                SyncLog::EVENT_WEBHOOK_REJECTED,
                substr($rawBody, 0, self::REJECTED_BODY_CAP_BYTES),
                $eventId,
                400
            );
            return $result->setHttpResponseCode(400)->setData(['error' => 'Invalid JSON']);
        }

        // After signature: gate on fulfillment_sync_enabled. If a merchant has
        // disabled sync but a stale subscription is still firing, the body is
        // authentic (signature passed) but we must not mutate orders. 200 so
        // Bob Go doesn't retry — operator intent is "stop processing".
        if (!$this->apiConfig->isFulfillmentSyncEnabled()) {
            $this->logger->info('Bob Go webhook: fulfillment sync disabled, ignoring');
            return $result->setHttpResponseCode(200)->setData(['message' => 'fulfillment sync disabled']);
        }

        // 3. Topic and event id. The body is authoritative for both.
        $topic = $this->resolveTopic($request, $data);
        $eventId = $this->normaliseEventId($data['event_id'] ?? null) ?? $eventId;

        if ($topic === null) {
            $this->logWithoutClaimingEventId(SyncLog::EVENT_WEBHOOK_REJECTED, $data, $eventId, 400);
            return $result->setHttpResponseCode(400)->setData(['error' => 'Could not determine webhook topic']);
        }

        if (!in_array($topic, self::KNOWN_TOPICS, true)) {
            // Not malformed, just not ours to handle. Acknowledge — a 4xx here
            // would count against the subscription-disable window.
            $this->logger->warning('Bob Go webhook: unknown topic', ['topic' => $topic]);
            $this->logWithoutClaimingEventId(
                SyncLog::EVENT_WEBHOOK_UNKNOWN_TOPIC,
                $data,
                $eventId,
                200,
                null,
                sprintf('topic "%s" is not handled by this integration', $topic)
            );
            return $result->setData(['message' => 'unknown topic, ignored']);
        }

        // 4. Which local order is this about? Resolution happens before the
        // dedup claim so that routine foreign traffic leaves no trace at all.
        $resolution = $this->orderResolver->resolve($data, $topic);

        if (!$resolution->hasReference()) {
            // No order reference whatsoever. Under account-wide delivery this is
            // ordinary background noise (standalone shipments, other channels).
            // Deliberately not logged: the volume would drown the sync log.
            return $result->setData(['status' => 'ignored']);
        }

        if (!$resolution->isMatched()) {
            $this->logger->info('Bob Go webhook: no local order matched, acknowledging', [
                'topic' => $topic,
                'event_id' => $eventId,
                'reason' => $resolution->getReason(),
            ]);
            $this->logWithoutClaimingEventId(
                SyncLog::EVENT_WEBHOOK_IGNORED,
                $data,
                $eventId,
                200,
                null,
                $resolution->getReason()
            );
            return $result->setData(['status' => 'ignored', 'reason' => $resolution->getReason()]);
        }

        $order = $resolution->getOrder();
        $orderId = (int) $order->getEntityId();

        $this->logger->info('Bob Go webhook received', [
            'topic' => $topic,
            'event_id' => $eventId,
            'order_id' => $orderId,
            'increment_id' => $order->getIncrementId(),
        ]);

        // 5. Idempotency — atomic claim. claimEventId() writes a sentinel row
        // under a unique (event_id, direction) index; if a concurrent delivery
        // already claimed it, we 200 without processing.
        if (!$this->syncLogger->claimEventId($eventId, $topic)) {
            $this->logger->info('Bob Go webhook: duplicate event_id, acknowledging', [
                'event_id' => $eventId,
                'topic' => $topic,
            ]);
            return $result->setHttpResponseCode(200)->setData(['message' => 'duplicate, ignored']);
        }

        // 6. Route, in the order's own store scope. Webhook subscriptions carry a
        // single delivery URL, so every delivery arrives in whichever store that
        // URL resolves to — not necessarily the store the order belongs to. Without
        // this, a multi-store order would be refreshed using another store's API
        // key and channel identifier.
        try {
            return $this->storeScope->forOrder($order, function () use ($topic, $order, $data, $eventId, $result, $orderId) {
                // Mark the order inbound-driven for the duration, so the saves the
                // handlers make don't queue an outbound push of Bob Go's own change.
                return $this->inboundGuard->around($orderId, function () use ($topic, $order, $data, $eventId, $result) {
                    return $this->route($topic, $order, $data, $eventId, $result);
                });
            });
        } catch (TransientWebhookException $e) {
            // Our fault or a race, and retrying can fix it: release the dedup
            // claim so Bob Go's retry can re-claim, and 500 so it retries.
            $this->syncLogger->releaseEventIdClaim($eventId);
            $this->logger->error('Bob Go webhook processing failed (transient, will retry)', [
                'topic' => $topic,
                'event_id' => $eventId,
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
            $this->logWithoutClaimingEventId(
                SyncLog::EVENT_WEBHOOK_RECEIVED,
                $data,
                $eventId,
                500,
                $orderId,
                $e->getMessage()
            );
            return $result->setHttpResponseCode(500)->setData(['error' => 'Processing failed']);
        } catch (\Throwable $e) {
            // Unexpected. We DON'T release the claim — the row stays as a marker
            // so retries are short-circuited at the dedup gate rather than
            // re-running broken code. A 500 makes the operator notice; an
            // operator clears the claim row to allow a replay after fixing it.
            $this->logger->error('Bob Go webhook processing failed (unexpected)', [
                'topic' => $topic,
                'event_id' => $eventId,
                'order_id' => $orderId,
                'exception' => get_class($e),
                'error' => $e->getMessage(),
            ]);
            $this->logWithoutClaimingEventId(
                SyncLog::EVENT_WEBHOOK_RECEIVED,
                $data,
                $eventId,
                500,
                $orderId,
                $e->getMessage()
            );
            return $result->setHttpResponseCode(500)->setData(['error' => 'Processing failed']);
        }
    }

    /**
     * Dispatch to the handler for this topic and record the outcome.
     *
     * @param array<string,mixed> $data
     * @param mixed $result The JSON result to populate (Controller\Result\Json)
     * @return mixed The same result, populated
     * @throws TransientWebhookException
     */
    private function route(
        string $topic,
        \Magento\Sales\Api\Data\OrderInterface $order,
        array $data,
        ?string $eventId,
        $result
    ) {
        $orderId = (int) $order->getEntityId();

        switch ($topic) {
                case OrderResolver::TOPIC_FULFILLMENT_CREATED:
                    $this->fulfillmentService->processFulfillment($order, $data);
                    $this->syncLogger->logInbound(
                        SyncLog::EVENT_FULFILLMENT_RECEIVED,
                        $data,
                        $orderId,
                        $eventId,
                        200,
                        true
                    );
                    return $result->setData(['message' => 'fulfillment processed']);

                case OrderResolver::TOPIC_TRACKING_UPDATED:
                    $this->fulfillmentService->processTrackingUpdate($order, $data);
                    $this->syncLogger->logInbound(
                        SyncLog::EVENT_TRACKING_UPDATED,
                        $data,
                        $orderId,
                        $eventId,
                        200,
                        true
                    );
                    return $result->setData(['message' => 'tracking update processed']);

                default:
                    // order/updated — Bob Go sends the full order object and
                    // re-fires on any relevant change, so the handler is
                    // idempotent and acts only on cancellation. Other fields are
                    // still left alone deliberately: the store owns the order.
                    $this->fulfillmentService->processOrderUpdate($order, $data);
                    $this->syncLogger->logInbound(
                        SyncLog::EVENT_ORDER_UPDATED_INBOUND,
                        $data,
                        $orderId,
                        $eventId,
                        200,
                        true
                    );
                    return $result->setData(['message' => 'order update acknowledged']);
            }
    }

    /**
     * Record an outcome that must NOT occupy the (event_id, direction) dedup slot.
     *
     * Anything written with a non-null event_id claims that slot permanently. If
     * a rejection claimed it, the legitimate redelivery of the same event would
     * fail claimEventId() and be answered "duplicate, ignored" — dropped for
     * good. The classic trigger is a merchant enabling fulfilment sync before
     * pasting the webhook secret: every delivery in between is 403'd, and
     * without this rule none of those retries can ever land.
     *
     * The event id still travels in the payload, so operators can trace it.
     *
     * @param array<string,mixed>|string|null $body
     */
    private function logWithoutClaimingEventId(
        string $eventType,
        $body,
        ?string $eventId,
        ?int $httpStatus,
        ?int $orderId = null,
        ?string $reason = null
    ): void {
        $payload = ['event_id' => $eventId, 'body' => $body];
        if ($reason !== null) {
            $payload['reason'] = $reason;
        }
        $this->syncLogger->logInbound($eventType, $payload, $orderId, null, $httpStatus, false);
    }

    private function getEventIdFromHeaders(RequestInterface $request): ?string
    {
        foreach (self::EVENT_ID_HEADERS as $headerName) {
            $value = $this->normaliseEventId($request->getHeader($headerName));
            if ($value !== null) {
                return $value;
            }
        }
        return null;
    }

    /**
     * @param mixed $value
     */
    private function normaliseEventId($value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    /**
     * Topic from the body, then the headers, then the payload's shape.
     *
     * @param array<string,mixed> $data
     */
    private function resolveTopic(RequestInterface $request, array $data): ?string
    {
        $fromBody = $data['topic'] ?? null;
        if (is_string($fromBody) && trim($fromBody) !== '') {
            return trim($fromBody);
        }

        foreach (self::TOPIC_HEADERS as $headerName) {
            $value = $request->getHeader($headerName);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return $this->inferTopicFromPayload($data);
    }

    /**
     * Last-resort topic resolution from the payload's shape.
     *
     * @param array<string,mixed> $data
     */
    private function inferTopicFromPayload(array $data): ?string
    {
        if (isset($data['shipment_tracking_reference']) || isset($data['checkpoints'])) {
            return OrderResolver::TOPIC_TRACKING_UPDATED;
        }
        if (isset($data['method_reference']) && isset($data['order_items'])) {
            return OrderResolver::TOPIC_FULFILLMENT_CREATED;
        }
        return null;
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
