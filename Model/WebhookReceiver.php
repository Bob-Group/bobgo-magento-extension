<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Model;

use BobGroup\BobGo\Api\WebhookReceiverInterface;
use BobGroup\BobGo\Service\FulfillmentService;
use Psr\Log\LoggerInterface;

/**
 * Processes incoming Bob Go webhooks by routing them to the appropriate handler.
 *
 * Supports the following topics:
 * - fulfillment/created → Creates a Magento shipment via FulfillmentService
 * - tracking/updated   → Adds tracking numbers to an existing shipment
 *
 * Called via the REST API endpoint POST /rest/V1/bobgo/webhook.
 */
class WebhookReceiver implements WebhookReceiverInterface
{
    /**
     * @var FulfillmentService
     */
    private FulfillmentService $fulfillmentService;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    public function __construct(
        FulfillmentService $fulfillmentService,
        LoggerInterface $logger
    ) {
        $this->fulfillmentService = $fulfillmentService;
        $this->logger = $logger;
    }

    /**
     * @inheritdoc
     */
    public function receive(string $topic, $data): string
    {
        $this->logger->info('Bob Go webhook received', ['topic' => $topic]);

        $payload = is_array($data) ? $data : [];

        try {
            switch ($topic) {
                case 'fulfillment/created':
                    $this->fulfillmentService->processFulfillment($payload);
                    return 'fulfillment processed';

                case 'tracking/updated':
                    $this->fulfillmentService->processTrackingUpdate($payload);
                    return 'tracking update processed';

                default:
                    $this->logger->warning('Bob Go webhook: unknown topic', ['topic' => $topic]);
                    return 'unknown topic';
            }
        } catch (\Exception $e) {
            $this->logger->error('Bob Go webhook processing failed', [
                'topic' => $topic,
                'error' => $e->getMessage(),
            ]);
            return 'error processing webhook';
        }
    }
}
