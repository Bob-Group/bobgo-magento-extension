<?php
declare(strict_types=1);

namespace BobGroup\BobGo\Controller\Tracking;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
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
    /**
     * How many of the customer's own orders to walk when they paste a tracking
     * number. Scoped to one customer, so this is generous.
     */
    private const TRACKING_SCAN_LIMIT = 50;

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

    /** @var SortOrderBuilder */
    private SortOrderBuilder $sortOrderBuilder;

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
        SearchCriteriaBuilder $searchCriteriaBuilder,
        SortOrderBuilder $sortOrderBuilder
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
        $this->sortOrderBuilder = $sortOrderBuilder;
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
        $email = '';
        if ($request->isPost()) {
            if (!$this->formKeyValidator->validate($request)) {
                return $this->redirectFactory->create()->setPath('bobgo/tracking/index');
            }
            $userInput = $request->getParam('order_reference');
            $email = (string) $request->getParam('email');
        }

        // Both, always. An order number on its own is not a secret: Magento hands
        // every store the same sequence, starting at 000000001, so accepting one
        // alone turns this page into a way to read any customer's shipment status
        // and checkpoint locations by counting upwards. Matching Magento's own
        // guest order lookup, the email has to agree.
        if ($userInput && $email !== '' && $this->apiConfig->isConfigured()) {
            $trackingReference = $this->resolveTrackingReferenceForStore((string) $userInput, $email);
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
     * Translate the customer's input into a tracking reference we are willing to
     * hand to Bob Go.
     *
     * Accepts either an order number or a tracking number, but in both cases only
     * for an order whose customer_email matches. Returns null otherwise — and the
     * caller must NOT fall back to the raw input, or the endpoint becomes a
     * tracking-reference oracle against the merchant's Bob Go account.
     */
    private function resolveTrackingReferenceForStore(string $input, string $email): ?string
    {
        $input = trim($input);
        $email = trim($email);
        if ($input === '' || $email === '') {
            return null;
        }

        // Order number + email: one precise row, no scanning.
        $order = $this->findOneOrder([
            ['field' => 'increment_id', 'value' => $input],
            ['field' => 'customer_email', 'value' => $email],
        ]);
        if ($order !== null) {
            $tracking = $this->extractTrackingFromOrder($order);
            if ($tracking !== null) {
                return $tracking;
            }
        }

        return $this->lookupTrackingNumberLocally($input, $email);
    }

    /**
     * The customer pasted a tracking number rather than an order number.
     *
     * Magento exposes no clean repository query over shipment tracks, so this
     * still walks orders — but only that customer's, newest first, which turns
     * what used to be a scan of the store's oldest hundred Bob Go orders into a
     * handful of rows. (No sort order was applied at all before, so on any store
     * past a hundred Bob Go orders this path could never match anything.)
     */
    private function lookupTrackingNumberLocally(string $trackingNumber, string $email): ?string
    {
        $criteria = $this->searchCriteriaBuilder
            ->addFilter('customer_email', $email)
            ->addFilter('bobgo_order_id', null, 'notnull')
            ->setPageSize(self::TRACKING_SCAN_LIMIT)
            ->setCurrentPage(1)
            ->addSortOrder($this->sortOrderBuilder->setField('entity_id')->setDescendingDirection()->create())
            ->create();

        try {
            foreach ($this->orderRepository->getList($criteria)->getItems() as $order) {
                if ($this->orderHasTrackingNumber($order, $trackingNumber)) {
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

    /**
     * @param array<int,array{field:string,value:string}> $filters
     * @return \Magento\Sales\Api\Data\OrderInterface|null
     */
    private function findOneOrder(array $filters)
    {
        foreach ($filters as $filter) {
            $this->searchCriteriaBuilder->addFilter($filter['field'], $filter['value']);
        }
        $criteria = $this->searchCriteriaBuilder->setPageSize(1)->create();

        try {
            $items = $this->orderRepository->getList($criteria)->getItems();
        } catch (\Throwable $e) {
            $this->logger->warning('Bob Go tracking: order lookup failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        $order = reset($items);
        return $order === false ? null : $order;
    }

    /**
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     */
    private function orderHasTrackingNumber($order, string $trackingNumber): bool
    {
        if (!method_exists($order, 'getShipmentsCollection')) {
            return false;
        }
        $shipments = $order->getShipmentsCollection();
        if (!$shipments || $shipments->getSize() === 0) {
            return false;
        }
        foreach ($shipments as $shipment) {
            foreach ($shipment->getAllTracks() as $track) {
                if ((string) $track->getTrackNumber() === $trackingNumber) {
                    return true;
                }
            }
        }
        return false;
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
}
