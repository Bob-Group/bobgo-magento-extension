<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Controller\Tracking;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\Registry;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\StoreManagerInterface;
use BobGroup\BobGo\Api\BobGoApiClient;
use BobGroup\BobGo\Api\BobGoApiException;
use BobGroup\BobGo\Model\Config\ApiConfig;

/**
 * Customer-facing order tracking page controller.
 *
 * Accepts an order_reference parameter, queries the Bob Go tracking API,
 * and renders the tracking results. Only accessible when the track order
 * feature is enabled in admin configuration.
 *
 * Route: /bobgo/tracking/index
 */
class Index extends \Magento\Framework\App\Action\Action
{
    /** @var PageFactory */
    protected $resultPageFactory;

    /** @var JsonFactory */
    protected $jsonFactory;

    /** @var LoggerInterface */
    protected $logger;

    /** @var ScopeConfigInterface */
    protected $scopeConfig;

    /** @var RedirectFactory */
    protected $redirectFactory;

    /** @var Registry */
    protected $registry;

    /** @var StoreManagerInterface */
    protected StoreManagerInterface $storeManager;

    /** @var BobGoApiClient */
    private BobGoApiClient $apiClient;

    /** @var ApiConfig */
    private ApiConfig $apiConfig;

    /** @var FormKeyValidator */
    private FormKeyValidator $formKeyValidator;

    /** @var OrderRepositoryInterface */
    private OrderRepositoryInterface $orderRepository;

    /** @var SearchCriteriaBuilder */
    private SearchCriteriaBuilder $searchCriteriaBuilder;

    public function __construct(
        Context $context,
        PageFactory $resultPageFactory,
        JsonFactory $jsonFactory,
        LoggerInterface $logger,
        ScopeConfigInterface $scopeConfig,
        RedirectFactory $redirectFactory,
        StoreManagerInterface $storeManager,
        BobGoApiClient $apiClient,
        ApiConfig $apiConfig,
        Registry $registry,
        FormKeyValidator $formKeyValidator,
        OrderRepositoryInterface $orderRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
        $this->resultPageFactory = $resultPageFactory;
        $this->jsonFactory = $jsonFactory;
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
        $this->redirectFactory = $redirectFactory;
        $this->storeManager = $storeManager;
        $this->apiClient = $apiClient;
        $this->apiConfig = $apiConfig;
        $this->registry = $registry;
        $this->formKeyValidator = $formKeyValidator;
        $this->orderRepository = $orderRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        parent::__construct($context);
    }

    /**
     * Execute the tracking page action.
     *
     * Redirects to 404 if the feature is disabled. Otherwise, fetches
     * tracking data from the Bob Go API using the order_reference param
     * and renders the tracking page template.
     *
     * @return \Magento\Framework\View\Result\Page|\Magento\Framework\Controller\Result\Redirect
     */
    public function execute()
    {
        $isEnabled = $this->scopeConfig->isSetFlag(
            ApiConfig::XML_PATH_ENABLE_TRACK_ORDER,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );

        if (!$isEnabled) {
            return $this->redirectFactory->create()->setPath('noroute');
        }

        $request = $this->getRequest();

        // Render the empty form on GET. Only honour a lookup when the request
        // is a POST carrying a valid form_key — without this the endpoint
        // is a CSRF-able proxy. Form key prevents that; the local-order
        // check below blocks enumeration / use as a generic Bob Go probe.
        $userInput = null;
        if ($request->isPost()) {
            if (!$this->formKeyValidator->validate($request)) {
                return $this->redirectFactory->create()->setPath('bobgo/tracking/index');
            }
            $userInput = $request->getParam('order_reference');
        }

        if ($userInput && $this->apiConfig->isConfigured()) {
            // Require the input to match an order or shipment that belongs
            // to this store. Without this gate, anyone could feed arbitrary
            // tracking references to the form and use the merchant's
            // (rate-limited, API-key-charged) Bob Go endpoint as a free
            // tracking-reference oracle.
            $trackingReference = $this->resolveTrackingReferenceForStore((string) $userInput);
            if ($trackingReference === null) {
                $this->logger->info('Bob Go tracking lookup: input did not match a local order', [
                    'input_length' => strlen((string) $userInput),
                ]);
            } else {
                try {
                    $response = $this->apiClient->get('tracking', [
                        'tracking_reference' => $trackingReference,
                    ]);

                    if (is_array($response) && isset($response[0])) {
                        $this->registry->register('shipment_data', $response[0]);
                    }
                } catch (BobGoApiException $e) {
                    $this->logger->error('Bob Go tracking request failed', [
                        'tracking_reference' => $trackingReference,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $this->resultPageFactory->create();
    }

    /**
     * Translate the customer's free-text input into a tracking reference
     * we're willing to hand to Bob Go.
     *
     * Accepts either:
     *   - an order increment_id (we look up the most recent tracking number
     *     on that order); or
     *   - a tracking number already present on a shipment in this store.
     *
     * Returns null if neither matches — the caller should NOT fall back to
     * the raw input, otherwise the endpoint becomes a tracking-reference
     * oracle for the merchant's Bob Go account.
     */
    private function resolveTrackingReferenceForStore(string $input): ?string
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        // Tracking number match — direct lookup against shipment tracks
        // belonging to orders in this store.
        $criteria = $this->searchCriteriaBuilder
            ->addFilter('increment_id', $input, 'eq')
            ->setPageSize(1)
            ->create();
        try {
            $list = $this->orderRepository->getList($criteria);
            $orders = $list->getItems();
        } catch (\Throwable $e) {
            $this->logger->warning('Bob Go tracking: order lookup failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
        if (!empty($orders)) {
            /** @var \Magento\Sales\Api\Data\OrderInterface $order */
            $order = reset($orders);
            $tracking = $this->extractTrackingFromOrder($order);
            if ($tracking !== null) {
                return $tracking;
            }
        }

        // Fall back: maybe the customer pasted a tracking number. Walk the
        // recently-shipped orders' tracks and accept it only if we recorded
        // it locally.
        return $this->lookupTrackingNumberLocally($input);
    }

    /**
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     */
    private function extractTrackingFromOrder($order): ?string
    {
        if (!method_exists($order, 'getShipmentsCollection')) {
            return null;
        }
        $shipments = $order->getShipmentsCollection();
        if ($shipments === false || $shipments->getSize() === 0) {
            return null;
        }
        foreach ($shipments as $shipment) {
            foreach ($shipment->getAllTracks() as $track) {
                $number = (string) $track->getTrackNumber();
                if ($number !== '') {
                    return $number;
                }
            }
        }
        return null;
    }

    /**
     * Best-effort: confirm a tracking number was issued by this store. We
     * page through recently-touched orders rather than running an EAV-side
     * track query (Magento doesn't expose one cleanly via repositories).
     */
    private function lookupTrackingNumberLocally(string $trackingNumber): ?string
    {
        $criteria = $this->searchCriteriaBuilder
            ->addFilter('bobgo_order_id', null, 'notnull')
            ->setPageSize(100)
            ->create();
        try {
            $list = $this->orderRepository->getList($criteria);
            foreach ($list->getItems() as $order) {
                $candidate = $this->extractTrackingFromOrder($order);
                if ($candidate !== null && $candidate === $trackingNumber) {
                    return $trackingNumber;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Bob Go tracking: shipment scan failed', [
                'error' => $e->getMessage(),
            ]);
        }
        return null;
    }
}
