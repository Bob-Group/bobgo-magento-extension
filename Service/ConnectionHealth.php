<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Service;

use Magento\Framework\FlagManager;
use Psr\Log\LoggerInterface;

/**
 * Tracks whether the stored credentials actually work.
 *
 * "An API key is saved" is not the same as "we can talk to Bob Go", and the
 * difference is exactly what a merchant needs to see. A key revoked or rotated on
 * the Bob Go side leaves the config page looking perfectly healthy while every
 * call 401s.
 *
 * So the state is written through from real traffic rather than from a button:
 *
 *   any 2xx                     -> valid
 *   401                         -> invalid
 *   anything else (404/5xx/     -> inconclusive; leave the last state alone
 *   timeout/transport)
 *
 * That last rule matters. A 404 means the key is fine but the channel isn't
 * enrolled; a timeout means we learned nothing at all. Treating either as
 * "invalid" would have the config page cry wolf every time Bob Go had a blip.
 *
 * Only transitions are written, so this costs nothing on the hot path.
 */
class ConnectionHealth
{
    public const STATE_UNKNOWN = 'unknown';
    public const STATE_VALID = 'valid';
    public const STATE_INVALID = 'invalid';

    private const STATE_FLAG = 'bobgo_connection_state';
    private const CHECKED_FLAG = 'bobgo_connection_checked_at';

    private FlagManager $flagManager;
    private LoggerInterface $logger;

    public function __construct(FlagManager $flagManager, LoggerInterface $logger)
    {
        $this->flagManager = $flagManager;
        $this->logger = $logger;
    }

    /**
     * Fold an observed HTTP status into the health state.
     *
     * @param int $statusCode 0 for a transport failure that produced no response
     */
    public function observe(int $statusCode): void
    {
        if ($statusCode >= 200 && $statusCode < 300) {
            $this->transitionTo(self::STATE_VALID);
            return;
        }

        if ($statusCode === 401) {
            $this->transitionTo(self::STATE_INVALID);
            return;
        }

        // Inconclusive. Deliberately no write: we did not learn anything about
        // the credentials, and overwriting a known-good state with "unknown"
        // would lose information.
    }

    public function getState(): string
    {
        $state = $this->flagManager->getFlagData(self::STATE_FLAG);
        return in_array($state, [self::STATE_VALID, self::STATE_INVALID], true)
            ? (string) $state
            : self::STATE_UNKNOWN;
    }

    /**
     * When the state last changed, as a UTC 'Y-m-d H:i:s' string.
     */
    public function getCheckedAt(): ?string
    {
        $value = $this->flagManager->getFlagData(self::CHECKED_FLAG);
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private function transitionTo(string $state): void
    {
        if ($this->getState() === $state) {
            return;
        }

        try {
            $this->flagManager->saveFlag(self::STATE_FLAG, $state);
            $this->flagManager->saveFlag(self::CHECKED_FLAG, gmdate('Y-m-d H:i:s'));
            $this->logger->info('Bob Go: connection state changed', ['state' => $state]);
        } catch (\Throwable $e) {
            $this->logger->warning('Bob Go: could not record the connection state', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
