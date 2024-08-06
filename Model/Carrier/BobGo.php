<?php
//declare(strict_types=1);
//
//namespace BobGroup\BobGo\Model\Carrier;
//
//use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
//use Magento\CatalogInventory\Api\StockRegistryInterface;
//use Magento\Checkout\Api\Data\ShippingInformationInterface;
//use Magento\Directory\Helper\Data;
//use Magento\Directory\Model\CountryFactory;
//use Magento\Directory\Model\CurrencyFactory;
//use Magento\Directory\Model\RegionFactory;
//use Magento\Framework\App\Config\ScopeConfigInterface;
//use Magento\Framework\Controller\Result\JsonFactory;
//use Magento\Framework\DataObject;
//use Magento\Framework\Exception\LocalizedException;
//use Magento\Framework\HTTP\Client\CurlFactory;
//use Magento\Framework\Module\Dir\Reader;
//use Magento\Framework\Xml\Security;
//use Magento\Quote\Model\Quote\Address\RateRequest;
//use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
//use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
//use Magento\Sales\Model\Order\Shipment;
//use Magento\Shipping\Model\Carrier\AbstractCarrier;
//use Magento\Shipping\Model\Carrier\AbstractCarrierOnline;
//use Magento\Shipping\Model\Rate\Result;
//use Magento\Shipping\Model\Rate\ResultFactory;
//use Magento\Shipping\Model\Simplexml\ElementFactory;
//use Magento\Shipping\Model\Tracking\Result\StatusFactory;
//use Magento\Store\Model\ScopeInterface;
//use Magento\Store\Model\StoreManagerInterface;
//use Psr\Log\LoggerInterface;
//use Mage;
//use Mage_Shipping_Model_Carrier_Abstract;
//use Mage_Shipping_Model_Carrier_Interface;
//use Varien_Object;
//
//
///**
// * Bob Go shipping implementation
// * @category   Bob Go
// * @package    bobgo_CustomShipping
// * @author     Bob Go
// * @website    https://www.bobgo.co.za
// * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
// * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
// * @SuppressWarnings(PHPMD.TooManyFields)
// */
//
//class BobGo extends Mage_Shipping_Model_Carrier_Abstract implements Mage_Shipping_Model_Carrier_Interface
//{
//    /**
//     * Code of the carrier
//     * @var string
//     */
//    public const CODE = 'bobgo';
//
//    const UNITS = 100;
//
//    /**
//     * Code of the carrier
//     *
//     * @var string
//     */
//    protected $_code = self::CODE;
//
//
//    /**
//     * Rate request data
//     *
//     * @var RateRequest|null
//     */
//    protected $_request = null;
//
//    /**
//     * Rate result data
//     *
//     * @var Result|null
//     */
//    protected $_result = null;
//
//    /**
//     * Container types that could be customized for bobgo carrier
//     *
//     * @var string[]
//     */
//    protected $_customizableContainerTypes = ['YOUR_PACKAGING'];
//
//    /**
//     * @var \Magento\Store\Model\StoreManagerInterface
//     */
//    protected $_storeManager;
//
//    /**
//     * @var \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory
//     */
//    protected $_productCollectionFactory;
//
//
//    /**
//     * @var DataObject
//     */
//    private $_rawTrackingRequest;
//
//    /**
//     * @var \Magento\Framework\HTTP\Client\Curl
//     */
//    protected \Magento\Framework\HTTP\Client\Curl $curl;
//
//
//    /**
//     * @param \Magento\Framework\Controller\Result\JsonFactory $jsonFactory
//     */
//    protected JsonFactory $jsonFactory;
//    private $cartRepository;
//    public AdditionalInfo $additionalInfo;
//
//
//    /**
//     * @param ScopeConfigInterface $scopeConfig
//     * @param ErrorFactory $rateErrorFactory
//     * @param LoggerInterface $logger
//     * @param Security $xmlSecurity
//     * @param ElementFactory $xmlElFactory
//     * @param ResultFactory $rateFactory
//     * @param MethodFactory $rateMethodFactory
//     * @param \Magento\Shipping\Model\Tracking\ResultFactory $trackFactory
//     * @param \Magento\Shipping\Model\Tracking\Result\ErrorFactory $trackErrorFactory
//     * @param StatusFactory $trackStatusFactory
//     * @param RegionFactory $regionFactory
//     * @param CountryFactory $countryFactory
//     * @param CurrencyFactory $currencyFactory
//     * @param Data $directoryData
//     * @param StockRegistryInterface $stockRegistry
//     * @param StoreManagerInterface $storeManager
//     * @param Reader $configReader
//     * @param CollectionFactory $productCollectionFactory
//     * @param JsonFactory $jsonFactory
//     * @param CurlFactory $curlFactory
//     * @param array $data
//     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
//     */
//    public function __construct(
//        \Magento\Framework\App\Config\ScopeConfigInterface             $scopeConfig,
//        \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory     $rateErrorFactory,
//        \Psr\Log\LoggerInterface                                       $logger,
//        Security                                                       $xmlSecurity,
//        \Magento\Shipping\Model\Simplexml\ElementFactory               $xmlElFactory,
//        \Magento\Shipping\Model\Rate\ResultFactory                     $rateFactory,
//        \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory    $rateMethodFactory,
//        \Magento\Shipping\Model\Tracking\ResultFactory                 $trackFactory,
//        \Magento\Shipping\Model\Tracking\Result\ErrorFactory           $trackErrorFactory,
//        \Magento\Shipping\Model\Tracking\Result\StatusFactory          $trackStatusFactory,
//        \Magento\Directory\Model\RegionFactory                         $regionFactory,
//        \Magento\Directory\Model\CountryFactory                        $countryFactory,
//        \Magento\Directory\Model\CurrencyFactory                       $currencyFactory,
//        \Magento\Directory\Helper\Data                                 $directoryData,
//        \Magento\CatalogInventory\Api\StockRegistryInterface           $stockRegistry,
//        \Magento\Store\Model\StoreManagerInterface                     $storeManager,
//        \Magento\Framework\Module\Dir\Reader                           $configReader,
//        \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory $productCollectionFactory,
//        JsonFactory                                                    $jsonFactory,
//        CurlFactory                                                    $curlFactory,
//        array                                                          $data = []
//    )
//    {
//
//        $this->_storeManager = $storeManager;
//        $this->_productCollectionFactory = $productCollectionFactory;
//        parent::__construct(
//            $scopeConfig,
//            $rateErrorFactory,
//            $logger,
//            $xmlSecurity,
//            $xmlElFactory,
//            $rateFactory,
//            $rateMethodFactory,
//            $trackFactory,
//            $trackErrorFactory,
//            $trackStatusFactory,
//            $regionFactory,
//            $countryFactory,
//            $currencyFactory,
//            $directoryData,
//            $stockRegistry,
//            $data
//        );
//        $this->jsonFactory = $jsonFactory;
//        $this->curl = $curlFactory->create();
//        $this->additionalInfo = new AdditionalInfo($countryFactory);
//    }
//
//
//    /*
//     * Gets the base url of the store by stripping the http:// or https:// and wwww. from the url
//     * leaving just "example.com" since Bob Go API uses this format and not the full url as the Identifier
//     * @var \Magento\Store\Model\StoreManagerInterface $this->_storeManager
//     * @return string
//     */
//    public function getBaseUrl(): string
//    {
//        $storeBase = $this->_storeManager->getStore()->getBaseUrl();
//        return parse_url($storeBase, PHP_URL_HOST);
//    }
//
//
//    /**
//     *  Make request to Bob Go API to get shipping rates for the cart
//     * After all the required data is collected, it makes a request to the Bob Go API to get the shipping rates
//     * @param $payload
//     * @return array
//     */
//    public function getRates($payload): array
//    {
//        return $this->uRates($payload);
//    }
//
//    /**
//     * Processing additional validation to check if carrier applicable.
//     *
//     * @param \Magento\Framework\DataObject $request
//     * @return $this|bool|\Magento\Framework\DataObject
//     */
//    public function processAdditionalValidation(\Magento\Framework\DataObject $request)
//    {
//        if (!count($this->getAllItems($request))) {
//            return false;
//        }
//
//        $maxAllowedWeight = 500; //$this->getConfigData('max_package_weight');
//        $errorMsg = '';
//        $configErrorMsg = $this->getConfigData('specificerrmsg');
//        $defaultErrorMsg = __('The shipping module is not available.');
//        $showMethod = $this->getConfigData('showmethod');
//
//        /** @var $item \Magento\Quote\Model\Quote\Item */
//        foreach ($this->getAllItems($request) as $item) {
//            $product = $item->getProduct();
//            if ($product && $product->getId()) {
//                $weight = $product->getWeight();
//                $stockItemData = $this->stockRegistry->getStockItem(
//                    $product->getId(),
//                    $item->getStore()->getWebsiteId()
//                );
//                $doValidation = true;
//
//                if ($stockItemData->getIsQtyDecimal() && $stockItemData->getIsDecimalDivided()) {
//                    if ($stockItemData->getEnableQtyIncrements() && $stockItemData->getQtyIncrements()
//                    ) {
//                        $weight = $weight * $stockItemData->getQtyIncrements();
//                    } else {
//                        $doValidation = false;
//                    }
//                } elseif ($stockItemData->getIsQtyDecimal() && !$stockItemData->getIsDecimalDivided()) {
//                    $weight = $weight * $item->getQty();
//                }
//
//
//                if ($doValidation && $weight > $maxAllowedWeight) {
//                    $errorMsg = $configErrorMsg ? $configErrorMsg : $defaultErrorMsg;
//                    break;
//                }
//            }
//        }
//
//        if (!$errorMsg && !$request->getDestPostcode() && $this->isZipCodeRequired($request->getDestCountryId())) {
//
//            $errorMsg = __('This shipping method is not available. Please specify the zip code.');
//        }
//
//        if ($request->getDestCountryId() == 'ZA') {
//            $errorMsg = '';
//        } else {
//            $errorMsg = $configErrorMsg ? $configErrorMsg : $defaultErrorMsg;
//        }
//
//        if ($errorMsg && $showMethod) {
//            $error = $this->_rateErrorFactory->create();
//            $error->setCarrier($this->_code);
//            $error->setCarrierTitle($this->getConfigData('title'));
//            $error->setErrorMessage($errorMsg);
//
//            return $error;
//        } elseif ($errorMsg) {
//            return false;
//        }
//
//        return $this;
//    }
//
//    /**
//     * Collect and get rates for this shipping method based on information in $request
//     * This is a default function that is called by Magento to get the shipping rates for the cart
//     * @param RateRequest $request
//     * @return Result|bool|null
//     */
////    public function collectRates(RateRequest $request)
////    {
////        if (!$this->getConfigFlag('active')) {
////            Mage::log('BobGo is not active', null, 'shipping.log');
////            return false;
////        }
////
////        $result = Mage::getModel('shipping/rate_result');
////        $rate = Mage::getModel('shipping/rate_result_method');
////        $rate->setCarrier('bobgo');
////        $rate->setCarrierTitle($this->getConfigData('title'));
////
////        $result->append($rate);
////
////        Mage::log('BobGo rates collected', null, 'shipping.log');
////
////
////        /**
////         * Gets the destination company name from AdditionalInfo Name field in the checkout page
////         * This method is used is the last resort to get the company name since the company name is not available in _rateFactory
////         */
////        $destComp = $this->getDestComp();
////        $destSuburb = $this->getDestSuburb();
////        $destPhone = $this->getDestTelephone();
////
////        /** @var \Magento\Shipping\Model\Rate\Result $result */
////
////        $result = $this->_rateFactory->create();
////
////        $destination = $request->getDestPostcode();
////        $destCountryCode = $request->getDestCountryId();
////        $destRegion = $request->getDestRegionCode();
////        $destCity = $request->getDestCity();
////        $destStreet = $request->getDestStreet();
////
////        $destCountry = $this->getCountryName($destCountryCode);
////
////        /**  Destination Information  */
////        [$destStreet1, $destStreet2, $destStreet3] = $this->destStreet($destStreet);
////
////
////        /**  Origin Information  */
////        [$originStreet, $originRegion, $originCountryCode, $originCity, $originStreet1, $originStreet2, $storeName, $baseIdentifier, $originSuburb, $weightUnit, $originPhone] = $this->storeInformation();
////
////        /**  Get all items in cart  */
////        $items = $request->getAllItems();
////        $itemsArray = [];
////        $itemsArray = $this->getStoreItems($items, $weightUnit, $itemsArray);
////
////        $originCountry = $this->getCountryName($originCountryCode);
////
////
////        $payload = [
////            'identifier' => $baseIdentifier,
////            'rate' => [
////                'origin' => [
////                    'company' => $storeName,
////                    'address1' => $originStreet1,
////                    'address2' => $originStreet2,
////                    'city' => $originCity,
////                    'suburb' => $originSuburb,
////                    'province' => $originRegion,
////                    'country_code' => $originCountryCode,
////                    'postal_code' => $originStreet,
////                    'telephone' => $originPhone,
////                    'country' => $originCountry,
////
////                ],
////                'destination' => [
////                    'company' => $destComp,
////                    'address1' => $destStreet1,
////                    'address2' => $destStreet2,
////                    'suburb' => $destSuburb,
////                    'city' => $destCity,
////                    'province' => $destRegion,
////                    'country_code' => $destCountryCode,
////                    'postal_code' => $destination,
////                    'telephone' => $destPhone,
////                    'country' => $destCountry
////                ],
////                'items' => $itemsArray,
////            ]
////        ];
////
////        $this->_getRates($payload, $result);
////
////        return $result;
////    }
//
//    public function collectRates(Mage_Shipping_Model_Rate_Request $request)
//    {
//        if (!$this->getConfigFlag('active')) {
//            Mage::log('BobGo is not active', null, 'shipping.log');
//            return false;
//        }
//
//        $result = Mage::getModel('shipping/rate_result');
//
//        // Prepare request data for API call
//        $params = [
//            'dest_country_id' => $request->getDestCountryId(),
//            'dest_region_id' => $request->getDestRegionId(),
//            'dest_postcode' => $request->getDestPostcode(),
//            'package_weight' => $request->getPackageWeight(),
//            'package_value' => $request->getPackageValue(),
//            'package_qty' => $request->getPackageQty(),
//        ];
//
//        try {
//            $apiResponse = $this->_fetchRatesFromApi($params);
//            if ($apiResponse && isset($apiResponse['rates'])) {
//                foreach ($apiResponse['rates'] as $rateData) {
//                    $rate = Mage::getModel('shipping/rate_result_method');
//                    $rate->setCarrier($this->_code);
//                    $rate->setCarrierTitle($this->getConfigData('title'));
//                    $rate->setMethod($rateData['method']);
//                    $rate->setMethodTitle($rateData['method_title']);
//                    $rate->setPrice($rateData['price']);
//                    $rate->setCost($rateData['cost']);
//                    $result->append($rate);
//                }
//            } else {
//                Mage::log('No rates returned from API', null, 'shipping.log');
//            }
//        } catch (Exception $e) {
//            Mage::logException($e);
//            Mage::log('Error fetching rates from API: ' . $e->getMessage(), null, 'shipping.log');
//        }
//
//        return $result;
//    }
//
//    protected function _fetchRatesFromApi($params)
//    {
//        $url = \BobGroup\BobGo\Model\Carrier\uData::RATES_ENDPOINT;
//        $client = new Varien_Http_Client($url);
//        $client->setMethod(Varien_Http_Client::POST);
//        $client->setRawData(json_encode($params), 'application/json');
//
//        try {
//            $response = $client->request();
//            if ($response->isSuccessful()) {
//                return json_decode($response->getBody(), true);
//            } else {
//                Mage::log('API request failed with status: ' . $response->getStatus(), null, 'shipping.log');
//            }
//        } catch (Exception $e) {
//            Mage::logException($e);
//            Mage::log('Exception during API request: ' . $e->getMessage(), null, 'shipping.log');
//        }
//
//        return false;
//    }
//
//    /**
//     * @return array
//     */
//    public function storeInformation(): array
//    {
//        /** Store Origin details */
//        $originCountryCode = $this->_scopeConfig->getValue(
//            'general/store_information/country_id',
//            ScopeInterface::SCOPE_STORE
//        );
//        $originRegion = $this->_scopeConfig->getValue(
//            'general/store_information/region_id',
//            ScopeInterface::SCOPE_STORE
//        );
//        $originCity = $this->_scopeConfig->getValue(
//            'general/store_information/city',
//            ScopeInterface::SCOPE_STORE
//        );
//
//        $originStreet = $this->_scopeConfig->getValue(
//            'general/store_information/postcode',
//            ScopeInterface::SCOPE_STORE
//        );
//
//        $originStreet1 = $this->_scopeConfig->getValue(
//            'general/store_information/street_line1',
//            ScopeInterface::SCOPE_STORE
//        );
//
//        $originStreet2 = $this->_scopeConfig->getValue(
//            'general/store_information/street_line2',
//            ScopeInterface::SCOPE_STORE
//        );
//
//        $storeName = $this->_scopeConfig->getValue(
//            'general/store_information/name',
//            ScopeInterface::SCOPE_STORE
//        );
//
//        $originSuburb = $this->_scopeConfig->getValue(
//            'general/store_information/suburb',
//            ScopeInterface::SCOPE_STORE
//        );
//
//        $weightUnit = $this->_scopeConfig->getValue(
//            'general/locale/weight_unit',
//            ScopeInterface::SCOPE_STORE
//        );
//
//        $originTelephone = $this->_scopeConfig->getValue(
//            'general/store_information/phone',
//            ScopeInterface::SCOPE_STORE
//        );
//
//        $baseIdentifier = $this->getBaseUrl();
//
//        return array($originStreet, $originRegion, $originCountryCode, $originCity, $originStreet1, $originStreet2, $storeName, $baseIdentifier, $originSuburb, $weightUnit, $originTelephone);
//    }
//
//
//    /**
//     * Get result of request
//     *
//     * @return Result|null
//     */
//    public function getResult()
//    {
//        if (!$this->_result) {
//            $this->_result = $this->_trackFactory->create();
//        }
//        return $this->_result;
//    }
//
//
//    /**
//     * Get final price for shipping method with handling fee per package
//     *
//     * @param float $cost
//     * @param string $handlingType
//     * @param float $handlingFee
//     * @return float
//     */
//    protected function _getPerpackagePrice($cost, $handlingType, $handlingFee)
//    {
//        if ($handlingType == AbstractCarrier::HANDLING_TYPE_PERCENT) {
//            return $cost + $cost * $this->_numBoxes * $handlingFee / self::UNITS;
//        }
//
//        return $cost + $this->_numBoxes * $handlingFee;
//    }
//
//    /**
//     * Get final price for shipping method with handling fee per order
//     *
//     * @param float $cost
//     * @param string $handlingType
//     * @param float $handlingFee
//     * @return float
//     */
//    protected function _getPerorderPrice($cost, $handlingType, $handlingFee)
//    {
//        if ($handlingType == self::HANDLING_TYPE_PERCENT) {
//            return $cost + $cost * $handlingFee / self::UNITS;
//        }
//
//        return $cost + $handlingFee;
//    }
//
//
//    /**
//     * Get configuration data of carrier
//     *
//     * @param string $type
//     * @param string $code
//     * @return array|false
//     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
//     */
//    public function getCode($type, $code = '')
//    {
//        $codes = [
//            'method' => [
//                'bobGo' => __('BobGo'),
//            ],
//            'unit_of_measure' => [
//                'KGS' => __('Kilograms'),
//                'LBS' => __('Pounds'),
//            ],
//        ];
//
//        if (!isset($codes[$type])) {
//            return false;
//        } elseif ('' === $code) {
//            return $codes[$type];
//        }
//
//        if (!isset($codes[$type][$code])) {
//            return false;
//        } else {
//            return $codes[$type][$code];
//        }
//    }
//
//
//    /**
//     * Get tracking
//     *
//     * @param string|string[] $trackings
//     * @return Result|null
//     */
//    public function getTracking($trackings)
//    {
//        $this->setTrackingRequest();
//
//        if (!is_array($trackings)) {
//            $trackings = [$trackings];
//        }
//
//        foreach ($trackings as $tracking) {
//            $this->_getXMLTracking($tracking);
//        }
//
//        return $this->_result;
//    }
//
//    /**
//     * Set tracking request
//     *
//     * @return void
//     */
//    protected function setTrackingRequest()
//    {
//        $r = new \Magento\Framework\DataObject();
//
//        $account = $this->getConfigData('account');
//        $r->setAccount($account);
//
//        $this->_rawTrackingRequest = $r;
//    }
//
//    /**
//     * Send request for tracking
//     *
//     * @param string[] $tracking
//     * @return void
//     */
//    protected function _getXMLTracking($tracking)
//    {
//        $this->_parseTrackingResponse($tracking);
//    }
//
//    /**
//     * Parse tracking response
//     *
//     * @param string $trackingValue
//     * @return void
//     */
//    protected function _parseTrackingResponse($trackingValue)
//    {
//        $result = $this->getResult();
//        $carrierTitle = $this->getConfigData('title');
//        $counter = 0;
//        if (!is_array($trackingValue)) {
//            $trackingValue = [$trackingValue];
//        }
//        foreach ($trackingValue as $trackingReference) {
//            $tracking = $this->_trackStatusFactory->create();
//
//            $tracking->setCarrier(self::CODE);
//            $tracking->setCarrierTitle($carrierTitle);
//
//            //Production
//            /*   $tracking->setUrl(sprintf(uData::TRACKING, $this->getBaseUrl(), $trackingReference));
//            $tracking->setTracking($trackingReference);
//            $tracking->addData($this->processTrackingDetails($trackingReference));
//           */
//
//            //Dev
//            $tracking->setUrl(uData::TRACKING . $trackingReference);
//            $tracking->setTracking($trackingReference);
//            $tracking->addData($this->processTrackingDetails($trackingReference));
//
//            $result->append($tracking);
//            $counter++;
//        }
//
//        //Tracking Details Not Available
//        if (!$counter) {
//            $this->appendTrackingError(
//                $trackingValue,
//                __('For some reason we can\'t retrieve tracking info right now.')
//            );
//        }
//    }
//
//    /**
//     * Get tracking response
//     *
//     * @return string
//     */
//    public function getResponse()
//    {
//        $statuses = '';
//        if ($this->_result instanceof \Magento\Shipping\Model\Tracking\Result) {
//            if ($trackings = $this->_result->getAllTrackings()) {
//                foreach ($trackings as $tracking) {
//                    if ($data = $tracking->getAllData()) {
//                        if (!empty($data['status'])) {
//                            $statuses .= __($data['status']) . "\n<br/>";
//                        } else {
//                            $statuses .= __('Empty response') . "\n<br/>";
//                        }
//                    }
//                }
//            }
//        }
//        // phpstan:ignore
//        if (empty($statuses)) {
//            $statuses = __('Empty response');
//        }
//
//        return $statuses;
//    }
//
//    /**
//     * Get allowed shipping methods
//     *
//     * @return array
//     */
//    public function getAllowedMethods()
//    {
//        return ['bobgo' => $this->getConfigData('name')];
////        $allowed = explode(',', $this->getConfigData('allowed_methods'));
////        $arr = [];
////        foreach ($allowed as $k) {
////            $arr[$k] = $this->getCode('method', $k);
////        }
////
////        return $arr;
//    }
//
//    // Method to check if tracking is available
//    public function isTrackingAvailable()
//    {
//        return true;
//    }
//
//
//    /**
//     * Do shipment request to carrier web service, obtain Print Shipping Labels and process errors in response
//     * Also another magic function that is required to be implemented by carrier model
//     * @param \Magento\Framework\DataObject $request
//     * @return \Magento\Framework\DataObject
//     */
//    protected function _doShipmentRequest(\Magento\Framework\DataObject $request)
//    {
//        return null;
//
//    }
//
//    /**
//     * For multi package shipments. Delete requested shipments if the current shipment request is failed
//     *
//     * @param array $data
//     *
//     * @return bool
//     */
//    public function rollBack($data)
//    {
//        return true;
//    }
//
//    /**
//     * Return container types of carrier
//     *
//     * @param \Magento\Framework\DataObject|null $params
//     *
//     * @return array|bool
//     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
//     */
//    public function getContainerTypes(\Magento\Framework\DataObject $params = null)
//    {
//        $result = [];
//        $allowedContainers = $this->getConfigData('containers');
//        if ($allowedContainers) {
//            $allowedContainers = explode(',', $allowedContainers);
//        }
//        if ($allowedContainers) {
//            foreach ($allowedContainers as $container) {
//                $result[$container] = $this->getCode('container_types', $container);
//            }
//        }
//
//        return $result;
//    }
//
//    /**
//     * Return delivery confirmation types of carrier
//     *
//     * @param \Magento\Framework\DataObject|null $params
//     *
//     * @return array
//     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
//     */
//    public function getDeliveryConfirmationTypes(\Magento\Framework\DataObject $params = null)
//    {
//        return $this->getCode('delivery_confirmation_types');
//    }
//
//    /**
//     * Recursive replace sensitive fields in debug data by the mask
//     *
//     * @param string $data
//     * @return string
//     */
//    protected function filterDebugData($data)
//    {
//        foreach (array_keys($data) as $key) {
//            if (is_array($data[$key])) {
//                $data[$key] = $this->filterDebugData($data[$key]);
//            } elseif (in_array($key, $this->_debugReplacePrivateDataKeys)) {
//                $data[$key] = self::DEBUG_KEYS_MASK;
//            }
//        }
//        return $data;
//    }
//
//    /**
//     * Parse track details response from Bob Go
//     *
//     * @return array
//     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
//     * @SuppressWarnings(PHPMD.NPathComplexity)
//     **/
//    private function processTrackingDetails($trackInfo): array
//    {
//        $result = [
//            'shippeddate' => null,
//            'deliverydate' => null,
//            'deliverytime' => null,
//            'deliverylocation' => null,
//            'weight' => null,
//            'progressdetail' => [],
//        ];
//
//        $result = $this->_requestTracking($trackInfo, $result);
//
//        return $result;
//    }
//
//    /**
//     * Append error message to rate result instance
//     *
//     * @param string $trackingValue
//     * @param string $errorMessage
//     */
//    private function appendTrackingError($trackingValue, $errorMessage)
//    {
//        $error = $this->_trackErrorFactory->create();
//        $error->setCarrier(self::CODE);
//        $error->setCarrierTitle($this->getConfigData('title'));
//        $error->setTracking($trackingValue);
//        $error->setErrorMessage($errorMessage);
//        $result = $this->getResult();
//        $result->append($error);
//    }
//
//    /**
//     * @param string $date
//     * @return string
//     */
//    public function formatDate(string $date): string
//    {
//        return date('d M Y', strtotime($date));
//    }
//
//    /**
//     * @param string $time
//     * @return string
//     */
//    public function formatTime(string $time): string
//    {
//        return date('H:i', strtotime($time));
//    }
//
//    /**
//     * @return string
//     */
//    private function getApiUrl(): string
//    {
//        return uData::RATES_ENDPOINT;
//    }
//
//    /**
//     *  Perfom API Request to bobgo API and return response
//     * @param array $payload
//     * @param Result $result
//     * @return void
//     */
//    protected function _getRates(array $payload, Result $result): void
//    {
//
//        $rates = $this->uRates($payload);
//
//        $this->_formatRates($rates, $result);
//
//    }
//
//    /**
//     * Perform API Request for Shipment Tracking to Bob Go API and return response
//     * @param $trackInfo
//     * @param array $result
//     * @return array
//     */
//    private function _requestTracking($trackInfo, array $result): array
//    {
//        $response = $this->trackbobgoShipment($trackInfo);
//
//        $result = $this->prepareActivity($response[0], $result);
//
//        return $result;
//    }
//
//    /**
//     * Format rates from Bob Go API response and append to rate result instance of carrier
//     * @param mixed $rates
//     * @param Result $result
//     * @return void
//     */
//    protected function _formatRates(mixed $rates, Result $result): void
//    {
//        if (!isset($rates['rates'])) {
//            $error = $this->_rateErrorFactory->create();
//            $error->setCarrierTitle($this->getConfigData('title'));
//            $error->setErrorMessage($this->getConfigData('specificerrmsg'));
//
//            $result->append($error);
//        } else {
//            foreach ($rates['rates'] as $title) {
//
//                $method = $this->_rateMethodFactory->create();
//                if (isset($title)) {
//                    $method->setCarrier(self::CODE);
//
//
//                    if ($this->getConfigData('additional_info') == 1) {
//                        $min_delivery_date = $this->getWorkingDays(date('Y-m-d'), $title['min_delivery_date']);
//                        $max_delivery_date = $this->getWorkingDays(date('Y-m-d'), $title['max_delivery_date']);
//
//                        $this->deliveryDays($min_delivery_date, $max_delivery_date, $method);
//
//                    } else {
//                        $method->setCarrierTitle($this->getConfigData('title'));
//                    }
//
//                }
//
//                $method->setMethod($title['service_code']);
//                $method->setMethodTitle($title['service_name']);
//                $method->setPrice($title['total_price'] / self::UNITS);
//                $method->setCost($title['total_price'] / self::UNITS);
//
//                $result->append($method);
//            }
//        }
//    }
//
//
//    /**
//     * Prepare received checkpoints and activity from Bob Go Shipment Tracking API
//     * @param $response
//     * @param array $result
//     * @return array
//     */
//    private function prepareActivity($response, array $result): array
//    {
//
//        foreach ($response['checkpoints'] as $checkpoint) {
//            $result['progressdetail'][] = [
//                'activity' => $checkpoint['status'],
//                'deliverydate' => $this->formatDate($checkpoint['time']),
//                'deliverytime' => $this->formatTime($checkpoint['time']),
//                //  'deliverylocation' => 'Unavailable',//TODO: remove this line
//            ];
//        }
//        return $result;
//    }
//
//    /**
//     *  Get Working Days between time of checkout and delivery date (min and max)
//     * @param string $startDate
//     * @param string $endDate
//     * @return int
//     */
//    public function getWorkingDays(string $startDate, string $endDate): int
//    {
//        $begin = strtotime($startDate);
//        $end = strtotime($endDate);
//        if ($begin > $end) {
////            echo "Start Date Cannot Be In The Future! <br />";
//            return 0;
//        } else {
//            $no_days = 0;
//            $weekends = 0;
//            while ($begin <= $end) {
//                $no_days++; // no of days in the given interval
//                $what_day = date("N", $begin);
//                if ($what_day > 5) { // 6 and 7 are weekend days
//                    $weekends++;
//                };
//                $begin += 86400; // +1 day
//            };
//            return $no_days - $weekends;
//        }
//    }
//
//
//    /**
//     * Curl request to Bob Go Shipment Tracking API
//     * @param $trackInfo
//     * @return mixed
//     */
//    private function trackbobgoShipment($trackInfo): mixed
//    {
//        $this->curl->get(uData::TRACKING . $trackInfo);
//
//        $response = $this->curl->getBody();
//
//        return json_decode($response, true);
//    }
//
//    /**
//     * Build The Payload for Bob Go API Request and return response
//     * @param array $payload
//     * @return mixed
//     */
//    protected function uRates(array $payload): mixed
//    {
//
//        $this->curl->addHeader('Content-Type', 'application/json');
//        $this->curl->post($this->getApiUrl(), json_encode($payload));
//        $rates = $this->curl->getBody();
//
//        $rates = json_decode($rates, true);
//        return $rates;
//    }
//
//
//    /**
//     * @param string $destStreet
//     * @return string[]
//     */
//    protected function destStreet(string $destStreet): array
//    {
//        if (strpos($destStreet, "\n") !== false) {
//            $destStreet = explode("\n", $destStreet);
//            $destStreet1 = $destStreet[0];
//            $destStreet2 = $destStreet[1];
//            $destStreet3 = $destStreet[2] ?? '';
//        } else {
//            $destStreet1 = $destStreet;
//            $destStreet2 = '';
//            $destStreet3 = '';
//        }
//        return array($destStreet1, $destStreet2, $destStreet3);
//    }
//
//    /**
//     * @param int $min_delivery_date
//     * @param int $max_delivery_date
//     * @param $method
//     * @return void
//     */
//    protected function deliveryDays(int $min_delivery_date, int $max_delivery_date, $method): void
//    {
//        if ($min_delivery_date !== $max_delivery_date) {
//            $method->setCarrierTitle('delivery in ' . $min_delivery_date . ' - ' . $max_delivery_date . ' days');
//        } else {
//            $method->setCarrierTitle('delivery in ' . $min_delivery_date . ' days');
//            if ($min_delivery_date && $max_delivery_date == 1) {
//                $method->setCarrierTitle('delivery in ' . $min_delivery_date . ' day');
//            }
//        }
//    }
//
//    /**
//     * @return mixed|string
//     */
//    public function getDestComp(): mixed
//    {
//        return $this->additionalInfo->getDestComp();
//    }
//
//    /**
//     * @return mixed|string
//     */
//    public function getDestSuburb(): mixed
//    {
//        return $this->additionalInfo->getSuburb();
//    }
//
//    /**
//     * @return mixed|string
//     */
//    public function getDestTelephone(): mixed
//    {
//        return $this->additionalInfo->getDestTelephone();
//    }
//
//    /**
//     * @return mixed|string
//     */
//    public function getCountryName(mixed $country_id): mixed
//    {
//        return $this->additionalInfo->getCountryName($country_id);
//    }
//
//    /**
//     * @param mixed $weightUnit
//     * @param mixed $item
//     * @return float|int
//     */
//    public function getItemWeight(mixed $weightUnit, mixed $item): int|float
//    {
//        //1 lb = 453.59237 g exact. 1 kg = 1000 g. 1 lb = 0.45359237 kg
//        if ($weightUnit == 'KGS') {
//            $mass = $item->getWeight() ? $item->getWeight() * 1000 : 0;
//        } else {
//            $mass = $item->getWeight() ? $item->getWeight() * 0.45359237 * 1000 : 0;//Pound to Kilogram Conversion Formula
//        }
//        return $mass;
//    }
//
//    /**
//     * @param array $items
//     * @param mixed $weightUnit
//     * @param array $itemsArray
//     * @return array
//     */
//    public function getStoreItems(array $items, mixed $weightUnit, array $itemsArray): array
//    {
//        foreach ($items as $item) {
//
//            if ($item->getParentItem()) continue;
//
//            $productID = $item->getProductId();
//            $product = $item->getProduct();             // Product Object
//            $description = "";
//
//            if ($product) {
//                $productFull = $product->load($productID);
//                // Remove meta tags from the description
//                $description = preg_replace('#<[^>]+>#', ' ', $productFull->getDescription());
//                $description = preg_replace('!\s+!', ' ', $description);
//            }
//
//            $mass = $this->getItemWeight($weightUnit, $item);
//
//            $itemsArray[] = [
//                'sku' => $item->getSku(),
//                'quantity' => $item->getQty(),
//                'price' => $item->getPrice(),
//                'weight' => round($mass),
//                'name' => $item->getName(),
//                'description' => $description,
//            ];
//
//        }
//        return $itemsArray;
//    }
//
//    /**
//     * Get the list of required data fields
//     * @return bool
//     */
//    public function hasRequiredData(DataObject $request): bool
//    {
//        $requiredFields = [
//            'dest_country_id',
//            'dest_region_id',
//        ];
//
//        foreach ($requiredFields as $field) {
//            if (!$request->getData($field)) {
//                return false;
//            }
//        }
//        return true;
//    }
//}


namespace BobGroup\BobGo\Model\Carrier;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\ZendClientFactory;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Shipping\Model\Carrier\AbstractCarrierOnline;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Rate\Result;
use BobGroup\BobGo\Logger\Logger;

class BobGo extends AbstractCarrierOnline implements CarrierInterface
{
    protected $_code = 'bobgo';
    protected $_isFixed = true;

    protected $scopeConfig;
    protected $httpClientFactory;
    protected $logger;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory $rateErrorFactory,
        Logger $logger,
        ZendClientFactory $httpClientFactory,
        array $data = []
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->httpClientFactory = $httpClientFactory;
        $this->logger = $logger;
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
    }

    public function collectRates(RateRequest $request)
    {
        $this->logger->info('BobGo collectRates method called');

        if (!$this->getConfigFlag('active')) {
            $this->logger->info('BobGo is not active');
            return false;
        }

        $this->logger->info('BobGo is active, proceeding with rate collection');

        $result = $this->_rateFactory->create();

        // Prepare request data for API call
        $params = [
            'dest_country_id' => $request->getDestCountryId(),
            'dest_region_id' => $request->getDestRegionId(),
            'dest_postcode' => $request->getDestPostcode(),
            'package_weight' => $request->getPackageWeight(),
            'package_value' => $request->getPackageValue(),
            'package_qty' => $request->getPackageQty(),
        ];

        $this->logger->info('Request parameters: ' . json_encode($params));

        try {
            $apiResponse = $this->_fetchRatesFromApi($params);
            $this->logger->info('API response: ' . json_encode($apiResponse));

            if ($apiResponse && isset($apiResponse['rates']) && !empty($apiResponse['rates'])) {
                foreach ($apiResponse['rates'] as $rateData) {
                    $rate = $this->_rateMethodFactory->create();
                    $rate->setCarrier($this->_code);
                    $rate->setCarrierTitle($this->getConfigData('title'));
                    $rate->setMethod($rateData['method']);
                    $rate->setMethodTitle($rateData['method_title']);
                    $rate->setPrice($rateData['price']);
                    $rate->setCost($rateData['cost']);
                    $result->append($rate);
                }
            } else {
                $this->logger->info('No rates returned from API');
                $error = $this->_rateErrorFactory->create();
                $error->setCarrier($this->_code);
                $error->setCarrierTitle($this->getConfigData('title'));
                $error->setErrorMessage($this->getConfigData('specificerrmsg'));
                return $error;
            }
        } catch (\Exception $e) {
            $this->logger->error('Error fetching rates from API: ' . $e->getMessage());
            $error = $this->_rateErrorFactory->create();
            $error->setCarrier($this->_code);
            $error->setCarrierTitle($this->getConfigData('title'));
            $error->setErrorMessage($this->getConfigData('specificerrmsg'));
            return $error;
        }

        return $result;
    }

    protected function _fetchRatesFromApi($params)
    {
        $url = 'https://api.dev.bobgo.co.za/rates-at-checkout/magento';
        $client = $this->httpClientFactory->create();
        $client->setUri($url);
        $client->setConfig(['timeout' => 30]);
        $client->setHeaders(['Content-Type' => 'application/json']);
        $client->setMethod(\Zend_Http_Client::POST);
        $client->setRawData(json_encode($params), 'application/json');

        try {
            $response = $client->request();
            $responseBody = $response->getBody();

            // Log the response body
            $this->logger->info('Response body: ' . var_export($responseBody, true));

            if ($responseBody === null) {
                $this->logger->error('API response body is null');
                return false;
            }

            if ($response->isSuccessful()) {
                return json_decode($responseBody, true);
            } else {
                $this->logger->error('API request failed with status: ' . $response->getStatus());
            }
        } catch (\Exception $e) {
            $this->logger->error('Exception during API request: ' . $e->getMessage());
        }

        return false;
    }

    public function getAllowedMethods()
    {
        return [$this->_code => $this->getConfigData('name')];
    }

}





