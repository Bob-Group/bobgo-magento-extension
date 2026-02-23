<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Api;

interface WebhookReceiverInterface
{
    /**
     * Receive and process a webhook from Bob Go
     *
     * @param string $topic The webhook topic (e.g., fulfillment/created, tracking/updated)
     * @param mixed $data The webhook payload data
     * @return string Response message
     */
    public function receive(string $topic, $data): string;
}
