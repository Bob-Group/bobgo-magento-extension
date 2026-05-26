<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Service;

/**
 * Thrown by webhook processing services when the failure is transient and
 * Bob Go should retry. The controller catches this and returns HTTP 500;
 * Bob Go's retry policy will redeliver the same event_id.
 *
 * Permanent failures (order not found, can't ship — i.e. nothing Bob Go
 * can fix by retrying) are NOT thrown — those return 200 so Bob Go marks
 * the delivery as accepted instead of cycling it through retries.
 */
class TransientWebhookException extends \RuntimeException
{
}
