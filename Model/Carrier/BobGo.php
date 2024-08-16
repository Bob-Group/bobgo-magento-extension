<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Model\Carrier;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Checkout\Api\Data\ShippingInformationInterface;
use Magento\Directory\Helper\Data;
use Magento\Directory\Model\CountryFactory;
use Magento\Directory\Model\CurrencyFactory;
use Magento\Directory\Model\RegionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Module\Dir\Reader;
use Magento\Framework\Xml\Security;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Sales\Model\Order\Shipment;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\AbstractCarrierOnline;
use Magento\Shipping\Model\Rate\Result;
use Magento\Shipping\Model\Rate\ResultFactory;
use Magento\Shipping\Model\Simplexml\ElementFactory;
use Magento\Shipping\Model\Tracking\Result\StatusFactory;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\RequestInterface;

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
     * Units constant
     * @var int
     */
    public const UNITS = 100;

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
     * @var DataObject
     */
    private DataObject $_rawTrackingRequest;

    /**
     * @var \Magento\Framework\HTTP\Client\Curl
     */
    protected \Magento\Framework\HTTP\Client\Curl $curl;

    /**
     * @var ScopeConfigInterface
     */
    protected ScopeConfigInterface $scopeConfig;

    /**
     * @var JsonFactory
     */
    protected JsonFactory $jsonFactory;

    /**
     * @var mixed
     */
    private $cartRepository;

    /**
     * @var AdditionalInfo
     */
    public AdditionalInfo $additionalInfo;

    /**
     * @var RequestInterface
     */
    protected RequestInterface $request;

    /**
     * Constructor
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
     * @param Reader $configReader
     * @param CollectionFactory $productCollectionFactory
     * @param JsonFactory $jsonFactory
     * @param CurlFactory $curlFactory
     * @param RequestInterface $request
     * @param array $data
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
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
        Reader $configReader,
        CollectionFactory $productCollectionFactory,
        JsonFactory $jsonFactory,
        CurlFactory $curlFactory,
        RequestInterface $request,
        array $data = []
    ) {
        $this->request = $request;
        $this->_storeManager = $storeManager;
        $this->_productCollectionFactory = $productCollectionFactory;
        $this->scopeConfig = $scopeConfig;
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
        $this->jsonFactory = $jsonFactory;
        $this->curl = $curlFactory->create();
        $this->additionalInfo = new AdditionalInfo($countryFactory, $this->request);
    }

    /**
     * Gets the base URL of the store by stripping the http:// or https:// and www. from the URL.
     *
     * @return string
     */
    public function getBaseUrl(): string
    {
        $storeBase = $this->_storeManager->getStore()->getBaseUrl();

        // Remove protocol (http:// or https://)
        $host = preg_replace('#^https?://#', '', $storeBase);

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
     * @param array $payload
     * @return array
     */
    public function getRates(array $payload): array
    {
        return $this->uRates($payload);
    }

    /**
     * Processing additional validation to check if the carrier is applicable.
     *
     * @param \Magento\Framework\DataObject $request
     * @return $this|bool|\Magento\Framework\DataObject
     */
    public function processAdditionalValidation(\Magento\Framework\DataObject $request)
    {
        if (!count($this->getAllItems($request))) {
            return false;
        }

        $maxAllowedWeight = 500;
        $errorMsg = '';
        $configErrorMsg = $this->getConfigData('specificerrmsg');
        $defaultErrorMsg = __('The shipping module is not available.');
        $showMethod = $this->getConfigData('showmethod');

        /** @var $item \Magento\Quote\Model\Quote\Item */
        foreach ($this->getAllItems($request) as $item) {
            $product = $item->getProduct();
            if ($product && $product->getId()) {
                $weight = $product->getWeight();
                $stockItemData = $this->stockRegistry->getStockItem(
                    $product->getId(),
                    $item->getStore()->getWebsiteId()
                );
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

        if (!$errorMsg && !$request->getDestPostcode() && $this->isZipCodeRequired($request->getDestCountryId())) {
            $errorMsg = __('This shipping method is not available. Please specify the zip code.');
        }

        if ($request->getDestCountryId() == 'ZA') {
            $errorMsg = '';
        } else {
            $errorMsg = $configErrorMsg ? $configErrorMsg : $defaultErrorMsg;
        }

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
            $baseIdentifier,
            $originSuburb,
            $weightUnit
        ] = $this->storeInformation();

        /** Get all items in cart */
        $items = $request->getAllItems();
        $itemsArray = [];
        $itemsArray = $this->getStoreItems($items, $weightUnit, $itemsArray);

        $payload = [
            'identifier' => $baseIdentifier,
            'rate' => [
                'origin' => [
                    'company' => $storeName,
                    'address1' => $originStreet1,
                    'address2' => $originStreet2,
                    'city' => $originCity,
                    'suburb' => $originSuburb,
                    'province' => $originRegion,
                    'country_code' => $originCountry,
                    'postal_code' => $originStreet,
                ],
                'destination' => [
                    'company' => $destComp,
                    'address1' => $destStreet1,
                    'address2' => $destStreet2,
                    'suburb' => $destSuburb,
                    'city' => $destCity,
                    'province' => $destRegion,
                    'country_code' => $destCountry,
                    'postal_code' => $destination,
                ],
                'items' => $itemsArray,
            ]
        ];

        $this->_getRates($payload, $result);

        return $result;
    }

    /**
     * Retrieves store information including origin details.
     *
     * @return array
     */
    public function storeInformation(): array
    {
        /** Store Origin details */
        $originCountry = $this->_scopeConfig->getValue(
            'general/store_information/country_id',
            ScopeInterface::SCOPE_STORE
        );
        $originRegion = $this->_scopeConfig->getValue(
            'general/store_information/region_id',
            ScopeInterface::SCOPE_STORE
        );
        $originCity = $this->_scopeConfig->getValue(
            'general/store_information/city',
            ScopeInterface::SCOPE_STORE
        );

        $originStreet = $this->_scopeConfig->getValue(
            'general/store_information/postcode',
            ScopeInterface::SCOPE_STORE
        );

        $originStreet1 = $this->_scopeConfig->getValue(
            'general/store_information/street_line1',
            ScopeInterface::SCOPE_STORE
        );

        $originStreet2 = $this->_scopeConfig->getValue(
            'general/store_information/street_line2',
            ScopeInterface::SCOPE_STORE
        );

        $storeName = $this->_scopeConfig->getValue(
            'general/store_information/name',
            ScopeInterface::SCOPE_STORE
        );

        $originSuburb = $this->_scopeConfig->getValue(
            'general/store_information/suburb',
            ScopeInterface::SCOPE_STORE
        );
        $weightUnit = $this->_scopeConfig->getValue(
            'general/locale/weight_unit',
            ScopeInterface::SCOPE_STORE
        );

        $baseIdentifier = $this->getBaseUrl();

        return [
            $originStreet,
            $originRegion,
            $originCountry,
            $originCity,
            $originStreet1,
            $originStreet2,
            $storeName,
            $baseIdentifier,
            $originSuburb,
            $weightUnit
        ];
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
     * @return array|false
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
            return $codes[$type][$code];
        }
    }

    /**
     * Get tracking
     *
     * @param string|string[] $trackings
     * @return Result|null
     */
    public function getTracking($trackings)
    {
        $this->setTrackingReqeust();

        if (!is_array($trackings)) {
            $trackings = [$trackings];
        }

        foreach ($trackings as $tracking) {
            $this->_getXMLTracking($tracking);
        }

        return $this->_result;
    }

    /**
     * Set tracking request
     *
     * @return void
     */
    protected function setTrackingReqeust()
    {
        $r = new \Magento\Framework\DataObject();

        $account = $this->getConfigData('account');
        $r->setAccount($account);

        $this->_rawTrackingRequest = $r;
    }

    /**
     * Send request for tracking
     *
     * @param string[] $tracking
     * @return void
     */
    protected function _getXMLTracking($tracking)
    {
        $this->_parseTrackingResponse($tracking);
    }

    /**
     * Parse tracking response
     *
     * @param string $trackingValue
     * @return void
     */
    protected function _parseTrackingResponse($trackingValue)
    {
        $result = $this->getResult();
        $carrierTitle = $this->getConfigData('title');
        $counter = 0;
        if (!is_array($trackingValue)) {
            $trackingValue = [$trackingValue];
        }
        foreach ($trackingValue as $trackingReference) {
            $tracking = $this->_trackStatusFactory->create();

            $tracking->setCarrier(self::CODE);
            $tracking->setCarrierTitle($carrierTitle);

            //Production
            /*   $tracking->setUrl(sprintf(uData::TRACKING, $this->getBaseUrl(), $trackingReference));
            $tracking->setTracking($trackingReference);
            $tracking->addData($this->processTrackingDetails($trackingReference));
           */

            //Dev
            $tracking->setUrl(uData::TRACKING .$trackingReference);
            $tracking->setTracking($trackingReference);
            $tracking->addData($this->processTrackingDetails($trackingReference));

            $result->append($tracking);
            $counter ++;
        }

        //Tracking Details Not Available
        if (!$counter) {
            $this->appendTrackingError(
                $trackingValue,
                __('For some reason we can\'t retrieve tracking info right now.')
            );
        }
    }

    /**
     * Get tracking response
     *
     * @return string
     */
    public function getResponse()
    {
        $statuses = '';
        if ($this->_result instanceof \Magento\Shipping\Model\Tracking\Result) {
            if ($trackings = $this->_result->getAllTrackings()) {
                foreach ($trackings as $tracking) {
                    if ($data = $tracking->getAllData()) {
                        if (!empty($data['status'])) {
                            $statuses .= __($data['status']) . "\n<br/>";
                        } else {
                            $statuses .= __('Empty response') . "\n<br/>";
                        }
                    }
                }
            }
        }
        // phpstan:ignore
        if (empty($statuses)) {
            $statuses = __('Empty response');
        }

        return $statuses;
    }

    /**
     * Get allowed shipping methods
     *
     * @return array
     */
    public function getAllowedMethods()
    {
        $allowed = explode(',', $this->getConfigData('allowed_methods'));
        $arr = [];
        foreach ($allowed as $k) {
            $arr[$k] = $this->getCode('method', $k);
        }

        return $arr;
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
     * @param array $data
     * @return bool
     */
    public function rollBack($data)
    {
        return true;
    }

    /**
     * Return container types of carrier.
     *
     * @param \Magento\Framework\DataObject|null $params
     * @return array|bool
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function getContainerTypes(\Magento\Framework\DataObject $params = null)
    {
        $result = [];
        $allowedContainers = $this->getConfigData('containers');
        if ($allowedContainers) {
            $allowedContainers = explode(',', $allowedContainers);
        }
        if ($allowedContainers) {
            foreach ($allowedContainers as $container) {
                $result[$container] = $this->getCode('container_types', $container);
            }
        }

        return $result;
    }

    /**
     * Return delivery confirmation types of carrier.
     *
     * @param \Magento\Framework\DataObject|null $params
     * @return array
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getDeliveryConfirmationTypes(\Magento\Framework\DataObject $params = null)
    {
        return $this->getCode('delivery_confirmation_types');
    }

    /**
     * Recursive replace sensitive fields in debug data by the mask.
     *
     * @param string $data
     * @return string
     */
    protected function filterDebugData($data)
    {
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
     * Parse track details response from Bob Go.
     *
     * @param string $trackInfo
     * @return array
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function processTrackingDetails($trackInfo): array
    {
        $result = [
            'shippeddate' => null,
            'deliverydate' => null,
            'deliverytime' => null,
            'deliverylocation' => null,
            'weight' => null,
            'progressdetail' => [],
        ];

        $result = $this->_requestTracking($trackInfo, $result);

        return $result;
    }

    /**
     * Append error message to rate result instance.
     *
     * @param string $trackingValue
     * @param string $errorMessage
     */
    private function appendTrackingError($trackingValue, $errorMessage)
    {
        $error = $this->_trackErrorFactory->create();
        $error->setCarrier(self::CODE);
        $error->setCarrierTitle($this->getConfigData('title'));
        $error->setTracking($trackingValue);
        $error->setErrorMessage($errorMessage);
        $result = $this->getResult();
        $result->append($error);
    }

    /**
     * Format a date to 'd M Y'.
     *
     * @param string $date
     * @return string
     */
    public function formatDate(string $date): string
    {
        return date('d M Y', strtotime($date));
    }

    /**
     * Format a time to 'H:i'.
     *
     * @param string $time
     * @return string
     */
    public function formatTime(string $time): string
    {
        return date('H:i', strtotime($time));
    }

    /**
     * Get the API URL for Bob Go.
     *
     * @return string
     */
    private function getApiUrl(): string
    {
        return uData::RATES_ENDPOINT;
    }

    /**
     *  Perfom API Request to bobgo API and return response
     *
     * @param array $payload
     * @param Result $result
     * @return void
     */
    protected function _getRates(array $payload, Result $result): void
    {

        $rates = $this->uRates($payload);

        $this->_formatRates($rates, $result);
    }

    /**
     * Perform API Request for Shipment Tracking to Bob Go API and return response.
     *
     * @param string $trackInfo The tracking information or tracking ID.
     * @param array $result The result array to be populated with tracking details.
     * @return array The updated result array with tracking details.
     */
    private function _requestTracking(string $trackInfo, array $result): array
    {
        $response = $this->trackbobgoShipment($trackInfo);

        $result = $this->prepareActivity($response[0], $result);

        return $result;
    }

    /**
     * Format rates from Bob Go API response and append to rate result instance of carrier
     *
     * @param mixed $rates
     * @param Result $result
     * @return void
     */
    protected function _formatRates(mixed $rates, Result $result): void
    {
        if (empty($rates['rates'])) {  // Check if the 'rates' key is empty or null
            $error = $this->_rateErrorFactory->create();
            $error->setCarrierTitle($this->getConfigData('title'));
            $error->setErrorMessage($this->getConfigData('specificerrmsg'));

            $result->append($error);
        } else {

            foreach ($rates['rates'] as $rate) {

                $method = $this->_rateMethodFactory->create();

                if (isset($rate)) {
                    // Set the carrier code
                    $method->setCarrier(self::CODE);

                    // Strip out the redundant 'bobgo_' prefix if present
                    $serviceCode = $rate['service_code'];
                    if (strpos($serviceCode, 'bobgo_') === 0) {
                        $serviceCode = substr($serviceCode, strlen('bobgo_'));
                    }

                    // Set the method with the modified service code
                    $method->setMethod($serviceCode);

                    // Set additional info if required
                    if ($this->getConfigData('additional_info') == 1) {
                        $min_delivery_date = isset($rate['min_delivery_date']) && $rate['min_delivery_date'] !== null
                            ? $this->getWorkingDays(date('Y-m-d'), $rate['min_delivery_date'])
                            : null;

                        $max_delivery_date = isset($rate['max_delivery_date']) && $rate['max_delivery_date'] !== null
                            ? $this->getWorkingDays(date('Y-m-d'), $rate['max_delivery_date'])
                            : null;

                        $this->deliveryDays($min_delivery_date, $max_delivery_date, $method);
                    }

                    // Set the method title, price, and cost
//                    $description = $rate['description'];
                    $service_name = $rate['service_name'];
//                    $method->setMethodTitle("$service_name | $description" );
                    $method->setMethodTitle("$service_name");
                    $price = $rate['total_price'];
                    $cost = $rate['total_price'];

                    $method->setPrice($price);
                    $method->setCost($cost);

                    $result->append($method);
                }
            }
        }
    }

    /**
     * Prepare received checkpoints and activity from Bob Go Shipment Tracking API.
     *
     * @param array $response The API response containing tracking checkpoints.
     * @param array $result The result array to be populated with activity details.
     * @return array The updated result array with activity details.
     */
    private function prepareActivity(array $response, array $result): array
    {
        foreach ($response['checkpoints'] as $checkpoint) {
            $result['progressdetail'][] = [
                'activity' => $checkpoint['status'],
                'deliverydate' => $this->formatDate($checkpoint['time']),
                'deliverytime' => $this->formatTime($checkpoint['time']),
            ];
        }
        return $result;
    }

    /**
     *  Get Working Days between time of checkout and delivery date (min and max)
     *
     * @param string $startDate
     * @param string $endDate
     * @return int
     */
    public function getWorkingDays(string $startDate, string $endDate): int
    {
        $begin = strtotime($startDate);
        $end = strtotime($endDate);
        if ($begin > $end) {
            return 0;
        } else {
            $no_days = 0;
            $weekends = 0;
            while ($begin <= $end) {
                $no_days++; // no of days in the given interval
                $what_day = date("N", $begin);
                if ($what_day > 5) { // 6 and 7 are weekend days
                    $weekends++;
                };
                $begin += 86400; // +1 day
            };
            return $no_days - $weekends;
        }
    }

    /**
     * Curl request to Bob Go Shipment Tracking API.
     *
     * @param string $trackInfo The tracking information or tracking ID.
     * @return mixed The decoded API response.
     */
    private function trackbobgoShipment(string $trackInfo): mixed
    {
        $this->curl->get(uData::TRACKING . $trackInfo);

        $response = $this->curl->getBody();

        return json_decode($response, true);
    }

    /**
     * Build The Payload for Bob Go API Request and return response
     *
     * @param array $payload
     * @return mixed
     */
    protected function uRates(array $payload): mixed
    {

        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->post($this->getApiUrl(), json_encode($payload));
        $rates = $this->curl->getBody();

        $rates = json_decode($rates, true);
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
     *
     * @return mixed|string The destination company name.
     */
    public function getDestComp(): mixed
    {
        return $this->additionalInfo->getDestComp();
    }

    /**
     * Retrieves the destination suburb from the additional information.
     *
     * @return mixed|string The destination suburb.
     */
    public function getDestSuburb(): mixed
    {
        return $this->additionalInfo->getSuburb();
    }

    /**
     * Calculates the item weight in grams based on the provided weight unit.
     *
     * @param mixed $weightUnit The unit of weight, either 'KGS' or another unit (assumed to be pounds).
     * @param mixed $item The item whose weight is to be calculated.
     * @return float|int The weight of the item in grams.
     */
    public function getItemWeight(mixed $weightUnit, mixed $item): int|float
    {
        // 1 lb = 453.59237 g exact. 1 kg = 1000 g. 1 lb = 0.45359237 kg
        if ($weightUnit == 'KGS') {
            $mass = $item->getWeight() ? $item->getWeight() * 1000 : 0;
        } else {
            // Pound to Kilogram Conversion Formula
            $mass = $item->getWeight() ? $item->getWeight() * 0.45359237 * 1000 : 0;
        }
        return $mass;
    }

    /**
     * Processes the items in the cart, calculates their weights, and prepares an array of item details.
     *
     * @param array $items The items in the cart.
     * @param mixed $weightUnit The unit of weight used for the items.
     * @param array $itemsArray The array to store the processed item details.
     * @return array The array containing details of each item including SKU, quantity, price, and weight.
     */
    public function getStoreItems(array $items, mixed $weightUnit, array $itemsArray): array
    {
        foreach ($items as $item) {

            $mass = $this->getItemWeight($weightUnit, $item);

            $itemsArray[] = [
                'sku' => $item->getSku(),
                'quantity' => $item->getQty(),
                'price' => $item->getPrice(),
                'weight' => round($mass),
            ];
        }
        return $itemsArray;
    }

    /**
     * Checks if the required data fields are present in the request.
     *
     * @param \Magento\Framework\DataObject $request The data object containing the request information.
     * @return bool True if all required fields are present, otherwise false.
     */
    public function hasRequiredData(\Magento\Framework\DataObject $request): bool
    {
        $requiredFields = [
            'dest_country_id',
            'dest_region_id',
        ];

        foreach ($requiredFields as $field) {
            if (!$request->getData($field)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Tests the rate retrieval from the BobGo API using a sample payload.
     * This method checks if the "Show rates for checkout" setting is enabled,
     * then constructs and sends a sample payload to the API to verify the response.
     *
     * @return array|bool Returns the response array from the API if successful, or false if an error occurs.
     */
    public function triggerRatesTest()
    {
        // Check if the 'Show rates for checkout' setting is enabled
        $isEnabled = $this->scopeConfig->getValue(
            'carriers/bobgo/active',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );

        if ($isEnabled) {
            // Sample test payload, replace with actual structure
            $payload = [
                'identifier' => $this->getBaseUrl(),
                'rate' => [
                    'origin' => [
                        'company' => 'Jamie Ds Emporium',
                        'address1' => '36 Marelu Street',
                        'address2' => 'Six Fountains Estate',
                        'city' => 'Pretoria',
                        'suburb' => 'Pretoria',
                        'province' => 'GT',
                        'country_code' => 'ZA',
                        'postal_code' => '0081',
                    ],
                    'destination' => [
                        'company' => 'Test Company',
                        'address1' => '456 Test Ave',
                        'address2' => '',
                        'suburb' => 'Test Suburb',
                        'city' => 'Test City',
                        'province' => 'Test Province',
                        'country_code' => 'ZA',
                        'postal_code' => '3000',
                    ],
                    'items' => [
                        [
                            'sku' => 'test-sku-1',
                            'quantity' => 1,
                            'price' => 100.00,
                            'weight' => 500, // in grams
                        ]
                    ],
                ]
            ];

            try {
                // Perform the API request
                $this->curl->addHeader('Content-Type', 'application/json');
                $this->curl->post($this->getApiUrl(), json_encode($payload));
                $statusCode = $this->curl->getStatus();
                $responseBody = $this->curl->getBody();

                // Decode the response
                $response = json_decode($responseBody, true);

                // Check if the response contains a 'message' (indicating an error)
                if (isset($response['message'])) {
                    throw new LocalizedException(__('Error from BobGo: %1', $response['message']));
                }

                // Check if the response contains rates with a valid id field
                if (isset($response['rates']) && is_array($response['rates']) && !empty($response['rates'])) {
                    foreach ($response['rates'] as $rate) {
                        if (isset($rate['id']) && $rate['id'] !== null) {
                            return $response; // Successful response with a valid id
                        }
                    }
                    throw new LocalizedException(__('Rates received but id field is empty or invalid.'));
                } else {
                    throw new LocalizedException(__('Received response but no valid rates were found.'));
                }
            } catch (\Exception $e) {
                return false;
            }
        }
        return false;
    }
}
