<?php

namespace uafrica\Customshipping\Model\Carrier;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Config;
use Magento\Shipping\Model\Rate\ResultFactory;
use Magento\Store\Model\ScopeInterface;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\Method;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use function Safe\swoole_event_defer;

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
    protected $_isFixed = true;

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
     * Generates list of allowed carrier`s shipping methods
     * Displays on cart price rules page
     *
     * @return array
     * @api
     */
    public function getAllowedMethods(): array
    {
        return [$this->getCarrierCode() => __($this->getConfigData('name'))];
    }
    /**
     * Allow Display of uAfrica/Bobgo in the list of carriers (Admin)
     *  @return bool
     */
    public function isTrackingAvailable(): bool
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

        $result->setUrl('https://api.dev.ship.uafrica.com/tracking?channel=localhost&tracking_reference=UADPCTGF');

        $result->setTracking($tracking);

        $result->setCarrierTitle($this->getConfigData('title'));

        //Perfom curl request to get tracking info from uAfrica API
        $this->curl->get('https://api.dev.ship.uafrica.com/tracking?channel=localhost&tracking_reference=UADPCTGF');

        $response = $this->curl->getBody();

        $response = json_decode($response, true);

        $result->addData((array)$response);
        /**1. Image to be dynamic in the next phase
         * 2. Tracking status to be dynamic in the next phase
         * 3. This method feels hacky, not sure if I will need to refactor, but it works for now I am up for suggestions
         */

        //Todo: Add Image to the result object
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
     * Collect and get rates for storefront
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @param RateRequest $request
     * @return DataObject|bool|null
     * @api
     */
    public function collectRates(RateRequest $request)
    {
        /**
         * Make sure that Shipping method is enabled
         */
        if (!$this->isActive()) {
            return false;
        }

        /** @var \Magento\Shipping\Model\Rate\Result $result */
        $result = $this->_rateResultFactory->create();

        $shippingPrice = $this->getConfigData('price');

        $method = $this->_rateMethodFactory->create();

        /**
         * Set carrier's method data
         */
        $method->setCarrier($this->getCarrierCode());
        $method->setCarrierTitle($this->getConfigData('title'));

        /**
         * Displayed as shipping method under Carrier
         */
        $method->setMethod($this->getCarrierCode());
        $method->setMethodTitle($this->getConfigData('name'));

        $method->setPrice($shippingPrice);
        $method->setCost($shippingPrice);

        $result->append($method);

        return $result;
    }

}
