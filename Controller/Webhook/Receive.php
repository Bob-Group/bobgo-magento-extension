<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Controller\Webhook;

use BobGroup\BobGo\Service\FulfillmentService;
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
 * Bob Go sends all webhook topics to the same delivery URL and includes
 * the topic in an HTTP header. This controller reads the topic header,
 * parses the flat JSON body, and routes to the appropriate handler.
 *
 * Supported topic headers (checked in order):
 *   - X-BobGo-Topic
 *   - X-Webhook-Topic
 *
 * Route: POST /bobgo/webhook/receive
 */
class Receive extends Action implements CsrfAwareActionInterface
{
    /**
     * Topic header names to check, in priority order.
     */
    private const TOPIC_HEADERS = [
        'X-BobGo-Topic',
        'X-Webhook-Topic',
        'X-Topic',
    ];

    /**
     * @var FulfillmentService
     */
    private FulfillmentService $fulfillmentService;

    /**
     * @var JsonFactory
     */
    private JsonFactory $jsonFactory;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    public function __construct(
        Context $context,
        FulfillmentService $fulfillmentService,
        JsonFactory $jsonFactory,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->fulfillmentService = $fulfillmentService;
        $this->jsonFactory = $jsonFactory;
        $this->logger = $logger;
    }

    /**
     * @inheritdoc
     */
    public function execute()
    {
        $result = $this->jsonFactory->create();
        $request = $this->getRequest();

        $body = $request->getContent();
        $data = json_decode($body, true);

        if (!is_array($data)) {
            $this->logger->error('Bob Go webhook: invalid JSON body');
            return $result->setHttpResponseCode(400)->setData(['error' => 'Invalid JSON']);
        }

        // Determine topic from headers
        $topic = $this->resolveTopicFromHeaders($request);

        // If no header found, try to infer from payload structure
        if ($topic === null) {
            $topic = $this->inferTopicFromPayload($data);
        }

        $this->logger->info('Bob Go webhook received', [
            'topic' => $topic ?? 'unknown',
            'channel_order_number' => $data['channel_order_number'] ?? 'unknown',
        ]);

        if ($topic === null) {
            $this->logger->warning('Bob Go webhook: could not determine topic', [
                'headers' => $this->getAllTopicHeaders($request),
                'payload_keys' => array_keys($data),
            ]);
            return $result->setHttpResponseCode(400)->setData(['error' => 'Could not determine webhook topic']);
        }

        try {
            switch ($topic) {
                case 'fulfillment/created':
                    $this->fulfillmentService->processFulfillment($data);
                    return $result->setData(['message' => 'fulfillment processed']);

                case 'tracking/updated':
                    $this->fulfillmentService->processTrackingUpdate($data);
                    return $result->setData(['message' => 'tracking update processed']);

                default:
                    $this->logger->warning('Bob Go webhook: unknown topic', ['topic' => $topic]);
                    return $result->setData(['message' => 'unknown topic, ignored']);
            }
        } catch (\Exception $e) {
            $this->logger->error('Bob Go webhook processing failed', [
                'topic' => $topic,
                'error' => $e->getMessage(),
            ]);
            return $result->setHttpResponseCode(500)->setData(['error' => 'Processing failed']);
        }
    }

    /**
     * Check known topic header names and return the first match.
     *
     * @param RequestInterface $request
     * @return string|null
     */
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
     * Infer the webhook topic from payload structure when no header is present.
     *
     * Fulfillment payloads have: method, method_reference, order_items
     * Tracking payloads have: shipment_tracking_reference, checkpoints, tracking_steps
     *
     * @param array<string,mixed> $data
     * @return string|null
     */
    private function inferTopicFromPayload(array $data): ?string
    {
        // Tracking update: has shipment_tracking_reference or checkpoints
        if (isset($data['shipment_tracking_reference']) || isset($data['checkpoints'])) {
            return 'tracking/updated';
        }

        // Fulfillment: has method_reference and order_items
        if (isset($data['method_reference']) && isset($data['order_items'])) {
            return 'fulfillment/created';
        }

        return null;
    }

    /**
     * Get all topic-related headers for debug logging.
     *
     * @param RequestInterface $request
     * @return array<string,string>
     */
    private function getAllTopicHeaders(RequestInterface $request): array
    {
        $headers = [];
        foreach (self::TOPIC_HEADERS as $headerName) {
            $value = $request->getHeader($headerName);
            if (is_string($value) && $value !== '') {
                $headers[$headerName] = $value;
            }
        }
        return $headers;
    }

    /**
     * @inheritdoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * @inheritdoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
