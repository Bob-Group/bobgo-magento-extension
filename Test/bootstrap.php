<?php
/**
 * PHPUnit test bootstrap.
 *
 * Provides minimal stubs for Magento framework classes so that unit tests
 * can run without a full Magento installation. The ComponentRegistrar stub
 * is handled by Test/stubs/autoload-prepend.php via --prepend (loaded before
 * the composer autoloader).
 *
 * This file defines additional stubs needed for test mocking.
 */

// --- Core Framework Stubs (order matters - base classes first) ---

// Stub Magento DataObject if not already available
if (!class_exists(\Magento\Framework\DataObject::class, false)) {
    eval('namespace Magento\Framework; class DataObject { protected $_data = []; public function __call($m, $a) { $key = strtolower(preg_replace("/(.)([A-Z])/", "$1_$2", substr($m, 3))); switch (substr($m, 0, 3)) { case "get": return $this->getData($key); case "set": return $this->setData($key, $a[0] ?? null); case "has": return isset($this->_data[$key]); case "uns": unset($this->_data[$key]); return $this; } return null; } public function getData($k = null) { if ($k === null) { return $this->_data; } return $this->_data[$k] ?? null; } public function setData($k, $v = null) { $this->_data[$k] = $v; return $this; } public function hasData($k = null) { if ($k === null) { return !empty($this->_data); } return array_key_exists($k, $this->_data); } public function toArray() { return $this->_data; } }');
}

// Stub Magento Phrase (used by __() function and LocalizedException)
if (!class_exists(\Magento\Framework\Phrase::class, false)) {
    eval('namespace Magento\Framework; class Phrase { private $text; private $arguments; public function __construct(string $text, array $arguments = []) { $this->text = $text; $this->arguments = $arguments; } public function __toString(): string { return $this->text; } public function getText(): string { return $this->text; } public function getArguments(): array { return $this->arguments; } public function render(): string { return $this->text; } }');
}

// Stub __() translation function
if (!function_exists('__')) {
    function __(): \Magento\Framework\Phrase
    {
        $args = func_get_args();
        $text = array_shift($args);
        return new \Magento\Framework\Phrase((string) $text, $args);
    }
}

// Stub Magento Event class if not already available (used in observer tests via addMethods)
if (!class_exists(\Magento\Framework\Event::class, false)) {
    eval('namespace Magento\Framework; class Event extends DataObject {}');
}

// Stub ScopeInterface constants
if (!interface_exists(\Magento\Store\Model\ScopeInterface::class, false)) {
    eval('namespace Magento\Store\Model; interface ScopeInterface { const SCOPE_STORE = "store"; const SCOPE_STORES = "stores"; const SCOPE_WEBSITE = "website"; const SCOPE_WEBSITES = "websites"; }');
}

// Stub Magento\Framework\Event\Observer if not available
if (!class_exists(\Magento\Framework\Event\Observer::class, false)) {
    eval('namespace Magento\Framework\Event; class Observer { protected $event; public function getEvent() { return $this->event; } public function setEvent($event) { $this->event = $event; return $this; } }');
}

// Stub Magento\Framework\Event\ObserverInterface
if (!interface_exists(\Magento\Framework\Event\ObserverInterface::class, false)) {
    eval('namespace Magento\Framework\Event; interface ObserverInterface { public function execute(Observer $observer): void; }');
}

// Stub UrlInterface with constants (must be defined before generic stubs)
if (!interface_exists(\Magento\Framework\UrlInterface::class, false)) {
    eval('namespace Magento\Framework; interface UrlInterface { const URL_TYPE_LINK = "link"; const URL_TYPE_DIRECT_LINK = "direct_link"; const URL_TYPE_WEB = "web"; const URL_TYPE_MEDIA = "media"; const URL_TYPE_STATIC = "static"; const URL_TYPE_JS = "js"; public function getUrl(); public function getBaseUrl(); }');
}

// --- Exception Stubs ---

// Stub LocalizedException (parent of BobGoApiException)
if (!class_exists(\Magento\Framework\Exception\LocalizedException::class, false)) {
    eval('namespace Magento\Framework\Exception; class LocalizedException extends \Exception { protected $phrase; public function __construct(?\Magento\Framework\Phrase $phrase = null, ?\Exception $cause = null, int $code = 0) { $this->phrase = $phrase; parent::__construct($phrase ? $phrase->getText() : "", $code, $cause); } public function getRawMessage(): string { return $this->phrase ? $this->phrase->getText() : $this->getMessage(); } }');
}

// Stub NoSuchEntityException
if (!class_exists(\Magento\Framework\Exception\NoSuchEntityException::class, false)) {
    eval('namespace Magento\Framework\Exception; class NoSuchEntityException extends LocalizedException {}');
}

// --- Config Stubs ---

// Stub ScopeConfigInterface (must be defined before ReinitableConfigInterface which extends it)
if (!interface_exists(\Magento\Framework\App\Config\ScopeConfigInterface::class, false)) {
    eval('namespace Magento\Framework\App\Config; interface ScopeConfigInterface { public function getValue($path, $scopeType = "default", $scopeCode = null); public function isSetFlag($path, $scopeType = "default", $scopeCode = null); }');
}

// Stub ReinitableConfigInterface (extends ScopeConfigInterface)
if (!interface_exists(\Magento\Framework\App\Config\ReinitableConfigInterface::class, false)) {
    eval('namespace Magento\Framework\App\Config; interface ReinitableConfigInterface extends ScopeConfigInterface { public function reinit(); }');
}

// Stub EncryptorInterface
if (!interface_exists(\Magento\Framework\Encryption\EncryptorInterface::class, false)) {
    eval('namespace Magento\Framework\Encryption; interface EncryptorInterface { public function encrypt($data); public function decrypt($data); }');
}

// --- Carrier Stubs (needed by BobGo carrier class) ---

// Stub CarrierInterface
if (!interface_exists(\Magento\Shipping\Model\Carrier\CarrierInterface::class, false)) {
    eval('namespace Magento\Shipping\Model\Carrier; interface CarrierInterface { public function collectRates(\Magento\Quote\Model\Quote\Address\RateRequest $request); public function getAllowedMethods(); }');
}

// Stub AbstractCarrier
if (!class_exists(\Magento\Shipping\Model\Carrier\AbstractCarrier::class, false)) {
    eval('namespace Magento\Shipping\Model\Carrier; abstract class AbstractCarrier extends \Magento\Framework\DataObject { protected $_code; protected $_scopeConfig; protected $_rateErrorFactory; protected $_logger; public function __construct(...$args) { $this->_scopeConfig = $args[0] ?? null; $this->_rateErrorFactory = $args[1] ?? null; $this->_logger = $args[2] ?? null; } public function getConfigData($field) { if ($this->_scopeConfig) { return $this->_scopeConfig->getValue("carriers/" . $this->_code . "/" . $field, \Magento\Store\Model\ScopeInterface::SCOPE_STORE); } return null; } public function getConfigFlag($field) { return (bool) $this->getConfigData($field); } public function isActive() { return $this->getConfigFlag("active"); } }');
}

// Stub AbstractCarrierOnline
if (!class_exists(\Magento\Shipping\Model\Carrier\AbstractCarrierOnline::class, false)) {
    eval('namespace Magento\Shipping\Model\Carrier; abstract class AbstractCarrierOnline extends AbstractCarrier { protected $_rateFactory; protected $_rateMethodFactory; protected $stockRegistry; public function __construct(...$args) { parent::__construct(...$args); $this->_rateFactory = $args[5] ?? null; $this->_rateMethodFactory = $args[6] ?? null; $this->stockRegistry = $args[14] ?? null; } public function getContainerTypes(?\Magento\Framework\DataObject $params = null) { return []; } public function getDeliveryConfirmationTypes(?\Magento\Framework\DataObject $params = null) { return []; } public function processAdditionalValidation(\Magento\Framework\DataObject $request) { return $this; } public function getAllItems(\Magento\Framework\DataObject $request) { $items = $request->getData("all_items"); return is_array($items) ? $items : []; } }');
}

// --- Helper/Block Stubs ---

// Stub AbstractHelper
if (!class_exists(\Magento\Framework\App\Helper\AbstractHelper::class, false)) {
    eval('namespace Magento\Framework\App\Helper; abstract class AbstractHelper { protected $scopeConfig; protected $_logger; public function __construct(?Context $context = null) { if ($context) { $this->scopeConfig = $context->getScopeConfig(); $this->_logger = $context->getLogger(); } } }');
}

// Stub Helper\Context
if (!class_exists(\Magento\Framework\App\Helper\Context::class, false)) {
    eval('namespace Magento\Framework\App\Helper; class Context { protected $scopeConfig; protected $logger; public function __construct($scopeConfig = null, $logger = null) {} public function getScopeConfig() { return $this->scopeConfig; } public function getLogger() { return $this->logger; } }');
}

// Stub Backend Block Context
if (!class_exists(\Magento\Backend\Block\Template\Context::class, false)) {
    eval('namespace Magento\Backend\Block\Template; class Context { public function __construct() {} }');
}

// Stub Config Form Field (parent of Version block)
if (!class_exists(\Magento\Config\Block\System\Config\Form\Field::class, false)) {
    eval('namespace Magento\Config\Block\System\Config\Form; class Field { public function __construct(...$args) {} protected function _getElementHtml(\Magento\Framework\Data\Form\Element\AbstractElement $element): string { return ""; } }');
}

// Stub AbstractElement for form fields
if (!class_exists(\Magento\Framework\Data\Form\Element\AbstractElement::class, false)) {
    eval('namespace Magento\Framework\Data\Form\Element; abstract class AbstractElement extends \Magento\Framework\DataObject { public function setData($k, $v = null) { $this->_data[$k] = $v; return $this; } public function getData($k = null) { return $this->_data[$k] ?? null; } }');
}

// --- Stub common Magento interfaces needed for mocking ---
$stubs = [
    'interface' => [
        'Magento\Sales\Api\Data\OrderInterface' => ['getEntityId', 'getIncrementId', 'getGrandTotal', 'getTotalDue', 'getDiscountAmount', 'getOrderCurrencyCode', 'getStatus', 'getShippingMethod', 'getShippingDescription', 'getCreatedAt', 'getUpdatedAt', 'getShippingAddress', 'getBillingAddress', 'getItems', 'getData', 'setData', 'getCustomerFirstname', 'getCustomerLastname', 'getCustomerEmail', 'getStoreId', 'getCustomerNote', 'getTaxAmount', 'getTotalRefunded', 'getState'],
        'Magento\Sales\Api\Data\OrderItemInterface' => ['getItemId', 'getParentItemId', 'getProductId', 'getProductType', 'getSku', 'getName', 'getPriceInclTax', 'getQtyOrdered', 'getWeight', 'setWeight', 'getData', 'setData', 'getProductOptions'],
        'Magento\Sales\Api\Data\OrderAddressInterface' => ['getStreet', 'getCity', 'getPostcode', 'getRegion', 'getCountryId', 'getCompany', 'getFirstname', 'getLastname', 'getTelephone'],
        'Magento\Sales\Api\OrderRepositoryInterface' => ['save', 'get', 'getList', 'delete'],
        'Magento\Sales\Api\OrderItemRepositoryInterface' => ['save', 'get', 'getList', 'delete'],
        'Magento\Framework\App\Config\ScopeConfigInterface' => ['getValue', 'isSetFlag'],
        'Magento\Framework\Message\ManagerInterface' => ['addSuccessMessage', 'addErrorMessage', 'addWarningMessage', 'addNoticeMessage'],
        'Magento\Catalog\Api\ProductRepositoryInterface' => ['getById', 'get', 'save', 'delete'],
        'Magento\Catalog\Api\Data\ProductInterface' => ['getImage', 'getSku', 'getName', 'getId', 'getData'],
        'Magento\Store\Api\Data\StoreInterface' => ['getBaseUrl', 'getId', 'getCode'],
        'Magento\Sales\Api\Data\OrderSearchResultInterface' => ['getItems', 'getTotalCount'],
        'Magento\Sales\Api\ShipOrderInterface' => ['execute'],
        'Magento\Sales\Api\Data\ShipmentItemCreationInterface' => ['setOrderItemId', 'setQty'],
        'Magento\Sales\Api\Data\ShipmentTrackCreationInterface' => ['setCarrierCode', 'setTitle', 'setTrackNumber'],
        'Magento\CatalogInventory\Api\StockRegistryInterface' => ['getStockItem'],
        'Magento\Framework\Module\ModuleListInterface' => ['getOne', 'getAll', 'getNames', 'has'],
    ],
    'class' => [
        'Magento\Framework\HTTP\Client\Curl' => ['addHeader', 'get', 'post', 'getStatus', 'getBody', 'setOption'],
        'Magento\Framework\HTTP\Client\CurlFactory' => ['create'],
        'Magento\Framework\Api\SearchCriteriaBuilder' => ['addFilter', 'setPageSize', 'setCurrentPage', 'setSortOrders', 'create'],
        'Magento\Framework\Api\SearchCriteria' => [],
        // Note: Magento\Sales\Model\Order is defined separately below (implements OrderInterface)

        'Magento\Sales\Model\Order\Shipment' => ['getTracks', 'getAllTracks', 'getEntityId', 'addTrack', 'save', 'getLastItem', 'getData', 'setData'],
        'Magento\Sales\Model\Order\Item' => ['getItemId', 'getParentItemId', 'getProductId', 'getProductType', 'getSku', 'getName', 'getPriceInclTax', 'getQtyOrdered', 'getQtyToShip', 'getWeight', 'getData', 'setData'],
        'Magento\Sales\Model\Order\Shipment\Track' => ['getTrackNumber', 'setTrackNumber', 'getTitle', 'setCarrierCode', 'setTitle', 'save'],
        'Magento\Sales\Model\Order\Shipment\TrackFactory' => ['create'],
        // Note: ShipmentCollection is defined separately below (implements IteratorAggregate)
        'Magento\Store\Model\Store' => ['getBaseUrl'],
        'Magento\Store\Model\StoreManagerInterface' => ['getStore'],
        'Magento\Framework\Xml\Security' => [],
        'Magento\Shipping\Model\Simplexml\ElementFactory' => ['create'],
        'Magento\Shipping\Model\Rate\ResultFactory' => ['create'],
        'Magento\Shipping\Model\Rate\Result' => ['append', 'getError', 'getAllRates'],
        'Magento\Shipping\Model\Tracking\ResultFactory' => ['create'],
        'Magento\Shipping\Model\Tracking\Result\StatusFactory' => ['create'],
        'Magento\Shipping\Model\Tracking\Result\ErrorFactory' => ['create'],
        'Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory' => ['create'],
        'Magento\Quote\Model\Quote\Address\RateResult\MethodFactory' => ['create'],
        'Magento\Quote\Model\Quote\Address\RateResult\Method' => ['setCarrier', 'setCarrierTitle', 'setMethod', 'setMethodTitle', 'setPrice', 'setCost'],
        'Magento\Directory\Model\RegionFactory' => ['create'],
        'Magento\Directory\Model\CountryFactory' => ['create'],
        'Magento\Directory\Model\Country' => ['loadByCode', 'getName'],
        'Magento\Directory\Model\CurrencyFactory' => ['create'],
        'Magento\Directory\Helper\Data' => [],
        'Magento\Catalog\Model\ResourceModel\Product\CollectionFactory' => ['create'],
        'Magento\Framework\App\Request\Http' => ['getContent', 'getParam'],
        'Magento\Catalog\Model\Product' => ['isVirtual', 'getWeight', 'getImage', 'getId'],
        'Magento\Quote\Model\Quote\Item' => ['getProduct', 'getName', 'getSku', 'getQty', 'getPrice', 'getWeight', 'getStore', 'getProductType'],
    ],
];

foreach ($stubs['interface'] as $fqcn => $methods) {
    if (!interface_exists($fqcn, false) && !class_exists($fqcn, false)) {
        $parts = explode('\\', $fqcn);
        $className = array_pop($parts);
        $namespace = implode('\\', $parts);
        $methodDecls = '';
        // array_unique: a duplicate name in the list below is a typo, not a
        // reason to fatal the whole suite with "cannot redeclare".
        foreach (array_unique($methods) as $method) {
            $methodDecls .= "public function {$method}();\n";
        }
        eval("namespace {$namespace}; interface {$className} { {$methodDecls} }");
    }
}

foreach ($stubs['class'] as $fqcn => $methods) {
    if (!class_exists($fqcn, false)) {
        $parts = explode('\\', $fqcn);
        $className = array_pop($parts);
        $namespace = implode('\\', $parts);
        $methodDecls = '';
        foreach ($methods as $method) {
            $methodDecls .= "public function {$method}() { return null; }\n";
        }
        eval("namespace {$namespace}; class {$className} { {$methodDecls} }");
    }
}

// Magento\Sales\Model\Order must implement OrderInterface so mocks satisfy return type hints
if (!class_exists(\Magento\Sales\Model\Order::class, false)) {
    eval('namespace Magento\Sales\Model; class Order implements \Magento\Sales\Api\Data\OrderInterface { const STATE_NEW = "new"; const STATE_PROCESSING = "processing"; const STATE_HOLDED = "holded"; const STATE_COMPLETE = "complete"; const STATE_CLOSED = "closed"; const STATE_CANCELED = "canceled"; public function getEntityId() { return null; } public function getIncrementId() { return null; } public function getGrandTotal() { return null; } public function getTotalDue() { return null; } public function getDiscountAmount() { return null; } public function getOrderCurrencyCode() { return null; } public function getStatus() { return null; } public function getShippingMethod() { return null; } public function getShippingDescription() { return null; } public function getCreatedAt() { return null; } public function getUpdatedAt() { return null; } public function getShippingAddress() { return null; } public function getBillingAddress() { return null; } public function getItems() { return null; } public function getData($k = null) { return null; } public function setData($k = null, $v = null) { return null; } public function getCustomerFirstname() { return null; } public function getCustomerLastname() { return null; } public function getCustomerEmail() { return null; } public function getState() { return null; } public function canShip() { return null; } public function getShipmentsCollection() { return null; } public function getAllItems() { return null; } public function addCommentToStatusHistory($comment = null) { return null; } public function save() { return null; } public function getIsVirtual() { return null; } public function getStoreId() { return null; } public function getCustomerNote() { return null; } public function getTaxAmount() { return null; } public function getTotalRefunded() { return null; } }');
}

// ShipmentCollection must implement IteratorAggregate so foreach works on mocks
if (!class_exists(\Magento\Sales\Model\ResourceModel\Order\Shipment\Collection::class, false)) {
    eval('namespace Magento\Sales\Model\ResourceModel\Order\Shipment; class Collection implements \IteratorAggregate { public function getItems() { return []; } public function getSize() { return 0; } public function getLastItem() { return null; } public function getIterator(): \Traversable { return new \ArrayIterator([]); } }');
}

// RateRequest is special: it extends DataObject and needs setters/getters to work via __call
if (!class_exists(\Magento\Quote\Model\Quote\Address\RateRequest::class, false)) {
    eval('namespace Magento\Quote\Model\Quote\Address; class RateRequest extends \Magento\Framework\DataObject {}');
}

// OptionSourceInterface (implemented by source model classes)
if (!interface_exists(\Magento\Framework\Data\OptionSourceInterface::class, false)) {
    eval('namespace Magento\Framework\Data; interface OptionSourceInterface { public function toOptionArray(); }');
}

// Factory stubs for Magento DI factories
$factories = [
    'Magento\Sales\Api\Data\ShipmentItemCreationInterfaceFactory' => 'Magento\Sales\Api\Data\ShipmentItemCreationInterface',
    'Magento\Sales\Api\Data\ShipmentTrackCreationInterfaceFactory' => 'Magento\Sales\Api\Data\ShipmentTrackCreationInterface',
];

foreach ($factories as $factoryClass => $targetInterface) {
    if (!class_exists($factoryClass, false)) {
        $parts = explode('\\', $factoryClass);
        $factoryName = array_pop($parts);
        $namespace = implode('\\', $parts);
        eval("namespace {$namespace}; class {$factoryName} { public function create() { return null; } }");
    }
}

// CURLOPT constants needed by BobGoApiClient
if (!defined('CURLOPT_TIMEOUT')) {
    define('CURLOPT_TIMEOUT', 13);
}
if (!defined('CURLOPT_CUSTOMREQUEST')) {
    define('CURLOPT_CUSTOMREQUEST', 10036);
}

// Stub Magento DateTime helper (used by OrderPushService / FulfillmentService for timestamps)
if (!class_exists(\Magento\Framework\Stdlib\DateTime\DateTime::class, false)) {
    eval('namespace Magento\Framework\Stdlib\DateTime; class DateTime { public function gmtDate($format = null, $input = null) { return gmdate("Y-m-d H:i:s"); } }');
}

// Stub Magento Model\AbstractModel (parent of SyncLog) — minimal DataObject behaviour is enough
if (!class_exists(\Magento\Framework\Model\AbstractModel::class, false)) {
    eval('namespace Magento\Framework\Model; abstract class AbstractModel extends \Magento\Framework\DataObject { protected function _construct(): void {} protected function _init($resourceModel) {} }');
}

// Stub Magento ResourceModel AbstractDb (parent of SyncLog resource model)
if (!class_exists(\Magento\Framework\Model\ResourceModel\Db\AbstractDb::class, false)) {
    eval('namespace Magento\Framework\Model\ResourceModel\Db; abstract class AbstractDb { protected function _construct(): void {} protected function _init($table, $idField) {} public function save($object) { return $this; } public function delete($object) { return $this; } public function getConnection() { return null; } public function getMainTable() { return ""; } }');
}

// Stub Magento controller/block parent classes used by our concrete classes — minimum
// shape needed for PHPStan / PHPUnit autoloading. None of these are exercised at runtime
// in unit tests; they only need to be loadable.
if (!class_exists(\Magento\Framework\App\Action\Action::class, false)) {
    eval('namespace Magento\Framework\App\Action; class Action { protected $_request; protected $messageManager; protected $_redirect; public function __construct($context = null) {} public function execute() {} protected function _redirect(...$args) {} public function getRequest() { return $this->_request; } public function getUrl(...$args) {} }');
}
if (!interface_exists(\Magento\Framework\App\CsrfAwareActionInterface::class, false)) {
    eval('namespace Magento\Framework\App; interface CsrfAwareActionInterface { public function createCsrfValidationException(\Magento\Framework\App\RequestInterface $request): ?\Magento\Framework\App\Request\InvalidRequestException; public function validateForCsrf(\Magento\Framework\App\RequestInterface $request): ?bool; }');
}
if (!interface_exists(\Magento\Framework\App\Action\HttpPostActionInterface::class, false)) {
    eval('namespace Magento\Framework\App\Action; interface HttpPostActionInterface {}');
}
if (!interface_exists(\Magento\Sales\Api\ShipmentRepositoryInterface::class, false)) {
    eval('namespace Magento\Sales\Api; interface ShipmentRepositoryInterface { public function get($id); public function save($shipment); }');
}
// Admin grid + notification surfaces. Stubbed rather than excluded from static
// analysis so the classes that use them still get checked.
if (!interface_exists(\Magento\Framework\Api\Search\SearchResultInterface::class, false)) {
    eval('namespace Magento\Framework\Api\Search; interface SearchResultInterface {}');
}
if (!interface_exists(\Magento\Framework\Event\ManagerInterface::class, false)) {
    eval('namespace Magento\Framework\Event; interface ManagerInterface { public function dispatch($eventName, array $data = []); }');
}
if (!interface_exists(\Magento\Framework\Data\Collection\EntityFactoryInterface::class, false)) {
    eval('namespace Magento\Framework\Data\Collection; interface EntityFactoryInterface { public function create($className, array $data = []); }');
}
if (!interface_exists(\Magento\Framework\Data\Collection\Db\FetchStrategyInterface::class, false)) {
    eval('namespace Magento\Framework\Data\Collection\Db; interface FetchStrategyInterface { public function fetchAll($select, array $bindParams = []); }');
}
if (!class_exists(\Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult::class, false)) {
    eval('namespace Magento\Framework\View\Element\UiComponent\DataProvider; class SearchResult { public function __construct($entityFactory = null, $logger = null, $fetchStrategy = null, $eventManager = null, $mainTable = null, $resourceModel = null) {} }');
}
if (!interface_exists(\Magento\Framework\Notification\MessageInterface::class, false)) {
    eval('namespace Magento\Framework\Notification; interface MessageInterface { const SEVERITY_CRITICAL = 1; const SEVERITY_MAJOR = 2; const SEVERITY_MINOR = 3; const SEVERITY_NOTICE = 4; public function getIdentity(); public function isDisplayed(); public function getText(); public function getSeverity(); }');
}
if (!class_exists(\Magento\Framework\Controller\ResultFactory::class, false)) {
    eval('namespace Magento\Framework\Controller; class ResultFactory { const TYPE_PAGE = "page"; const TYPE_JSON = "json"; const TYPE_REDIRECT = "redirect"; public function create($type, array $args = []) { return null; } }');
}
if (!class_exists(\Magento\Backend\App\Action::class, false)) {
    eval('namespace Magento\Backend\App; class Action extends \Magento\Framework\App\Action\Action { protected $resultFactory; }');
}
if (!interface_exists(\Magento\Framework\App\Action\HttpGetActionInterface::class, false)) {
    eval('namespace Magento\Framework\App\Action; interface HttpGetActionInterface {}');
}
if (!class_exists(\Magento\Store\Model\App\Emulation::class, false)) {
    eval('namespace Magento\Store\Model\App; class Emulation { public function startEnvironmentEmulation($storeId, $area = "frontend", $force = false) { return $this; } public function stopEnvironmentEmulation() { return $this; } }');
}
if (!class_exists(\Magento\Framework\App\Area::class, false)) {
    eval('namespace Magento\Framework\App; class Area { const AREA_FRONTEND = "frontend"; const AREA_ADMINHTML = "adminhtml"; }');
}
if (!interface_exists(\Magento\Framework\Api\SearchCriteriaInterface::class, false)) {
    eval('namespace Magento\Framework\Api; interface SearchCriteriaInterface {}');
}
if (!interface_exists(\Magento\Framework\App\CacheInterface::class, false)) {
    eval('namespace Magento\Framework\App; interface CacheInterface { public function load($identifier); public function save($data, $identifier, $tags = [], $lifeTime = null); public function remove($identifier); public function clean($tags = []); }');
}
if (!class_exists(\Magento\Framework\App\ResourceConnection::class, false)) {
    eval('namespace Magento\Framework\App; class ResourceConnection { public function getConnection($name = null) { return null; } public function getTableName($name) { return $name; } }');
}
if (!interface_exists(\Magento\Framework\DB\Adapter\AdapterInterface::class, false)) {
    eval('namespace Magento\Framework\DB\Adapter; interface AdapterInterface { public function insertOnDuplicate($table, array $data, array $fields = []); public function select(); public function fetchCol($select); public function fetchOne($select); public function update($table, array $bind, $where = ""); public function delete($table, $where = ""); }');
}
if (!interface_exists(\Magento\Sales\Api\OrderManagementInterface::class, false)) {
    eval('namespace Magento\Sales\Api; interface OrderManagementInterface { public function cancel($id); public function hold($id); public function unHold($id); }');
}
if (!class_exists(\Magento\Framework\FlagManager::class, false)) {
    eval('namespace Magento\Framework; class FlagManager { public function getFlagData($code) { return null; } public function saveFlag($code, $value) { return true; } public function deleteFlag($code) { return true; } }');
}
if (!interface_exists(\Magento\Framework\Api\AttributeInterface::class, false)) {
    eval('namespace Magento\Framework\Api; interface AttributeInterface { public function getAttributeCode(); public function getValue(); public function setAttributeCode($code); public function setValue($value); }');
}
if (!class_exists(\Magento\Framework\Exception\AlreadyExistsException::class, false)) {
    eval('namespace Magento\Framework\Exception; class AlreadyExistsException extends LocalizedException {}');
}
if (!class_exists(\Magento\Framework\Data\Form\FormKey::class, false)) {
    eval('namespace Magento\Framework\Data\Form; class FormKey { public function getFormKey(): string { return ""; } }');
}
if (!class_exists(\Magento\Sales\Api\Data\OrderAddressExtensionFactory::class, false)) {
    eval('namespace Magento\Sales\Api\Data; class OrderAddressExtensionFactory { public function create() { return new \stdClass(); } }');
}
if (!class_exists(\Magento\Quote\Model\Quote\Address\ToOrderAddress::class, false)) {
    eval('namespace Magento\Quote\Model\Quote\Address; class ToOrderAddress { public function convert($quoteAddress, $data = []) {} }');
}
if (!interface_exists(\Magento\Quote\Api\Data\AddressInterface::class, false)) {
    eval('namespace Magento\Quote\Api\Data; interface AddressInterface {}');
}
if (!interface_exists(\Magento\Sales\Api\Data\OrderAddressInterface::class, false)) {
    eval('namespace Magento\Sales\Api\Data; interface OrderAddressInterface {}');
}
if (!interface_exists(\Magento\Framework\App\RequestInterface::class, false)) {
    eval('namespace Magento\Framework\App; interface RequestInterface { public function getContent(); public function getHeader($name); public function getParam($name); }');
}
if (!class_exists(\Magento\Framework\App\Request\InvalidRequestException::class, false)) {
    eval('namespace Magento\Framework\App\Request; class InvalidRequestException extends \Exception {}');
}
if (!class_exists(\Magento\Framework\App\Action\Context::class, false)) {
    eval('namespace Magento\Framework\App\Action; class Context { public function __construct() {} }');
}
if (!class_exists(\Magento\Framework\Controller\Result\JsonFactory::class, false)) {
    eval('namespace Magento\Framework\Controller\Result; class JsonFactory { public function create() {} }');
}
if (!class_exists(\Magento\Backend\App\Action::class, false)) {
    eval('namespace Magento\Backend\App; class Action extends \Magento\Framework\App\Action\Action { protected $messageManager; protected function _redirect(...$args) {} }');
}
if (!class_exists(\Magento\Backend\App\Action\Context::class, false)) {
    eval('namespace Magento\Backend\App\Action; class Context extends \Magento\Framework\App\Action\Context {}');
}
if (!class_exists(\Magento\Framework\View\Element\Template::class, false)) {
    eval('namespace Magento\Framework\View\Element; class Template { protected $_data = []; public function __construct(...$args) {} public function getUrl(...$args) {} public function getData($k = null) { return $this->_data[$k] ?? null; } public function setData($k, $v = null) { $this->_data[$k] = $v; return $this; } }');
}
if (!class_exists(\Magento\Backend\Block\Template::class, false)) {
    eval('namespace Magento\Backend\Block; class Template extends \Magento\Framework\View\Element\Template { public function __construct(...$args) {} }');
}
if (!class_exists(\Magento\Framework\View\Element\Html\Link\Current::class, false)) {
    eval('namespace Magento\Framework\View\Element\Html\Link; class Current extends \Magento\Framework\View\Element\Template { public function __construct(...$args) {} public function getMca() { return ""; } }');
}
if (!class_exists(\Magento\Framework\Registry::class, false)) {
    eval('namespace Magento\Framework; class Registry { public function registry($key) { return null; } public function register($key, $value, $overwrite = false) {} public function unregister($key) {} }');
}

// Stub Magento ResourceModel AbstractCollection (parent of SyncLog collection)
if (!class_exists(\Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection::class, false)) {
    eval('namespace Magento\Framework\Model\ResourceModel\Db\Collection; abstract class AbstractCollection implements \IteratorAggregate { protected function _construct(): void {} protected function _init($model, $resourceModel) {} public function addFieldToFilter($field, $condition = null) { return $this; } public function setPageSize($size) { return $this; } public function getSize() { return 0; } public function getFirstItem() { return null; } public function getIterator(): \Traversable { return new \ArrayIterator([]); } }');
}

// Factory class generation in real Magento is dynamic. Stub the two we
// exercise in unit tests so createMock() can resolve them.
if (!class_exists(\BobGroup\BobGo\Model\SyncLogFactory::class, false)) {
    eval('namespace BobGroup\BobGo\Model; class SyncLogFactory { public function create(array $data = []) {} }');
}
if (!class_exists(\BobGroup\BobGo\Model\ResourceModel\SyncLog\CollectionFactory::class, false)) {
    eval('namespace BobGroup\BobGo\Model\ResourceModel\SyncLog; class CollectionFactory { public function create(array $data = []) {} }');
}
