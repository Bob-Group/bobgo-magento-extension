<?php
declare(strict_types=1);

namespace uafrica\Customshipping\Model\Carrier;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;
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
 * uAfrica shipping implementation
 * @category   uafrica
 * @package    uafrica_Customshipping
 * @author     info@bob.co.za
 * @website    https://www.bob.co.za
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyFields)
 */
class Customshipping extends AbstractCarrierOnline implements \Magento\Shipping\Model\Carrier\CarrierInterface
{
    /**
     * Code of the carrier
     *`
     * @var string
     */
    public const CODE = 'uafrica';

    /** Tracking Endpoint */
    const TRACKING = 'https://api.dev.ship.uafrica.com/tracking?channel=localhost&tracking_reference=';
    /*** RATES API Endpoint*/
//      const RATES_ENDPOINT = 'https://8390956f-c00b-497d-8742-87b1d6305bd2.mock.pstmn.io/putrates';
    const RATES_ENDPOINT = 'https://api.dev.ship.uafrica.com/rates-at-checkout/woocommerce';

    /**
     * Code of the carrier
     *
     * @var string
     */
    protected $_code = self::CODE;

    /**
     * Types of rates, order is important
     *
     * @var array
     */
    protected $_ratesOrder = [
        'RATED_ACCOUNT_PACKAGE',
        'PAYOR_ACCOUNT_PACKAGE',
        'RATED_ACCOUNT_SHIPMENT',
        'PAYOR_ACCOUNT_SHIPMENT',
        'RATED_LIST_PACKAGE',
        'PAYOR_LIST_PACKAGE',
        'RATED_LIST_SHIPMENT',
        'PAYOR_LIST_SHIPMENT',
    ];

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
     * Path to wsdl file of rate service
     *
     * @var string
     */
    protected $_rateServiceWsdl;

    /**
     * Path to wsdl file of ship service
     *
     * @var string
     */
    protected $_shipServiceWsdl = null;

    /**
     * Path to wsdl file of track service
     *
     * @var string
     */
    protected $_trackServiceWsdl = null;

    /**
     * Container types that could be customized for uAfrica carrier
     *
     * @var string[]
     */
    protected $_customizableContainerTypes = ['YOUR_PACKAGING'];

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
    }


    /**
     * Store Base Url
     * @var \Magento\Store\Model\StoreManagerInterface $this->_storeManager
     * @return string
     */
    public function getBaseUrl(): string
    {
        $storeBase = $this->_storeManager->getStore()->getBaseUrl();
        // Strip slashes and http:// or https:// and wwww. from the url leaving just "example.com"
        $storeBase = preg_replace('/(http:\/\/|https:\/\/|www\.)/', '', $storeBase);
        $storeBase = preg_replace('/(\/)/', '', $storeBase);
        return $storeBase;
    }


    /**
     *  Make request to uAfrica API to get shipping rates
     * @param $payload
     * @return array
     */
    public function getRates($payload): array
    {
        $response = $this->uRates($payload);

        return $response;
    }


    /**
     * Collect and get rates
     *
     * @param RateRequest $request
     * @return Result|bool|null
     */
    public function collectRates(RateRequest $request)
    {
       /*** Make sure that Shipping method is enabled*/
        if (!$this->isActive()) {
            return false;
        }

        /** @var \Magento\Shipping\Model\Rate\Result $result */

        $result = $this->_rateFactory->create();

        $destination = $request->getDestPostcode();
        $destCountry = $request->getDestCountryId();
        $destRegion = $request->getDestRegionCode();
        $destCity = $request->getDestCity();
        $destStreet = $request->getDestStreet();
        $destStreet1 = $destStreet;
        $destStreet2 = $destStreet;

        //Get all the origin data from the request
        /**  Origin Information  */
        list($originStreet, $originRegion, $originCity, $originStreet1, $originStreet2, $storeName, $storeEmail, $storePhoneNumber, $baseIdentifier) = $this->storeInformation();




        $items = $request->getAllItems();

        $itemsArray = [];

        foreach ($items as $item) {
            $itemsArray[] = [
                'name' => $item->getName(),
                'sku' => $item->getSku(),
                'quantity' => $item->getQty(),
                'price' => $item->getPrice(),
                'grams' => $item->getWeight() * 1000,
                'requires_shipping' => $item->getIsVirtual(),
                'taxable' => true,
                'fulfillment_service' => 'manual',
                'properties' => [],
                'vendor' => $item->getName(),
                'product_id' => $item->getProductId(),
                'variant_id' => $item->getProduct()->getId()
            ];
        }

        $payload = [
            'identifier' => $baseIdentifier,
            'rate' => [
                'origin' => [
                    'country' => 'ZA',
                    'postal_code' => $originStreet,
                    'province' => $originRegion,
                    'city' => $originCity,
                    'name' => $storeName,
                    'address1' => $originStreet1,
                    'address2' => $originStreet2,
                    'address3' => '',
                    'phone' => $storePhoneNumber,
                    'fax' => '',
                    'email' => $storeEmail,
                    'address_type' => '',
                    'company_name' => $storeName
                ],
                'destination' => [
                    'country' => $destCountry,
                    'postal_code' => $destination,
                    'province' => $destRegion,
                    'city' => $destCity,
                    'name' => 'Brian Singh',
                    'address1' => $destStreet1,
                    'address2' => $destStreet2,
                    'address3' => '',
                    'phone' => '',
                    'fax' => '',
                    'email' => '',
                    'address_type' => '',
                    'company_name' => ''
                ],
                'items' => $itemsArray,
                'currency' => '',
                'locale' => 'en-PT'
            ]
        ];

        $this->_getRates($payload, $result);

        return $result;
    }


    /**
     * @return array
     */
    public function storeInformation(): array
    {
        /** Store Origin details */

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

        $storeEmail = $this->_scopeConfig->getValue(
            'general/store_information/email',
            ScopeInterface::SCOPE_STORE
        );

        $storePhoneNumber = $this->_scopeConfig->getValue(
            'general/store_information/phone',
            ScopeInterface::SCOPE_STORE
        );

        $baseIdentifier = $this->getBaseUrl();
        return array($originStreet, $originRegion, $originCity, $originStreet1, $originStreet2, $storeName, $storeEmail, $storePhoneNumber, $baseIdentifier);
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
            return $cost + $cost * $this->_numBoxes * $handlingFee / 100;
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
            return $cost + $cost * $handlingFee / 100;
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
                'UAFRICA_SHIPPING' => __('uAfrica Shipping'),
            ],
            'delivery_confirmation_types' => [
                'NO_SIGNATURE_REQUIRED' => __('Not Required'),
                'ADULT' => __('Adult'),
                'DIRECT' => __('Direct'),
                'INDIRECT' => __('Indirect'),
            ],
            'unit_of_measure' => [
                'KG' => __('Kilograms'),
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
        foreach ($trackingValue as $item) {
            $tracking = $this->_trackStatusFactory->create();

            $tracking->setCarrier(self::CODE);
            $tracking->setCarrierTitle($carrierTitle);
            $tracking->setUrl(self::TRACKING.$item);
            $tracking->setTracking($item);
            $tracking->addData($this->processTrackingDetails($item));
            $result->append($tracking);
            $counter ++;
        }

        // no available tracking details
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
     *
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
     * Parse track details response from uAfrica
     *
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
     * Append error message to rate result instance
     *
     * @param string $trackingValue
     * @param string $errorMessage
     */
    private function appendTrackingError($trackingValue, $errorMessage)
    {
        $error = $this->_trackErrorFactory->create();
        $error->setCarrier('uafrica');
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
        return self::RATES_ENDPOINT;
    }

    /**
     *  Perfom API Request to uAfrica API and return response
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
     * Perform API Request for Shipment Tracking to uAfrica API and return response
     * @param $trackInfo
     * @param array $result
     * @return array
     */
    private function _requestTracking($trackInfo, array $result): array
    {
        $response = $this->trackUafricaShipment($trackInfo);

        $result = $this->prepareActivity($response[0], $result);

        return $result;
    }

    /**
     * Format rates from uAfrica API response and append to rate result instance of carrier
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
            foreach ($rates['rates'] as $code => $title) {

                $method = $this->_rateMethodFactory->create();
                $method->setCarrier('uafrica');
                if ($this->getConfigData('additional_info') == 1) {
                    $min_delivery_date = $this->getWorkingDays(date('Y-m-d'), $title['min_delivery_date']);
                    $max_delivery_date = $this->getWorkingDays(date('Y-m-d'), $title['max_delivery_date']);
                    $method->setCarrierTitle('Delivery in ' . $min_delivery_date .' - ' . $max_delivery_date .' Business Days');

                } else {
                    $method->setCarrierTitle($this->getConfigData('title'));
                }

                $method->setMethod($title['service_code']);
                $method->setMethodTitle($title['service_name']);
                $method->setPrice($title['total_price'] / 100);
                $method->setCost($title['total_price'] / 100);

                $result->append($method);
            }
        }
    }


    /**
     * Prepare received checkpoints and activity from uAfrica Shipment Tracking API
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
                'deliverylocation' => 'Unavailable',
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
     * Curl request to uAfrica Shipment Tracking API
     * @param $trackInfo
     * @return mixed
     */
    private function trackUafricaShipment($trackInfo): mixed
    {
        $this->curl->get(self::TRACKING . $trackInfo);

        $response = $this->curl->getBody();

        $response = json_decode($response, true);
        return $response;
    }

    /**
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
}
