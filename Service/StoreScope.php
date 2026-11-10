<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Service;

use Magento\Framework\App\Area;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
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
    private StoreManagerInterface $storeManager;
    private LoggerInterface $logger;

    public function __construct(
        Emulation $emulation,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger
    ) {
        $this->emulation = $emulation;
        $this->storeManager = $storeManager;
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

        $before = $this->currentStoreId();

        try {
            // force = false on purpose. Magento's own guard is
            // `if ($storeId == currentStoreId && !$force) return;` — so passing
            // true only buys the right to emulate a store we are already in, which
            // is every order on a single-store install. Emulation reloads the
            // theme, locale and translations, so paying that per order in a
            // 50-order batch for no change of scope is pure waste.
            $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, false);
        } catch (\Throwable $e) {
            // Better to do the work in the default scope than not at all — on a
            // single-store install that is the same scope anyway.
            $this->logger->warning('Bob Go: could not emulate the order store scope', [
                'store_id' => $storeId,
                'error' => $e->getMessage(),
            ]);
            return $callback();
        }

        // Did emulation actually take effect? Magento returns void from every
        // path, so the store id is the only signal. This matters twice: it skips a
        // pointless stop() when start() short-circuited on the same store, and it
        // stops us tearing down an OUTER emulation if this ever gets nested —
        // Magento allows only one level, and a nested start() logs an error and
        // returns while leaving the outer state in place, which an unconditional
        // stop() would then restore out from under it.
        $emulated = $this->currentStoreId() !== $before;

        try {
            return $callback();
        } finally {
            if ($emulated) {
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

    private function currentStoreId(): ?int
    {
        try {
            return (int) $this->storeManager->getStore()->getId();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
