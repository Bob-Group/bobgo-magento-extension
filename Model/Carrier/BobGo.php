<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Model\Carrier;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Directory\Helper\Data;
use Magento\Directory\Model\CountryFactory;
use Magento\Directory\Model\CurrencyFactory;
use Magento\Directory\Model\RegionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Xml\Security;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\AbstractCarrierOnline;
use Magento\Shipping\Model\Rate\Result;
use Magento\Shipping\Model\Rate\ResultFactory;
use Magento\Shipping\Model\Simplexml\ElementFactory;
use Magento\Shipping\Model\Tracking\Result\StatusFactory;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\Request\Http as MagentoHttp;
use BobGroup\BobGo\Api\BobGoApiClient;
use BobGroup\BobGo\Api\BobGoApiException;
use BobGroup\BobGo\Model\Config\ApiConfig;
use BobGroup\BobGo\Service\RateCache;

/**
 * Bob Go shipping implementation
 * @website    https://www.bobgo.co.za
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyFields)
 */
class BobGo extends AbstractCarrierOnline implements \Magento\Shipping\Model\Carrier\CarrierInterface
{
    /**
     * Code of the carrier
     * @var string
     */
    public const CODE = 'bobgo';

    /**
     * Units constant (for percentage handling fee calculation)
     * @var int
     */
    public const UNITS = 100;

    /**
     * Method code for the rate we present when a cart rule already grants free
     * shipping. Deliberately not a Bob Go service code — the merchant picks the
     * courier on Bob Go for these, so OrderMapper omits
     * buyer_selected_service_code rather than sending something the API can't
     * resolve.
     */
    public const FREE_SHIPPING_METHOD = 'free';

    private const MAX_WEIGHT_KG = 500;
    private const SECONDS_PER_DAY = 86400;
    private const LBS_TO_KG = 0.45359237;
    private const GRAMS_PER_KG = 1000;

    /**
     * Code of the carrier
     *
     * @var string
     */
    protected $_code = self::CODE;

    /**
     * Rate request data
     *
     * @var RateRequest|null
     */
    protected $_request = null;

    /**
     * Rate result data
     *
     * @var Result|null
     */
    protected $_result = null;

    /**
     * Container types that could be customized for bobgo carrier
     *
     * @var string[]
     */
    protected $_customizableContainerTypes = ['YOUR_PACKAGING'];

    /**
     * @var StoreManagerInterface
     */
    protected StoreManagerInterface $_storeManager;

    /**
     * @var CollectionFactory
     */
    protected CollectionFactory $_productCollectionFactory;

    /**
     * @var ScopeConfigInterface
     */
    protected ScopeConfigInterface $scopeConfig;

    /**
     * @var AdditionalInfo
     */
    public AdditionalInfo $additionalInfo;

    /**
     * @var MagentoHttp
     */
    protected MagentoHttp $httpRequest;

    /**
     * @var BobGoApiClient
     */
    protected BobGoApiClient $apiClient;

    /**
     * @var ApiConfig
     */
    protected ApiConfig $apiConfig;

    /**
     * @var RateCache
     */
    protected RateCache $rateCache;

    /**
     * BobGo constructor.
     *
     * @param ScopeConfigInterface $scopeConfig
     * @param ErrorFactory $rateErrorFactory
     * @param LoggerInterface $logger
     * @param Security $xmlSecurity
     * @param ElementFactory $xmlElFactory
     * @param ResultFactory $rateFactory
     * @param MethodFactory $rateMethodFactory
     * @param \Magento\Shipping\Model\Tracking\ResultFactory $trackFactory
     * @param \Magento\Shipping\Model\Tracking\Result\ErrorFactory $trackErrorFactory
     * @param StatusFactory $trackStatusFactory
     * @param RegionFactory $regionFactory
     * @param CountryFactory $countryFactory
     * @param CurrencyFactory $currencyFactory
     * @param Data $directoryData
     * @param StockRegistryInterface $stockRegistry
     * @param StoreManagerInterface $storeManager
     * @param CollectionFactory $productCollectionFactory
     * @param MagentoHttp $httpRequest
     * @param BobGoApiClient $apiClient
     * @param ApiConfig $apiConfig
     * @param RateCache $rateCache
     * @param array<string,mixed> $data
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ErrorFactory $rateErrorFactory,
        LoggerInterface $logger,
        Security $xmlSecurity,
        ElementFactory $xmlElFactory,
        ResultFactory $rateFactory,
        MethodFactory $rateMethodFactory,
        \Magento\Shipping\Model\Tracking\ResultFactory $trackFactory,
        \Magento\Shipping\Model\Tracking\Result\ErrorFactory $trackErrorFactory,
        StatusFactory $trackStatusFactory,
        RegionFactory $regionFactory,
        CountryFactory $countryFactory,
        CurrencyFactory $currencyFactory,
        Data $directoryData,
        StockRegistryInterface $stockRegistry,
        StoreManagerInterface $storeManager,
        CollectionFactory $productCollectionFactory,
        MagentoHttp $httpRequest,
        BobGoApiClient $apiClient,
        ApiConfig $apiConfig,
        RateCache $rateCache,
        array $data = []
    ) {
        $this->rateCache = $rateCache;
        $this->httpRequest = $httpRequest;
        $this->_storeManager = $storeManager;
        $this->_productCollectionFactory = $productCollectionFactory;
        $this->scopeConfig = $scopeConfig;
        $this->apiClient = $apiClient;
        $this->apiConfig = $apiConfig;

        parent::__construct(
            $scopeConfig,
            $rateErrorFactory,
            $logger,
            $xmlSecurity,
            $xmlElFactory,
            $rateFactory,
            $rateMethodFactory,
            $trackFactory,
            $trackErrorFactory,
            $trackStatusFactory,
            $regionFactory,
            $countryFactory,
            $currencyFactory,
            $directoryData,
            $stockRegistry,
            $data
        );

        $this->additionalInfo = new AdditionalInfo($countryFactory, $this->httpRequest);
    }

    /**
     * Gets the base URL of the store by stripping the http:// or https:// and www. from the URL.
     *
     * @return string
     */
    public function getBaseUrl(): string
    {
        /** @var \Magento\Store\Model\Store $store */
        $store = $this->_storeManager->getStore();
        $storeBase = $store->getBaseUrl();

        // Remove protocol (http:// or https://)
        $host = preg_replace('#^https?://#', '', $storeBase);

        // Ensure $host is a string before using it in explode
        $host = $host ?? '';

        // Remove everything after the host (e.g., paths, query strings)
        $host = explode('/', $host)[0];

        // If the host starts with 'www.', remove it
        if (strpos($host, 'www.') === 0) {
            $host = substr($host, 4);
        }

        return $host;
    }

    /**
     * Makes a request to the Bob Go API to get shipping rates for the cart.
     *
     * @param array<string,mixed> $payload
     * @return array<int|string, mixed>
     */
    public function getRates(array $payload): array
    {
        $rates = $this->uRates($payload);

        if ($rates === null) {
            $this->_logger->warning('Bob Go: getRates returned no data from API');
            return [];
        }

        return $rates;
    }

    /**
     * Processing additional validation to check if the carrier is applicable.
     *
     * @param DataObject $request
     * @return $this|bool|\Magento\Framework\DataObject
     */
    public function processAdditionalValidation(DataObject $request)
    {
        /** @var RateRequest $rateRequest */
        $rateRequest = $request;

        if (!count($this->getAllItems($rateRequest))) {
            return false;
        }

        $maxAllowedWeight = self::MAX_WEIGHT_KG;
        $errorMsg = '';
        $configErrorMsg = $this->getConfigData('specificerrmsg');
        $defaultErrorMsg = __('The shipping module is not available.');
        $showMethod = $this->getConfigData('showmethod');

        /** @var \Magento\Quote\Model\Quote\Item $item */
        foreach ($this->getAllItems($rateRequest) as $item) {
            $product = $item->getProduct();
            if ($product && $product->getId()) {
                $weight = $product->getWeight();
                $websiteId = (int) $item->getStore()->getWebsiteId(); // Ensure $websiteId is an integer
                $stockItemData = $this->stockRegistry->getStockItem($product->getId(), $websiteId);
                $doValidation = true;

                if ($stockItemData->getIsQtyDecimal() && $stockItemData->getIsDecimalDivided()) {
                    if ($stockItemData->getEnableQtyIncrements() && $stockItemData->getQtyIncrements()) {
                        $weight = $weight * $stockItemData->getQtyIncrements();
                    } else {
                        $doValidation = false;
                    }
                } elseif ($stockItemData->getIsQtyDecimal() && !$stockItemData->getIsDecimalDivided()) {
                    $weight = $weight * $item->getQty();
                }

                if ($doValidation && $weight > $maxAllowedWeight) {
                    $errorMsg = $configErrorMsg ? $configErrorMsg : $defaultErrorMsg;
                    break;
                }
            }
        }

        // Bob Go shipping is only available for South Africa (ZA). Non-ZA
        // destinations get the generic carrier-not-available error and
        // skip the rest of the validation chain.
        if ($rateRequest->getDestCountryId() !== 'ZA') {
            $errorMsg = $configErrorMsg ? $configErrorMsg : $defaultErrorMsg;
        }

        // Postcode required for ZA (and any other country Magento marks
        // postcode-mandatory). Earlier versions cleared this error when
        // destCountry === 'ZA', which let the carrier fire without a
        // postcode and break the rate request downstream.
        if (!$errorMsg && !$rateRequest->getDestPostcode()
            && $this->isZipCodeRequired($rateRequest->getDestCountryId())) {
            $errorMsg = __('This shipping method is not available. Please specify the zip code.');
        }

        // If there's an error and showMethod is enabled, return an error rate object
        // so the customer sees the carrier with an error message. Otherwise return false
        // to silently hide the carrier.
        if ($errorMsg && $showMethod) {
            $error = $this->_rateErrorFactory->create();
            $error->setCarrier($this->_code);
            $error->setCarrierTitle($this->getConfigData('title'));
            $error->setErrorMessage($errorMsg);

            return $error;
        } elseif ($errorMsg) {
            return false;
        }

        return $this;
    }

    /**
     * Collect and get rates for this shipping method based on information in $request.
     *
     * This is a default function that is called by Magento to get the shipping rates for the cart.
     *
     * @param RateRequest $request
     * @return Result|bool|null
     */
    public function collectRates(RateRequest $request)
    {
        // Make sure that Shipping method is enabled
        if (!$this->isActive()) {
            return false;
        }

        // Fail soft — checkout must survive anything going wrong in here.
        // Magento does NOT guard this call: Shipping::collectCarrierRates()
        // invokes collectRates() with no try/catch, so an exception escaping
        // here 500s the checkout shipping step (and the cart estimator) for
        // every customer, whatever other carriers the store has. A falsy
        // return is the documented "this carrier has no rates" signal, so the
        // rest of the checkout carries on without us.
        try {
            return $this->collectBobGoRates($request);
        } catch (\Throwable $e) {
            $this->_logger->error('Bob Go: rate collection failed, hiding carrier', [
                'exception' => get_class($e),
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Build the rate request payload and collect rates from the Bob Go API.
     *
     * @param RateRequest $request
     * @return Result
     */
    private function collectBobGoRates(RateRequest $request): Result
    {
        // A cart rule already grants free shipping on this address, so there is
        // nothing to price. Present one zero-cost rate and skip the API entirely.
        //
        // This has to happen BEFORE the cache is touched: the free-shipping flag
        // is not part of the cache key, so zeroing a cached rate would leak free
        // shipping to the next cart with the same basket and address and no
        // coupon. Magento's address-level free_shipping flag is itself the output
        // of cart-rule validation, so checking it is the same test Magento's own
        // free-shipping carrier applies.
        if ($request->getFreeShipping()) {
            return $this->freeShippingResult();
        }

        /**
         * Gets the destination company name from Company Name field in the checkout page.
         * This method is used as the last resort to get the company name since the company name is
         * not available in _rateFactory.
         */
        $destComp = $this->getDestComp();
        $destSuburb = $this->getDestSuburb();

        /** @var \Magento\Shipping\Model\Rate\Result $result */
        $result = $this->_rateFactory->create();

        $destination = $request->getDestPostcode();
        $destCountry = $request->getDestCountryId();
        $destRegion = $request->getDestRegionCode();
        $destCity = $request->getDestCity();
        $destStreet = $request->getDestStreet();

        /** Destination Information */
        [$destStreet1, $destStreet2, $destStreet3] = $this->destStreet($destStreet);

        /** Origin Information */
        [
            $originStreet,
            $originRegion,
            $originCountry,
            $originCity,
            $originStreet1,
            $originStreet2,
            $storeName,
            $originSuburb,
            $weightUnit
        ] = $this->storeInformation();

        // Ensure weightUnit is always a string
        $weightUnit = $weightUnit ?? '';

        /** Get all items in cart */
        $items = $request->getAllItems();
        $itemsArray = [];
        $itemsArray = $this->getStoreItems($items, $weightUnit, $itemsArray);

        $payload = [
            'collection_address' => [
                'company' => $storeName,
                'street_address' => $originStreet1,
                'local_area' => $originSuburb,
                'city' => $originCity,
                'zone' => $originRegion,
                'country' => $originCountry,
                'code' => $originStreet,
            ],
            'delivery_address' => [
                'company' => $destComp,
                'street_address' => $destStreet1,
                'local_area' => $destSuburb,
                'city' => $destCity,
                'zone' => $destRegion,
                'country' => $destCountry,
                'code' => $destination,
            ],
            'items' => $itemsArray,
            // Two different numbers, both required.
            //
            // declared_value is the PRE-discount value of the shippable goods —
            // what the parcel is worth, for insurance and customs.
            //
            // order_total_price is the POST-discount total, and it is what Bob Go
            // evaluates free-shipping-over-X thresholds against. Sending only the
            // pre-discount value gave WooCommerce shoppers free shipping they
            // hadn't earned, and denied it to those who had. Until this release we
            // sent a hardcoded 0 for declared_value and nothing at all for
            // order_total_price, so those thresholds could not work.
            'declared_value' => $this->declaredValue($request, $itemsArray),
            'order_total_price' => $this->orderTotalPrice($request, $itemsArray),
            'handling_time' => 0,
        ];

        $this->_getRates($payload, $result);

        return $result;
    }

    /**
     * One zero-cost rate, for when a cart rule has already granted free shipping.
     */
    private function freeShippingResult(): Result
    {
        /** @var Result $result */
        $result = $this->_rateFactory->create();

        $method = $this->_rateMethodFactory->create();
        $method->setCarrier(self::CODE);
        $method->setCarrierTitle('');
        $method->setMethod(self::FREE_SHIPPING_METHOD);
        $method->setMethodTitle((string) __('Free shipping'));
        $method->setPrice(0.0);
        $method->setCost(0.0);

        $result->append($method);

        return $result;
    }

    /**
     * Pre-discount value of the shippable goods.
     *
     * Prefers Magento's own figures: package_physical_value excludes virtual
     * items, package_value is the whole address subtotal. Falls back to summing
     * the payload items for flows that don't populate either.
     *
     * @param array<int,array<string,mixed>> $items
     */
    private function declaredValue(RateRequest $request, array $items): float
    {
        foreach ([$request->getPackagePhysicalValue(), $request->getPackageValue()] as $candidate) {
            if (is_numeric($candidate) && (float) $candidate > 0.0) {
                return round((float) $candidate, 2);
            }
        }
        return $this->sumItemValue($items);
    }

    /**
     * Post-discount cart total — the figure Bob Go's free-shipping thresholds
     * are configured against.
     *
     * Magento exposes package_value_with_discount for the whole address; there is
     * no post-discount physical-only equivalent, which only diverges on a mixed
     * physical/virtual cart.
     *
     * @param array<int,array<string,mixed>> $items
     */
    private function orderTotalPrice(RateRequest $request, array $items): float
    {
        $withDiscount = $request->getPackageValueWithDiscount();
        if (is_numeric($withDiscount) && (float) $withDiscount >= 0.0) {
            return round((float) $withDiscount, 2);
        }
        return $this->declaredValue($request, $items);
    }

    /**
     * @param array<int,array<string,mixed>> $items
     */
    private function sumItemValue(array $items): float
    {
        $total = 0.0;
        foreach ($items as $item) {
            $total += ((float) ($item['price'] ?? 0)) * ((int) ($item['quantity'] ?? 0));
        }
        return round($total, 2);
    }

    /**
     * Retrieves store information including origin details.
     *
     * @return array<int, string|null>
     */
    public function storeInformation(): array
    {
        /** Store Origin details */
        $originCountry = $this->getStringValue('general/store_information/country_id');
        $originRegionRaw = $this->getStringValue('general/store_information/region_id');
        $originRegion = $this->resolveRegionCode($originRegionRaw, $originCountry);
        $originCity = $this->getStringValue('general/store_information/city');
        $originStreet = $this->getStringValue('general/store_information/postcode');
        $originStreet1 = $this->getStringValue('general/store_information/street_line1');
        $originStreet2 = $this->getStringValue('general/store_information/street_line2');
        $storeName = $this->getStringValue('general/store_information/name');
        $originSuburb = $this->getStringValue('general/store_information/suburb');
        $weightUnit = $this->getStringValue('general/locale/weight_unit');

        return [
            $originStreet,
            $originRegion,
            $originCountry,
            $originCity,
            $originStreet1,
            $originStreet2,
            $storeName,
            $originSuburb,
            $weightUnit,
        ];
    }

    /**
     * Magento stores the configured store region as `region_id` — a
     * numeric directory_country_region primary key. Bob Go expects a
     * province code/name (e.g. "GP", "WC"). Resolve the id through
     * RegionFactory; fall back to whatever was stored (which might
     * already be a name in older installs).
     */
    private function resolveRegionCode(?string $regionRaw, ?string $countryId): ?string
    {
        if ($regionRaw === null || $regionRaw === '') {
            return null;
        }
        // Already non-numeric? Probably a code/name — pass it through.
        if (!ctype_digit($regionRaw)) {
            return $regionRaw;
        }
        try {
            /** @var \Magento\Directory\Model\Region $region */
            $region = $this->_regionFactory->create()->load((int) $regionRaw);
            if ($region->getId()) {
                $code = (string) ($region->getCode() ?: $region->getName());
                if ($code !== '') {
                    return $code;
                }
            }
        } catch (\Throwable $e) {
            $this->_logger->warning('Bob Go: failed to resolve origin region', [
                'region_raw' => $regionRaw,
                'country_id' => $countryId,
                'error' => $e->getMessage(),
            ]);
        }
        return $regionRaw;
    }

    /**
     * Safely retrieve a configuration value as a string or null.
     *
     * @param string $path
     * @return string|null
     */
    protected function getStringValue(string $path): ?string
    {
        $value = $this->_scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE);
        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Get result of request
     *
     * @return Result|null
     */
    public function getResult()
    {
        if (!$this->_result) {
            $this->_result = $this->_trackFactory->create();
        }
        return $this->_result;
    }

    /**
     * Get final price for shipping method with handling fee per package
     *
     * @param float $cost
     * @param string $handlingType
     * @param float $handlingFee
     * @return float
     */
    protected function _getPerpackagePrice($cost, $handlingType, $handlingFee)
    {
        if ($handlingType == AbstractCarrier::HANDLING_TYPE_PERCENT) {
            return $cost + $cost * $this->_numBoxes * $handlingFee / self::UNITS;
        }

        return $cost + $this->_numBoxes * $handlingFee;
    }

    /**
     * Get final price for shipping method with handling fee per order
     *
     * @param float $cost
     * @param string $handlingType
     * @param float $handlingFee
     * @return float
     */
    protected function _getPerorderPrice($cost, $handlingType, $handlingFee)
    {
        if ($handlingType == self::HANDLING_TYPE_PERCENT) {
            return $cost + $cost * $handlingFee / self::UNITS;
        }

        return $cost + $handlingFee;
    }

    /**
     * Get configuration data of carrier
     *
     * @param string $type
     * @param string $code
     * @return array<string, \Magento\Framework\Phrase>|string|false
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function getCode($type, $code = '')
    {
        $codes = [
            'method' => [
                'bobGo' => __('BobGo'),
            ],
            'unit_of_measure' => [
                'KGS' => __('Kilograms'),
                'LBS' => __('Pounds'),
            ],
        ];

        if (!isset($codes[$type])) {
            return false;
        } elseif ('' === $code) {
            return $codes[$type];
        }

        if (!isset($codes[$type][$code])) {
            return false;
        } else {
            return (string) $codes[$type][$code]; // Convert \Magento\Framework\Phrase to string
        }
    }

    /**
     * Get allowed shipping methods
     *
     * @return array<string, mixed>
     */
    public function getAllowedMethods(): array
    {
        $allowedMethods = $this->getConfigData('allowed_methods');
        if (empty($allowedMethods)) {
            return []; // Return an empty array if no allowed methods are configured
        }

        $allowed = explode(',', $allowedMethods);
        $arr = [];
        foreach ($allowed as $k) {
            $arr[$k] = $this->getCode('method', $k);
        }

        return $arr;
    }

    /**
     * Check if carrier has tracking functionality.
     *
     * @return bool
     */
    public function isTrackingAvailable(): bool
    {
        return true;
    }

    /**
     * Get tracking info for a shipment tracking number.
     *
     * Fetches live tracking events from the Bob Go API and returns a Status
     * object with progress details. Falls back to just the tracking URL
     * if the API call fails.
     *
     * @param string $tracking The tracking number
     * @return \Magento\Shipping\Model\Tracking\Result\Status
     */
    public function getTrackingInfo($tracking)
    {
        $status = $this->_trackStatusFactory->create();
        $status->setCarrier(self::CODE);
        $status->setCarrierTitle($this->getConfigData('title') ?: 'Bob Go');
        $status->setTracking($tracking);
        $status->setUrl($this->getTrackingUrl((string) $tracking));

        try {
            $response = $this->apiClient->get('tracking', [
                'tracking_reference' => (string) $tracking,
            ]);

            // API returns an array of shipments; use the first one
            $shipment = isset($response[0]) ? $response[0] : $response;

            if (!empty($shipment['status_friendly'])) {
                $status->setStatus($shipment['status_friendly']);
            } elseif (!empty($shipment['status'])) {
                $status->setStatus($this->formatTrackingStatus($shipment['status']));
            }

            if (!empty($shipment['checkpoints']) && is_array($shipment['checkpoints'])) {
                $progressDetails = [];
                foreach ($shipment['checkpoints'] as $checkpoint) {
                    $dateTime = $checkpoint['time'] ?? '';
                    $detail = [
                        'activity' => $checkpoint['status_friendly'] ?? $this->formatTrackingStatus($checkpoint['status'] ?? ''),
                        'deliverylocation' => $checkpoint['message'] ?? '',
                    ];

                    if ($dateTime !== '') {
                        try {
                            $dt = new \DateTime($dateTime);
                            $detail['deliverydate'] = $dt->format('Y-m-d');
                            $detail['deliverytime'] = $dt->format('H:i:s');
                        } catch (\Exception $e) {
                            $this->_logger->debug('Bob Go: failed to parse tracking date', [
                                'date_time' => $dateTime,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }

                    $progressDetails[] = $detail;
                }

                $status->setProgressdetail($progressDetails);
            }
        } catch (BobGoApiException $e) {
            $this->_logger->debug('Bob Go tracking API call failed, falling back to URL only', [
                'tracking' => $tracking,
                'error' => $e->getMessage(),
            ]);
        }

        return $status;
    }

    /**
     * Format a Bob Go tracking status slug into a human-readable string.
     * e.g. "collection-assigned" → "Collection Assigned"
     *
     * @param string $status
     * @return string
     */
    private function formatTrackingStatus(string $status): string
    {
        return ucwords(str_replace('-', ' ', $status));
    }

    /**
     * Build the Bob Go tracking page URL for a tracking reference.
     *
     * @param string $trackingNumber
     * @return string
     */
    private function getTrackingUrl(string $trackingNumber): string
    {
        $baseUrl = $this->apiConfig->getEnvironment() === ApiConfig::ENV_PRODUCTION
            ? 'https://track.bobgo.co.za/'
            : 'https://track.sandbox.bobgo.co.za/';
        return $baseUrl . urlencode($trackingNumber);
    }

    /**
     * Do shipment request to carrier web service, obtain Print Shipping Labels, and process errors in response.
     *
     * Also another magic function that is required to be implemented by the carrier model.
     *
     * @param \Magento\Framework\DataObject $request
     * @return \Magento\Framework\DataObject|null
     */
    protected function _doShipmentRequest(\Magento\Framework\DataObject $request)
    {
        return null;
    }

    /**
     * For multi-package shipments. Delete requested shipments if the current shipment request fails.
     *
     * @param mixed $data
     * @return bool
     */
    public function rollBack($data): bool
    {
        // Return false if $data is not an array
        if (!is_array($data)) {
            return false;
        }

        return true;
    }

    /**
     * Return container types of carrier.
     *
     * Always returns an array (possibly empty) — Magento's admin packaging popup
     * template (vendor/magento/module-shipping/.../popup_content.phtml) iterates
     * the return value with foreach, which fatals if given `false`.
     *
     * @param \Magento\Framework\DataObject|null $params
     * @return array<string, mixed>
     */
    public function getContainerTypes(?\Magento\Framework\DataObject $params = null)
    {
        $result = [];
        $allowedContainers = $this->getConfigData('containers');
        if ($allowedContainers) {
            foreach (explode(',', (string) $allowedContainers) as $container) {
                $result[$container] = $this->getCode('container_types', $container);
            }
        }

        return $result;
    }

    /**
     * Return delivery confirmation types of carrier.
     *
     * @param \Magento\Framework\DataObject|null $params
     * @return array<int|string, mixed>
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getDeliveryConfirmationTypes(?\Magento\Framework\DataObject $params = null): array
    {
        $types = $this->getCode('delivery_confirmation_types');

        // Ensure it returns an array, even if getCode returns false
        return is_array($types) ? $types : [];
    }

    /**
     * Recursive replace sensitive fields in debug data by the mask.
     *
     * @param mixed $data
     * @return mixed
     */
    protected function filterDebugData($data)
    {
        if (!is_array($data)) {
            return $data; // Return early if $data is not an array.
        }

        foreach (array_keys($data) as $key) {
            if (is_array($data[$key])) {
                $data[$key] = $this->filterDebugData($data[$key]);
            } elseif (in_array($key, $this->_debugReplacePrivateDataKeys)) {
                $data[$key] = self::DEBUG_KEYS_MASK;
            }
        }

        return $data;
    }

    /**
     * Format a date to 'd M Y'.
     *
     * @param string $date
     * @return string
     */
    public function formatDate(string $date): string
    {
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            // Handle the error or return a default value, for example:
            return 'Invalid date';
        }
        return date('d M Y', $timestamp);
    }

    /**
     * Format a time to 'H:i'.
     *
     * @param string $time
     * @return string
     */
    public function formatTime(string $time): string
    {
        $timestamp = strtotime($time);
        if ($timestamp === false) {
            // Handle the error or return a default value, for example:
            return 'Invalid time';
        }
        return date('H:i', $timestamp);
    }


    /**
     * Perform API Request to Bob Go API and return response.
     *
     * @param array<string,mixed> $payload The payload for the API request.
     * @param Result $result The result object to append the rates.
     * @return void
     */
    protected function _getRates(array $payload, Result $result): void
    {
        $rates = $this->uRates($payload);

        // Ensure $rates is an array before passing it to _formatRates
        if (is_array($rates)) {
            $this->_formatRates($rates, $result);
        } else {
            $this->_logger->error('Bob Go API returned an invalid response');
        }
    }

    /**
     * Format rates from Bob Go API response and append to rate result instance of carrier.
     *
     * @param array<int|string,mixed> $rates The rates data from the API.
     * @param Result $result The result object to append the rates.
     * @return void
     */
    protected function _formatRates(array $rates, Result $result): void
    {
        if (empty($rates['rates']) || !is_array($rates['rates'])) {  // Validate that 'rates' exists and is an array
            $error = $this->_rateErrorFactory->create();
            $error->setCarrierTitle($this->getConfigData('title'));
            $error->setErrorMessage($this->getConfigData('specificerrmsg'));

            $result->append($error);
            return;
        }

        foreach ($rates['rates'] as $rate) {
            if (!is_array($rate)) {
                continue;  // Skip if the rate is not an array
            }

            $method = $this->_rateMethodFactory->create();

            // Set the carrier code
            $method->setCarrier(self::CODE);
            // Default carrier title to empty so the checkout shows only the service
            // name; deliveryDays() overrides this when additional_info is enabled.
            $method->setCarrierTitle('');

            // Strip out the redundant 'bobgo_' prefix if present
            $serviceCode = $rate['service_code'] ?? '';
            if (is_string($serviceCode) && strpos($serviceCode, 'bobgo_') === 0) {
                $serviceCode = substr($serviceCode, strlen('bobgo_'));
            }

            // Set the method with the modified service code
            $method->setMethod($serviceCode);

            // Set additional info if required
            if ($this->getConfigData('additional_info') == 1) {
                $min_delivery_date = isset($rate['min_delivery_date']) && is_string($rate['min_delivery_date'])
                    ? $this->getWorkingDays(date('Y-m-d'), $rate['min_delivery_date'])
                    : null;

                $max_delivery_date = isset($rate['max_delivery_date']) && is_string($rate['max_delivery_date'])
                    ? $this->getWorkingDays(date('Y-m-d'), $rate['max_delivery_date'])
                    : null;

                $this->deliveryDays($min_delivery_date, $max_delivery_date, $method);
            }

            // Set the method title, price, and cost
            $service_name = $rate['service_name'] ?? '';
            if (!is_string($service_name)) {
                $service_name = '';
            }
            $method->setMethodTitle($service_name);

            $price = $rate['total_price'] ?? 0;
            if (!is_numeric($price)) {
                $price = 0;
            }
            $cost = $rate['total_price'] ?? 0;
            if (!is_numeric($cost)) {
                $cost = 0;
            }

            $method->setPrice((float)$price);
            $method->setCost((float)$cost);

            $result->append($method);
        }
    }

    /**
     * Get Working Days between time of checkout and delivery date (min and max).
     *
     * @param string $startDate
     * @param string $endDate
     * @return int
     */
    public function getWorkingDays(string $startDate, string $endDate): int
    {
        $begin = strtotime($startDate);
        $end = strtotime($endDate);

        // Check if strtotime failed
        if ($begin === false || $end === false || $begin > $end) {
            return 0; // or throw an exception if preferred
        }

        $dayCount = 0;
        $weekends = 0;

        while ($begin <= $end) {
            $dayCount++;
            $dayOfWeek = date("N", $begin);
            if ($dayOfWeek > 5) {
                $weekends++;
            }
            $begin += self::SECONDS_PER_DAY;
        }

        return $dayCount - $weekends;
    }

    /**
     * Build the payload for Bob Go API request and return the response.
     *
     * @param array<string,mixed> $payload The payload for the API request.
     * @return array<int|string, mixed>|null The decoded response, or null if the response could not be decoded
     * or is not an array.
     */
    protected function uRates(array $payload): ?array
    {
        $cached = $this->rateCache->load($payload);
        if ($cached !== null) {
            return $this->rateCache->isNegative($cached) ? null : $cached;
        }

        try {
            $rates = $this->apiClient->post('rates-at-checkout', $payload);
        } catch (BobGoApiException $e) {
            $this->_logger->error('Bob Go rates API error: ' . $e->getMessage());
            // Brief negative entry so an outage costs one call per 30s rather
            // than one per cart recalculation.
            $this->rateCache->saveFailure($payload);
            return null;
        }

        $this->rateCache->save($payload, $rates);

        return $rates;
    }

    /**
     * Splits a destination street address into up to three lines if it contains newline characters.
     *
     * @param string $destStreet The full street address.
     * @return string[] An array containing up to three lines of the street address.
     */
    protected function destStreet(string $destStreet): array
    {
        if (strpos($destStreet, "\n") !== false) {
            $destStreet = explode("\n", $destStreet);
            $destStreet1 = $destStreet[0];
            $destStreet2 = $destStreet[1];
            $destStreet3 = $destStreet[2] ?? '';
        } else {
            $destStreet1 = $destStreet;
            $destStreet2 = '';
            $destStreet3 = '';
        }
        return [$destStreet1, $destStreet2, $destStreet3];
    }

    /**
     * Sets the carrier title with the estimated delivery days range based on minimum and maximum delivery dates.
     *
     * @param int|null $min_delivery_date Minimum estimated delivery date in days.
     * @param int|null $max_delivery_date Maximum estimated delivery date in days.
     * @param \Magento\Quote\Model\Quote\Address\RateResult\Method $method The shipping method instance
     * to set the carrier title.
     * @return void
     */
    protected function deliveryDays(
        ?int $min_delivery_date,
        ?int $max_delivery_date,
        \Magento\Quote\Model\Quote\Address\RateResult\Method $method
    ): void {
        if ($min_delivery_date === null || $max_delivery_date === null) {
            return;
        }

        if ($min_delivery_date !== $max_delivery_date) {
            $method->setCarrierTitle('Delivery in ' . $min_delivery_date . ' - ' . $max_delivery_date . ' days');
        } else {
            if ($min_delivery_date && $max_delivery_date == 1) {
                $method->setCarrierTitle('Delivery in ' . $min_delivery_date . ' day');
            } else {
                $method->setCarrierTitle('Delivery in ' . $min_delivery_date . ' days');
            }
        }
    }

    /**
     * Retrieves the destination company name from the additional information.
     */
    public function getDestComp(): string
    {
        return $this->additionalInfo->getDestComp();
    }

    /**
     * Retrieves the destination suburb from the additional information.
     */
    public function getDestSuburb(): string
    {
        return $this->additionalInfo->getSuburb();
    }

    /**
     * Calculates the item weight in grams based on the provided weight unit.
     *
     * @param string $weightUnit The unit of weight, either 'KGS' or another unit (assumed to be pounds).
     * @param \Magento\Quote\Model\Quote\Item $item The item whose weight is to be calculated.
     * @return float The weight of the item in grams.
     */
    public function getItemWeight(string $weightUnit, \Magento\Quote\Model\Quote\Item $item): float
    {
        $weightUnit = strtolower($weightUnit); // 'kgs' or 'lbs'

        if ($weightUnit === 'kgs') {
            $mass = $item->getWeight() ? $item->getWeight() * self::GRAMS_PER_KG : 0;
        } else {
            $mass = $item->getWeight() ? $item->getWeight() * self::LBS_TO_KG * self::GRAMS_PER_KG : 0;
        }
        return $mass;
    }

    /**
     * Processes the items in the cart, calculates their weights, and prepares an array of item details.
     *
     * @param \Magento\Quote\Model\Quote\Item[] $items The items in the cart.
     * @param string $weightUnit The unit of weight used for the items.
     * @param array<int,array<string,mixed>> $itemsArray The array to store the processed item details.
     * @return array<int, array<string, mixed>> The array containing details of each item,
     * including SKU, quantity, price, and weight.
     */
    public function getStoreItems(
        array $items,
        string $weightUnit,
        array $itemsArray
    ): array {
        foreach ($items as $item) {
            $massGrams = $this->getItemWeight($weightUnit, $item);
            $weightKg = round($massGrams / 1000, 2);

            $itemsArray[] = [
                'description' => $item->getName() ?: $item->getSku(),
                'quantity' => (int) $item->getQty(),
                'price' => (float) $item->getPrice(),
                'length_cm' => 0,
                'width_cm' => 0,
                'height_cm' => 0,
                'weight_kg' => $weightKg,
            ];
        }

        return $itemsArray;
    }

}
