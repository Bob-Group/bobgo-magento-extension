<?php

namespace uafrica\Customshipping\Model\Carrier;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Rate\ResultFactory;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\Method;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Psr\Log\LoggerInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\HTTP\Client\CurlFactory;

/**
 * @category   uafrica
 * @package    uafrica_Customshipping
 * @author     info@bob.co.za
 * @website    https://www.bob.co.za
 */
class Customshipping extends AbstractCarrier implements CarrierInterface
{
    /**
     * Carrier's code
     *
     * @var string
     */
    protected $_code = 'uafrica';

    /**
     * Whether this carrier has fixed rates calculation
     *
     * @var bool
     */
    protected $_isFixed = false;

    /**
     * @var ResultFactory
     */
    protected $_rateResultFactory;

    /**
     * @var MethodFactory
     */
    protected $_rateMethodFactory;



    //TODO: REFACTOR THIS
    /**
     * @var \Magento\Framework\Controller\Result\JsonFactory
     */
    protected $jsonFactory;

    /**
     * @var \Magento\Framework\HTTP\Client\Curl
     */
    protected $curl;


    /**
     * @param \Magento\Framework\Controller\Result\JsonFactory $jsonFactory
     */

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param ErrorFactory $rateErrorFactory
     * @param LoggerInterface $logger
     * @param ResultFactory $rateResultFactory
     * @param MethodFactory $rateMethodFactory
     * @param array $data
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ErrorFactory $rateErrorFactory,
        LoggerInterface $logger,
        ResultFactory $rateResultFactory,
        MethodFactory $rateMethodFactory,
        JsonFactory $jsonFactory,
        CurlFactory $curlFactory,
        array $data = []
    ) {
        $this->jsonFactory = $jsonFactory;
        $this->curl = $curlFactory->create();
        $this->_rateResultFactory = $rateResultFactory;
        $this->_rateMethodFactory = $rateMethodFactory;
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
    }


    /**
     *  Allow Admin/User to track shipment from admin panel or frontend  order page i.e popup
     *  @return bool
     */
    public function isTrackingAvailable(): bool
    {
        return true;
    }

    /**
     * Get allowed shipping methods
     *
     *  @param string $type
     *  @param string $code
     *  @param array|false
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getCode($type, string $code = '')
    {
        $codes = [
            'method' => [
                'uafrica_FIRST_INTERNATIONAL_PRIORITY' => __('uafrica First Priority'),
                'uafrica_1_DAY_FREIGHT' => __('1 Day Freight'),
                'uafrica_2_DAY_FREIGHT' => __('2 Day Freight'),
                'uafrica_2_DAY' => __('2 Day'),
                'uafrica_2_DAY_AM' => __('2 Day AM'),
                'uafrica_3_DAY_FREIGHT' => __('3 Day Freight'),
                'uafrica_EXPRESS_SAVER' => __('Express Saver'),
                'uafrica_GROUND' => __('Ground'),
                'FIRST_OVERNIGHT' => __('First Overnight'),
                'GROUND_HOME_DELIVERY' => __('Home Delivery'),
                'INTERNATIONAL_ECONOMY' => __('International Economy'),
                'INTERNATIONAL_ECONOMY_FREIGHT' => __('Intl Economy Freight'),
                'INTERNATIONAL_FIRST' => __('International First'),
                'INTERNATIONAL_GROUND' => __('International Ground'),
                'INTERNATIONAL_PRIORITY' => __('International Priority'),
                'INTERNATIONAL_PRIORITY_FREIGHT' => __('Intl Priority Freight'),
                'PRIORITY_OVERNIGHT' => __('Priority Overnight'),
                'SMART_POST' => __('Smart Post'),
                'STANDARD_OVERNIGHT' => __('Standard Overnight'),
                'uafrica_FREIGHT' => __('Freight'),
                'uafrica_NATIONAL_FREIGHT' => __('National Freight'),
            ],
            'dropoff' => [
                'REGULAR_PICKUP' => __('Regular Pickup'),
                'REQUEST_COURIER' => __('Request Courier'),
                'DROP_BOX' => __('Drop Box'),
                'BUSINESS_SERVICE_CENTER' => __('Business Service Center'),
                'STATION' => __('Station'),
            ],
            'packaging' => [
                'uafrica_ENVELOPE' => __('uafrica Envelope'),
                'uafrica_PAK' => __('uafrica Pak'),
                'uafrica_BOX' => __('uafrica Box'),
                'uafrica_TUBE' => __('uafrica Tube'),
                'uafrica_10KG_BOX' => __('uafrica 10kg Box'),
                'uafrica_25KG_BOX' => __('uafrica 25kg Box'),
                'YOUR_PACKAGING' => __('Your Packaging'),
            ],
            'containers_filter' => [
                [
                    'containers' => ['uafrica_ENVELOPE', 'uafrica_PAK'],
                    'filters' => [
                        'within_us' => [
                            'method' => [
                                'uafrica_EXPRESS_SAVER',
                                'uafrica_2_DAY',
                                'uafrica_2_DAY_AM',
                                'STANDARD_OVERNIGHT',
                                'PRIORITY_OVERNIGHT',
                                'FIRST_OVERNIGHT',
                            ],
                        ],
                        'from_us' => [
                            'method' => ['INTERNATIONAL_FIRST', 'INTERNATIONAL_ECONOMY', 'INTERNATIONAL_PRIORITY'],
                        ],
                    ],
                ],
                [
                    'containers' => ['uafrica_BOX', 'uafrica_TUBE'],
                    'filters' => [
                        'within_us' => [
                            'method' => [
                                'uafrica_2_DAY',
                                'uafrica_2_DAY_AM',
                                'STANDARD_OVERNIGHT',
                                'PRIORITY_OVERNIGHT',
                                'FIRST_OVERNIGHT',
                                'uafrica_FREIGHT',
                                'uafrica_1_DAY_FREIGHT',
                                'uafrica_2_DAY_FREIGHT',
                                'uafrica_3_DAY_FREIGHT',
                                'uafrica_NATIONAL_FREIGHT',
                            ],
                        ],
                        'from_us' => [
                            'method' => ['INTERNATIONAL_FIRST', 'INTERNATIONAL_ECONOMY', 'INTERNATIONAL_PRIORITY'],
                        ],
                    ],
                ],
                [
                    'containers' => ['uafrica_10KG_BOX', 'uafrica_25KG_BOX'],
                    'filters' => [
                        'within_us' => [],
                        'from_us' => ['method' => ['INTERNATIONAL_PRIORITY']],
                    ],
                ],
                [
                    'containers' => ['YOUR_PACKAGING'],
                    'filters' => [
                        'within_us' => [
                            'method' => [
                                'uafrica_GROUND',
                                'GROUND_HOME_DELIVERY',
                                'SMART_POST',
                                'uafrica_EXPRESS_SAVER',
                                'uafrica_2_DAY',
                                'uafrica_2_DAY_AM',
                                'STANDARD_OVERNIGHT',
                                'PRIORITY_OVERNIGHT',
                                'FIRST_OVERNIGHT',
                                'uafrica_FREIGHT',
                                'uafrica_1_DAY_FREIGHT',
                                'uafrica_2_DAY_FREIGHT',
                                'uafrica_3_DAY_FREIGHT',
                                'uafrica_NATIONAL_FREIGHT',
                            ],
                        ],
                        'from_us' => [
                            'method' => [
                                'INTERNATIONAL_FIRST',
                                'INTERNATIONAL_ECONOMY',
                                'INTERNATIONAL_PRIORITY',
                                'INTERNATIONAL_GROUND',
                                'uafrica_FREIGHT',
                                'uafrica_1_DAY_FREIGHT',
                                'uafrica_2_DAY_FREIGHT',
                                'uafrica_3_DAY_FREIGHT',
                                'uafrica_NATIONAL_FREIGHT',
                                'INTERNATIONAL_ECONOMY_FREIGHT',
                                'INTERNATIONAL_PRIORITY_FREIGHT',
                            ],
                        ],
                    ],
                ],
            ],
            'delivery_confirmation_types' => [
                'NO_SIGNATURE_REQUIRED' => __('Not Required'),
                'ADULT' => __('Adult'),
                'DIRECT' => __('Direct'),
                'INDIRECT' => __('Indirect'),
            ],
            'unit_of_measure' => [
                'LB' => __('Pounds'),
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
     * Whether this carrier has shipping labels
     */
    public function isShippingLabelsAvailable(): bool
    {
        return true;
    }

    /**
     * @param string $tracking
     * @return DataObject
     */
    public function getTrackingInfo($tracking): DataObject
    {
        //Not Used Yet On This Phase of Development
        //  $result = $this->_trackFactory->create();
        $result = new DataObject();
        //Insert tracking code dynamically here
        $result->setUrl('https://api.dev.ship.uafrica.com/tracking?channel=localhost&tracking_reference='.$tracking);
        $result->setTracking($tracking);

        $result->setCarrierTitle($this->getConfigData('title'));

        //Perform curl request to get tracking info from uAfrica API
        //Inject tracking code into the url dynamically
        $this->curl->get('https://api.dev.ship.uafrica.com/tracking?channel=localhost&tracking_reference='.$tracking);

        $response = $this->curl->getBody();

        $response = json_decode($response, true);

        $result->addData((array)$response);
        /**1. Image to be dynamic in the next phase
         * 2. Tracking status to be dynamic in the next phase
         * 3. This method feels hacky, not sure if I will need to refactor, but it works for now,
         * I am open for suggestions.
         */

        //Todo: Add Image dynamically to the result object
        echo "<pre>";
                print_r("
        <img src='https://ik.imagekit.io/z1viz85yxs/dev-v3/provider-logos/devpanda_logo.png' alt='Dev Panda' width='100' height='100'>

                <table '>
                <tr>
                <th>Order Number</th>
                <th>Order Date</th>
                <th>Order Status</th>
                </tr>
                <tr>
                <td>".$response[0]['order_number']."</td>
                <td>".$response[0]['shipment_time_created']."</td>
                <td>".$response[0]['status_friendly']."</td>
                </tr>
                </table>

                <h4>Tracking</h4>
                <table>
                <tr>
                <th>Tracking Number</th>
                <th>Tracking Status</th>
                <th>Tracking Date</th>
                </tr>
                <tr>
                <td>".$response[0]['shipment_tracking_reference']."</td>
                <td>".$response[0]['status_friendly']."</td>
                <td>".$response[0]['last_checkpoint_time']."</td>
                </tr>
                </table>

        ");

      print_r(" <h4>Checkpoints</h4>
        <table>
            <tr>
                <th>Status</th>
                <th>Status Friendly</th>
                <th>Country</th>
                <th>Zone</th>
                <th>City</th>
                <th>Zip</th>
                <th>Location</th>
                <th>Message</th>
                <th>Time</th>
            </tr>"
      );
        foreach ($response[0]['checkpoints'] as $checkpoint) {
            print_r("<tr>
                <td>" . $checkpoint['status'] . "</td>
                <td>" . $checkpoint['status_friendly'] . "</td>
                <td>" . $checkpoint['country'] . "</td>
                <td>" . $checkpoint['zone'] . "</td>
                <td>" . $checkpoint['city'] . "</td>
                <td>" . $checkpoint['zip'] . "</td>
                <td>" . $checkpoint['location'] . "</td>
                <td>" . $checkpoint['message'] . "</td>
                <td>" . $checkpoint['time'] . "</td>
            </tr>
            ");

        }
       print_r("</table>");

        echo "</pre>";

        return $result;
    }

    /**
     * @return string
     */
    private function getApiUrl(): string
    {
        //return $this->getConfigData('api_url');
       // return 'https://api.dev.ship.uafrica.com';

        //Used for testing purposes only on localhost, since there is not endpoint to get magento rates to work with
        //Basically, I am using the structure of the response from the uAfrica API to test the functionality of the module
        return 'https://8390956f-c00b-497d-8742-87b1d6305bd2.mock.pstmn.io/putrates';
    }

    /**
     * Make request to uAfrica API to get shipping rates
     *  @return array
     */
    private function getRates(): array
    {
        $this->curl->post($this->getApiUrl(), $this->getPayload());

        $response = $this->curl->getBody();

        $response = json_decode($response, true);

        return $response;
    }

    /**
     * Collect and get rates for storefront
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @param RateRequest $request
     * @return DataObject|bool|null
     * @api
     */
    public function collectRates(RateRequest $request)
    {
        //TODO: You need to work with this function in order to implement getting rates at checkout
        /**
         * Make sure that Shipping method is enabled
         */
        if (!$this->isActive()) {
            return false;
        }

        /** @var \Magento\Shipping\Model\Rate\Result $result */

        $result = $this->_rateResultFactory->create();

        //TODO: Get the rates from the API and append them to the result object
        //JSON data fed to post man mock server as a response to the request for rates
        //{
        //"rates": [
        //{
        //"id": "113810",
        //"description": "Standard Economy Shipping Rate",
        //"service_name": "Courier Door to Door Delivery - Economy",
        //"service_code": "113810_23",
        //"total_price": 7500,
        //"currency": "ZAR",
        //"min_delivery_date": "2022-12-29",
        //"max_delivery_date": "2023-01-03"
        //}
        //]
        //}
        //Mock URL FOR NOW, will be replaced with the actual API URL
        //Like; $this->getConfigData('api_url')


        $rates = $this->getRates();

        $method = $this->_rateMethodFactory->create();

        foreach ($rates['rates'] as $code => $title) {
            $method->setCarrier('uafrica');
            $method->setCarrierTitle('uafrica');
            $method->setMethod($code);
            $method->setMethodTitle($title['service_name']);
            $method->setPrice($title['total_price']);
            $method->setCost($title['total_price']);
            $result->append($method);
        }

        return $result;
    }

    /**
     *Get Allowed shipping methods (for this part make fake methods)
     * @return array
     *
     */
    public function getAllowedMethods(): array
    {
        $arr = [
            'method' => 'uafrica',

        ];
        return $arr;
    }
    /**
     * Return all the extracted data from  The Current Sales Order
     * @return array
     */
    private function getPayload(): array
    {
        return $this->extracted();
    }

    /**
     * @return array[]
     */
    private function extracted(): array
    {
        //Get Information from the current sales order
        //Static Payload for testing purposes only as aforementioned
        $payload = [
            'rate' => [
                'origin' => [
                    'country' => 'ZA',
                    'postal_code' => '4001',
                    'province' => 'NL',
                    'city' => 'Durban',
                    'name' => '',
                    'address1' => '29 Livingstone Rd',
                    'address2' => 'Durban',
                    'address3' => '',
                    'phone' => '+27313039670',
                    'fax' => '',
                    'email' => '',
                    'address_type' => '',
                    'company_name' => 'Jam Clothing'
                ],
                'destination' => [
                    'country' => 'ZA',
                    'postal_code' => '4068',
                    'province' => 'NL',
                    'city' => 'Phoenix',
                    'name' => 'Brian Singh',
                    'address1' => '3 cretemore road, stanmore, phoenix',
                    'address2' => 'House',
                    'address3' => '',
                    'phone' => '081 346 5923',
                    'fax' => '',
                    'email' => '',
                    'address_type' => '',
                    'company_name' => ''
                ],
                'items' => [
                    [
                        'name' => 'Mens Gold\'s Gym Knapsack Bag - Black - NoSize',
                        'sku' => 'AC91000336001',
                        'quantity' => 2,
                        'grams' => 0,
                        'price' => 2000,
                        'vendor' => 'Jam Clothing',
                        'requires_shipping' => true,
                        'taxable' => true,
                        'fulfillment_service' => 'manual',
                        'properties' => [],
                        'product_id' => 7100074098825,
                        'variant_id' => 41001616900233
                    ]
                ],
                'currency' => 'ZAR',
                'locale' => 'en-PT'
            ]
        ];

        return $payload;
    }

}
