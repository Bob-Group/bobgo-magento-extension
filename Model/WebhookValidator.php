<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Model;

class WebhookValidator
{
    /**
     * Validate that the webhook payload has the required structure
     *
     * @param array<string,mixed> $payload
     * @return bool
     */
    public function validate(array $payload): bool
    {
        return isset($payload['topic']) && isset($payload['data']);
    }
}
