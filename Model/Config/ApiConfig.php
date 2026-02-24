<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Centralized configuration for the Bob Go API integration.
 *
 * Reads all Bob Go-related settings from Magento's system configuration
 * (carriers/bobgo/* paths) and provides typed accessors for API key,
 * environment, base URL, and feature flags.
 */
class ApiConfig
{
    /** @var string Config path for the encrypted Bob Go API key */
    const XML_PATH_API_KEY = 'carriers/bobgo/api_key';

    /** @var string Config path for the environment selector (sandbox/production) */
    const XML_PATH_ENVIRONMENT = 'carriers/bobgo/environment';

    /** @var string Config path for the order push feature toggle */
    const XML_PATH_ENABLE_ORDER_PUSH = 'carriers/bobgo/enable_order_push';

    /** @var string Config path for the fulfillment sync feature toggle */
    const XML_PATH_ENABLE_FULFILLMENT_SYNC = 'carriers/bobgo/enable_fulfillment_sync';

    /** @var string Config path for customer shipment notification toggle */
    const XML_PATH_NOTIFY_CUSTOMER = 'carriers/bobgo/notify_customer_on_shipment';

    /** @var string Config path for the carrier active toggle (rates at checkout) */
    const XML_PATH_ACTIVE = 'carriers/bobgo/active';

    /** @var string Bob Go API v2 base URL for sandbox environment */
    const BASE_URL_SANDBOX = 'https://api.sandbox.bobgo.co.za/v2/';

    /** @var string Bob Go API v2 base URL for production environment */
    const BASE_URL_PRODUCTION = 'https://api.bobgo.co.za/v2/';

    /** @var string Environment identifier for sandbox */
    const ENV_SANDBOX = 'sandbox';

    /** @var string Environment identifier for production */
    const ENV_PRODUCTION = 'production';

    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;

    /**
     * @var EncryptorInterface
     */
    private EncryptorInterface $encryptor;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param EncryptorInterface $encryptor
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        EncryptorInterface $encryptor
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->encryptor = $encryptor;
    }

    /**
     * Get the decrypted Bob Go API key.
     *
     * @return string|null The API key, or null if not configured
     */
    public function getApiKey(): ?string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_API_KEY, ScopeInterface::SCOPE_STORE);
        if (!is_string($value) || $value === '') {
            return null;
        }
        $decrypted = $this->encryptor->decrypt($value);
        return is_string($decrypted) && $decrypted !== '' ? $decrypted : null;
    }

    /**
     * Get the selected API environment. Defaults to sandbox if not set or unrecognized.
     *
     * @return string Either 'sandbox' or 'production'
     */
    public function getEnvironment(): string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_ENVIRONMENT, ScopeInterface::SCOPE_STORE);
        return $value === self::ENV_PRODUCTION ? self::ENV_PRODUCTION : self::ENV_SANDBOX;
    }

    /**
     * Get the Bob Go API v2 base URL for the currently selected environment.
     *
     * @return string Full base URL with trailing slash (e.g. 'https://api.bobgo.co.za/v2/')
     */
    public function getBaseUrl(): string
    {
        return $this->getEnvironment() === self::ENV_PRODUCTION
            ? self::BASE_URL_PRODUCTION
            : self::BASE_URL_SANDBOX;
    }

    /**
     * Check whether automatic order push to Bob Go is enabled.
     *
     * @return bool
     */
    public function isOrderPushEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLE_ORDER_PUSH, ScopeInterface::SCOPE_STORE);
    }

    /**
     * Check whether fulfillment sync (webhooks + cron polling) is enabled.
     *
     * @return bool
     */
    public function isFulfillmentSyncEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLE_FULFILLMENT_SYNC, ScopeInterface::SCOPE_STORE);
    }

    /**
     * Check whether the customer should receive an email notification when a shipment is created.
     *
     * @return bool
     */
    public function shouldNotifyCustomer(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_NOTIFY_CUSTOMER, ScopeInterface::SCOPE_STORE);
    }

    /**
     * Check whether the API key has been configured (non-empty).
     *
     * @return bool
     */
    public function isConfigured(): bool
    {
        return $this->getApiKey() !== null;
    }

    /**
     * Check whether Bob Go rates at checkout is enabled.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ACTIVE, ScopeInterface::SCOPE_STORE);
    }
}
