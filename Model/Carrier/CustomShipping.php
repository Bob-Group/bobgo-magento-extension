<?php
declare(strict_types=1);

namespace bobgo\CustomShipping\Model\Carrier;

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
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\AbstractCarrierOnline;
use Magento\Shipping\Model\Rate\Result;
use Magento\Shipping\Model\Rate\ResultFactory;
use Magento\Shipping\Model\Simplexml\ElementFactory;
use Magento\Shipping\Model\Tracking\Result\StatusFactory;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Bob Go shipping implementation
 * @category   Bob Go
 * @package    bobgo_CustomShipping
 * @author     Bob Go
 * @website    https://www.bobgo.co.za
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyFields)
 */
class CustomShipping extends AbstractCarrierOnline implements \Magento\Shipping\Model\Carrier\CarrierInterface
{
    /**
     * Code of the carrier
     *`
     * @var string
     */
    public const CODE = 'bobgo';

    const UNITS = 100;

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
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $_storeManager;

    /**
     * @var \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory
     */
    protected $_productCollectionFactory;


    /**
     * @var DataObject
     */
    private $_rawTrackingRequest;

    /**
     * @var \Magento\Framework\HTTP\Client\Curl
     */
    protected \Magento\Framework\HTTP\Client\Curl $curl;


    /**
     * @param \Magento\Framework\Controller\Result\JsonFactory $jsonFactory
     */
    protected JsonFactory $jsonFactory;
    private $cartRepository;
    private Company $company;


    /**
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
     * @param array $data
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory $rateErrorFactory,
        \Psr\Log\LoggerInterface $logger,
        Security $xmlSecurity,
        \Magento\Shipping\Model\Simplexml\ElementFactory $xmlElFactory,
        \Magento\Shipping\Model\Rate\ResultFactory $rateFactory,
        \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory $rateMethodFactory,
        \Magento\Shipping\Model\Tracking\ResultFactory $trackFactory,
        \Magento\Shipping\Model\Tracking\Result\ErrorFactory $trackErrorFactory,
        \Magento\Shipping\Model\Tracking\Result\StatusFactory $trackStatusFactory,
        \Magento\Directory\Model\RegionFactory $regionFactory,
        \Magento\Directory\Model\CountryFactory $countryFactory,
        \Magento\Directory\Model\CurrencyFactory $currencyFactory,
        \Magento\Directory\Helper\Data $directoryData,
        \Magento\CatalogInventory\Api\StockRegistryInterface $stockRegistry,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\Module\Dir\Reader $configReader,
        \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory $productCollectionFactory,
        JsonFactory $jsonFactory,
        CurlFactory $curlFactory,
        array $data = []

    ) {

        $this->_storeManager = $storeManager;
        $this->_productCollectionFactory = $productCollectionFactory;
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
        $this->company = new Company();
    }


    /*
     * Gets the base url of the store by stripping the http:// or https:// and wwww. from the url
     * leaving just "example.com" since Bob Go API uses this format and not the full url as the Identifier
     * @var \Magento\Store\Model\StoreManagerInterface $this->_storeManager
     * @return string
     */
    public function getBaseUrl(): string
    {
        $storeBaseUrl = $this->_storeManager->getStore()->getBaseUrl();
        return parse_url($storeBaseUrl)["host"];
    }


    /**
     *  Make request to Bob Go API to get shipping rates for the cart
     * After all the required data is collected, it makes a request to the Bob Go API to get the shipping rates
     * @param $payload
     * @return array
     */
    public function getRates($payload): array
    {
        return $this->uRates($payload);
    }

    /**
     * Collect and get rates for this shipping method based on information in $request
     * This is a default function that is called by Magento to get the shipping rates for the cart
     * @param RateRequest $request
     * @return Result|bool|null
     */
    public function collectRates(RateRequest $request)
    {
        /*** Make sure that Shipping method is enabled*/
        if (!$this->isActive()) {
            return false;
        }
        /**
         * Gets the destination company name from Company Name field in the checkout page
         * This method is used is the last resort to get the company name since the company name is not available in _rateFactory
         */
        $destComp = $this->getDestComp();

        /** @var \Magento\Shipping\Model\Rate\Result $result */

        $result = $this->_rateFactory->create();

        $destination = $request->getDestPostcode();
        $destCountry = $request->getDestCountryId();
        $destRegion = $request->getDestRegionCode();
        $destCity = $request->getDestCity();
        $destStreet = $request->getDestStreet();

        /**  Destination Information  */
        [$destStreet1, $destStreet2, $destStreet3] = $this->destStreet($destStreet);

        /**  Origin Information  */
        [$originStreet, $originRegion, $originCountry, $originCity, $originStreet1, $originStreet2, $storeName, $baseIdentifier, $originSuburb] = $this->storeInformation();
        /**  Get all the items in the cart  */
        $items = $request->getAllItems();

        $itemsArray = [];

        foreach ($items as $item) {
            $itemsArray[] = [
                'sku' => $item->getSku(),
                'quantity' => $item->getQty(),
                'price' => $item->getPrice(),
                'grams' => $item->getWeight() * 1000,
            ];
        }

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
                    'suburb' => $destStreet3,
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
     * Get The store information from the Magento store configuration and return it as an array
     * In magento 2 there is origin information in the store information section of the configuration, so we get it from there
     * since Store Information has the origin information including suburb field that we Injected upon Bob Go Extension installation
     *  which is not available in the origin section
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

        $baseIdentifier = $this->getBaseUrl();
        return array($originStreet, $originRegion, $originCountry, $originCity, $originStreet1, $originStreet2, $storeName, $baseIdentifier, $originSuburb);
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
     * Interact with the
     * @param string $type
     * @param string $code
     * @return array|false
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function getCode($type, $code = '')
    {
        $codes = [
            'method' => [
                'bobgo_SHIPPING' => __('bobgo Shipping'),
            ],
            'unit_of_measure' => [
                'KG' => __('Kilograms'),
            ],
            'specificcountry' => [
                'ZA' => __('South Africa'),
            ],
            'allspecificcountries' => [
                'ZA' => __('South Africa'),
            ],
            'showmethod' => [
                '0' => __('No'),
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
     * Get tracking info by tracking number or tracking request
     * Without getTrackingInfo() method, Magento will not show tracking info on frontend
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
     * Set tracking request data for request by getting context from Magento
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
     * Send request for the actual tracking info and process response
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
     * Do shipment request to carrier web service, obtain Print Shipping Labels and process errors in response
     * Also another magic function that is required to be implemented by carrier model
     * @param \Magento\Framework\DataObject $request
     * @return \Magento\Framework\DataObject
     */
    protected function _doShipmentRequest(\Magento\Framework\DataObject $request)
    {
        return null;

    }

    /**
     * For multi package shipments. Delete requested shipments if the current shipment request is failed
     *
     * @param array $data
     *
     * @return bool
     */
    public function rollBack($data)
    {
        return true;
    }

    /**
     * Return container types of carrier
     *
     * @param \Magento\Framework\DataObject|null $params
     *
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
     * Return delivery confirmation types of carrier
     *
     * @param \Magento\Framework\DataObject|null $params
     *
     * @return array
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getDeliveryConfirmationTypes(\Magento\Framework\DataObject $params = null)
    {
        return $this->getCode('delivery_confirmation_types');
    }

    /**
     * Recursive replace sensitive fields in debug data by the mask
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
     * Parse track details response from Bob Go
     *
     * @return array
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     **/
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
     * Append error message to rate result instance
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
     * @param string $date
     * @return string
     */
    public function formatDate(string $date): string
    {
        return date('d M Y', strtotime($date));
    }

    /**
     * @param string $time
     * @return string
     */
    public function formatTime(string $time): string
    {
        return date('H:i', strtotime($time));
    }

    /**
     * @return string
     */
    private function getApiUrl(): string
    {
        return uData::RATES_ENDPOINT;
    }

    /**
     *  Perform API Request to Bob Go API and return response
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
     * Perform API Request for Shipment Tracking to Bob Go API and return response
     * @param $trackInfo
     * @param array $result
     * @return array
     */
    private function _requestTracking($trackInfo, array $result): array
    {
        $response = $this->trackbobgoShipment($trackInfo);

        $result = $this->prepareActivity($response[0], $result);

        return $result;
    }

    /**
     * Format rates from Bob Go API response and append to rate result instance of carrier
     * @param mixed $rates
     * @param Result $result
     * @return void
     */
    protected function _formatRates(mixed $rates, Result $result): void
    {
        if (empty($rates)) {
            $error = $this->_rateErrorFactory->create();
            $error->setCarrierTitle($this->getConfigData('title'));
            $error->setErrorMessage($this->getConfigData('specificerrmsg'));

            $result->append($error);
        } else {
            foreach ($rates['rates'] as $title) {

                $method = $this->_rateMethodFactory->create();
                if (isset($title)){
                    $method->setCarrier(self::CODE);


                    if ($this->getConfigData('additional_info') == 1) {
                        $min_delivery_date = $this->getWorkingDays(date('Y-m-d'), $title['min_delivery_date']);
                        $max_delivery_date = $this->getWorkingDays(date('Y-m-d'), $title['max_delivery_date']);

                        $this->deliveryDays($min_delivery_date, $max_delivery_date, $method);

                    } else {
                        $method->setCarrierTitle($this->getConfigData('title'));
                    }

                }

                $method->setMethod($title['service_code']);
                $method->setMethodTitle($title['service_name']);
                $method->setPrice($title['total_price'] / self::UNITS);
                $method->setCost($title['total_price'] / self::UNITS);

                $result->append($method);
            }
        }
    }


    /**
     * Prepare received checkpoints and activity from Bob Go Shipment Tracking API
     * @param $response
     * @param array $result
     * @return array
     */
    private function prepareActivity($response, array $result): array
    {

        foreach ($response['checkpoints'] as $checkpoint) {
            $result['progressdetail'][] = [
                'activity' => $checkpoint['status'],
                'deliverydate' => $this->formatDate($checkpoint['time']),
                'deliverytime' => $this->formatTime($checkpoint['time']),
              //  'deliverylocation' => 'Unavailable',//TODO: remove this line
            ];
        }
        return $result;
    }

    /**
     *  Get Working Days between time of checkout and delivery date (min and max)
     * @param string $startDate
     * @param string $endDate
     * @return int
     */
    public function getWorkingDays(string $startDate, string $endDate): int
    {
        $begin = strtotime($startDate);
        $end = strtotime($endDate);
        if ($begin > $end) {
//            echo "Start Date Cannot Be In The Future! <br />";
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
     * Curl request to Bob Go Shipment Tracking API
     * @param $trackInfo
     * @return mixed
     */
    private function trackbobgoShipment($trackInfo): mixed
    {
        $this->curl->get(uData::TRACKING . $trackInfo);

        $response = $this->curl->getBody();

        return json_decode($response, true);
    }

    /**
     * Build The Payload for Bob Go API Request and return response
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
     * @param string $destStreet
     * @return string[]
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
        return array($destStreet1, $destStreet2, $destStreet3);
    }

    /**
     * @param int $min_delivery_date
     * @param int $max_delivery_date
     * @param $method
     * @return void
     */
    protected function deliveryDays(int $min_delivery_date, int $max_delivery_date, $method): void
    {
        if ($min_delivery_date !== $max_delivery_date) {
            $method->setCarrierTitle('delivery in '.$min_delivery_date . ' - ' . $max_delivery_date . ' days');
        }else{
            $method->setCarrierTitle('delivery in ' . $min_delivery_date . ' days');
            if ($min_delivery_date && $max_delivery_date == 1) {
                $method->setCarrierTitle('delivery in '.$min_delivery_date . ' day');
            }
        }
    }

    /**
     * @return mixed|string
     */
    protected function getDestComp(): mixed
    {
        return $this->company->getDestComp();
    }
}
