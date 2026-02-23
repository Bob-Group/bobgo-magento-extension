<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class ApiConfig
{
    const XML_PATH_API_KEY = 'carriers/bobgo/api_key';
    const XML_PATH_ENVIRONMENT = 'carriers/bobgo/environment';
    const XML_PATH_ENABLE_ORDER_PUSH = 'carriers/bobgo/enable_order_push';
    const XML_PATH_ENABLE_FULFILLMENT_SYNC = 'carriers/bobgo/enable_fulfillment_sync';
    const XML_PATH_NOTIFY_CUSTOMER = 'carriers/bobgo/notify_customer_on_shipment';
    const XML_PATH_ACTIVE = 'carriers/bobgo/active';

    const BASE_URL_SANDBOX = 'https://api.sandbox.bobgo.co.za/v2/';
    const BASE_URL_PRODUCTION = 'https://api.bobgo.co.za/v2/';

    const ENV_SANDBOX = 'sandbox';
    const ENV_PRODUCTION = 'production';

    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;

    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->scopeConfig = $scopeConfig;
    }

    public function getApiKey(): ?string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_API_KEY, ScopeInterface::SCOPE_STORE);
        return is_string($value) && $value !== '' ? $value : null;
    }

    public function getEnvironment(): string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_ENVIRONMENT, ScopeInterface::SCOPE_STORE);
        return $value === self::ENV_PRODUCTION ? self::ENV_PRODUCTION : self::ENV_SANDBOX;
    }

    public function getBaseUrl(): string
    {
        return $this->getEnvironment() === self::ENV_PRODUCTION
            ? self::BASE_URL_PRODUCTION
            : self::BASE_URL_SANDBOX;
    }

    public function isOrderPushEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLE_ORDER_PUSH, ScopeInterface::SCOPE_STORE);
    }

    public function isFulfillmentSyncEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLE_FULFILLMENT_SYNC, ScopeInterface::SCOPE_STORE);
    }

    public function shouldNotifyCustomer(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_NOTIFY_CUSTOMER, ScopeInterface::SCOPE_STORE);
    }

    public function isConfigured(): bool
    {
        return $this->getApiKey() !== null;
    }

    public function isActive(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ACTIVE, ScopeInterface::SCOPE_STORE);
    }
}
