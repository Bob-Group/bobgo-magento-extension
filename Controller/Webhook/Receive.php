<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Controller\Webhook;

use BobGroup\BobGo\Model\Config\ApiConfig;
use BobGroup\BobGo\Model\SyncLog;
use BobGroup\BobGo\Service\FulfillmentService;
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
 *   2. Verify HMAC-SHA256 of the body against the Bobgo-Webhook-Signature header
 *      using the merchant-issued webhook secret. Constant-time compare. 403 on any
 *      mismatch or when the secret isn't configured — we never process unverified bodies.
 *   3. Decode JSON, resolve topic (header → payload-shape fallback).
 *   4. Dedup by event_id via SyncLogger so retried deliveries are 200'd, not reprocessed.
 *   5. Route to the appropriate handler. Every outcome is recorded in bobgo_sync_log.
 *
 * Route: POST /bobgo/webhook/receive
 */
class Receive extends Action implements CsrfAwareActionInterface
{
    private const TOPIC_HEADERS = [
        'X-Bobgroup-Topic',
        'X-BobGo-Topic',
        'X-Webhook-Topic',
        'X-Topic',
    ];

    private const SIGNATURE_HEADER = 'Bobgo-Webhook-Signature';
    private const EVENT_ID_HEADER  = 'Bobgo-Webhook-Event-Id';

    private FulfillmentService $fulfillmentService;
    private JsonFactory $jsonFactory;
    private LoggerInterface $logger;
    private WebhookSignatureVerifier $signatureVerifier;
    private SyncLogger $syncLogger;
    private ApiConfig $apiConfig;

    public function __construct(
        Context $context,
        FulfillmentService $fulfillmentService,
        JsonFactory $jsonFactory,
        LoggerInterface $logger,
        WebhookSignatureVerifier $signatureVerifier,
        SyncLogger $syncLogger,
        ApiConfig $apiConfig
    ) {
        parent::__construct($context);
        $this->fulfillmentService = $fulfillmentService;
        $this->jsonFactory = $jsonFactory;
        $this->logger = $logger;
        $this->signatureVerifier = $signatureVerifier;
        $this->syncLogger = $syncLogger;
        $this->apiConfig = $apiConfig;
    }

    /** Cap on how much of a rejected body we persist — anyone who fails signature
     *  verification can spray 64 KB requests at us, so we keep just enough to
     *  diagnose the rejection without giving them a free log-bloat vector. */
    private const REJECTED_BODY_CAP_BYTES = 256;

    public function execute()
    {
        $result = $this->jsonFactory->create();
        $request = $this->getRequest();
        $rawBody = (string) $request->getContent();

        // 1. Signature verification — first gate. Never inspect the body before this.
        $providedSignature = $request->getHeader(self::SIGNATURE_HEADER);
        if (!$this->signatureVerifier->verify($rawBody, is_string($providedSignature) ? $providedSignature : null)) {
            $this->syncLogger->logInbound(
                SyncLog::EVENT_WEBHOOK_REJECTED,
                substr($rawBody, 0, self::REJECTED_BODY_CAP_BYTES),
                null,
                $this->getEventId($request),
                403,
                false
            );
            return $result->setHttpResponseCode(403)->setData(['error' => 'Invalid signature']);
        }

        // 2. Parse body
        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            $this->syncLogger->logInbound(
                SyncLog::EVENT_WEBHOOK_REJECTED,
                substr($rawBody, 0, self::REJECTED_BODY_CAP_BYTES),
                null,
                $this->getEventId($request),
                400,
                false
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

        $topic = $this->resolveTopicFromHeaders($request) ?? $this->inferTopicFromPayload($data);
        $eventId = $this->getEventId($request) ?? ($data['event_id'] ?? null);
        if ($eventId !== null) {
            $eventId = (string) $eventId;
        }

        $this->logger->info('Bob Go webhook received', [
            'topic' => $topic ?? 'unknown',
            'event_id' => $eventId,
            'channel_order_number' => $data['channel_order_number'] ?? null,
        ]);

        if ($topic === null) {
            $this->syncLogger->logInbound(
                SyncLog::EVENT_WEBHOOK_REJECTED,
                $data,
                null,
                $eventId,
                400,
                false
            );
            return $result->setHttpResponseCode(400)->setData(['error' => 'Could not determine webhook topic']);
        }

        // 3. Idempotency — atomic claim. claimEventId() writes a sentinel
        // success row under a unique (event_id, direction) index; if a
        // concurrent delivery already claimed it, we 200 without processing.
        if (!$this->syncLogger->claimEventId($eventId, $topic)) {
            $this->logger->info('Bob Go webhook: duplicate event_id, acknowledging', [
                'event_id' => $eventId,
                'topic' => $topic,
            ]);
            return $result->setHttpResponseCode(200)->setData(['message' => 'duplicate, ignored']);
        }

        // 4. Route
        try {
            switch ($topic) {
                case 'fulfillment/created':
                    $this->fulfillmentService->processFulfillment($data);
                    $this->syncLogger->logInbound(SyncLog::EVENT_FULFILLMENT_RECEIVED, $data, null, $eventId, 200, true);
                    return $result->setData(['message' => 'fulfillment processed']);

                case 'tracking/updated':
                    $this->fulfillmentService->processTrackingUpdate($data);
                    $this->syncLogger->logInbound(SyncLog::EVENT_TRACKING_UPDATED, $data, null, $eventId, 200, true);
                    return $result->setData(['message' => 'tracking update processed']);

                default:
                    $this->logger->warning('Bob Go webhook: unknown topic', ['topic' => $topic]);
                    $this->syncLogger->logInbound(SyncLog::EVENT_WEBHOOK_UNKNOWN_TOPIC, $data, null, $eventId, 200, false);
                    return $result->setData(['message' => 'unknown topic, ignored']);
            }
        } catch (TransientWebhookException $e) {
            // Transient failure — release the dedup claim and log the
            // failure WITHOUT event_id so the unique (event_id, direction)
            // slot stays free for Bob Go's retry to re-claim. We embed
            // the event_id in the payload so operators can still trace it.
            $this->syncLogger->releaseEventIdClaim($eventId);
            $this->logger->error('Bob Go webhook processing failed (transient, will retry)', [
                'topic' => $topic,
                'event_id' => $eventId,
                'error' => $e->getMessage(),
            ]);
            $this->syncLogger->logInbound(
                SyncLog::EVENT_WEBHOOK_RECEIVED,
                ['event_id' => $eventId, 'topic' => $topic, 'data' => $data, 'error' => $e->getMessage()],
                null,
                null, // intentionally null — do NOT re-occupy the dedup slot
                500,
                false
            );
            return $result->setHttpResponseCode(500)->setData(['error' => 'Processing failed']);
        } catch (\Throwable $e) {
            // Permanent / unexpected. We DON'T release the claim — the row
            // stays as a marker so retries from Bob Go are short-circuited.
            // A 500 is returned for visibility (so the operator notices),
            // but Bob Go's subsequent retries will be 200'd at the dedup
            // check rather than re-running broken code.
            $this->logger->error('Bob Go webhook processing failed (unexpected)', [
                'topic' => $topic,
                'event_id' => $eventId,
                'error' => $e->getMessage(),
            ]);
            // Same rule as the transient branch: don't log with event_id,
            // because the claim row already holds the slot.
            $this->syncLogger->logInbound(
                SyncLog::EVENT_WEBHOOK_RECEIVED,
                ['event_id' => $eventId, 'topic' => $topic, 'data' => $data, 'error' => $e->getMessage()],
                null,
                null,
                500,
                false
            );
            return $result->setHttpResponseCode(500)->setData(['error' => 'Processing failed']);
        }
    }

    private function getEventId(RequestInterface $request): ?string
    {
        $value = $request->getHeader(self::EVENT_ID_HEADER);
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function resolveTopicFromHeaders(RequestInterface $request): ?string
    {
        foreach (self::TOPIC_HEADERS as $headerName) {
            $value = $request->getHeader($headerName);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        return null;
    }

    /**
     * Fallback topic resolution when no header is present.
     *
     * @param array<string,mixed> $data
     */
    private function inferTopicFromPayload(array $data): ?string
    {
        if (isset($data['shipment_tracking_reference']) || isset($data['checkpoints'])) {
            return 'tracking/updated';
        }
        if (isset($data['method_reference']) && isset($data['order_items'])) {
            return 'fulfillment/created';
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
