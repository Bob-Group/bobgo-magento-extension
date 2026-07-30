<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Service;

use Magento\Framework\App\Area;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Model\App\Emulation;
use Psr\Log\LoggerInterface;

/**
 * Runs work in the store scope of the order it concerns.
 *
 * Everything the extension reads per-store — the API key, the environment, the
 * `bobgo-channel-identifier` header derived from the store base URL — is resolved
 * from the *current* store. That is fine inside a storefront request, and wrong
 * everywhere else:
 *
 *   - cron has no store context, so StoreManager returns the default store;
 *   - the webhook endpoint resolves whichever store the request URL mapped to,
 *     which need not be the store the order belongs to.
 *
 * On a single-store install those coincide and nothing is visibly broken. On a
 * multi-store install an order from store B would be pushed with store A's API
 * key and channel identifier — i.e. into the wrong Bob Go channel. Moving order
 * push onto cron made that reach the outbound create path, not just
 * reconciliation, so it is worth fixing at the boundary rather than per call site.
 *
 * Emulation is the right tool because it fixes config scope and base URL together;
 * stamping the channel on the order at creation time (as the WooCommerce spec
 * suggests) would fix the header but not the credentials.
 */
class StoreScope
{
    private Emulation $emulation;
    private LoggerInterface $logger;

    public function __construct(Emulation $emulation, LoggerInterface $logger)
    {
        $this->emulation = $emulation;
        $this->logger = $logger;
    }

    /**
     * Run $callback with the store scope set to the order's store.
     *
     * Apply this at outermost boundaries only — the cron loop, the webhook
     * router — never nested, because Magento's emulation restores to the scope
     * captured when it started rather than maintaining a stack.
     *
     * @param callable $callback
     * @return mixed Whatever $callback returns
     */
    public function forOrder(OrderInterface $order, callable $callback)
    {
        $storeId = (int) $order->getStoreId();
        if ($storeId <= 0) {
            return $callback();
        }

        try {
            $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
        } catch (\Throwable $e) {
            // Better to do the work in the default scope than not at all — on a
            // single-store install that is the same scope anyway.
            $this->logger->warning('Bob Go: could not emulate the order store scope', [
                'store_id' => $storeId,
                'error' => $e->getMessage(),
            ]);
            return $callback();
        }

        try {
            return $callback();
        } finally {
            try {
                $this->emulation->stopEnvironmentEmulation();
            } catch (\Throwable $e) {
                $this->logger->warning('Bob Go: could not restore the store scope', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
