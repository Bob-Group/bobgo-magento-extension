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

// Stub Magento DataObject if not already available
if (!class_exists(\Magento\Framework\DataObject::class, false)) {
    eval('namespace Magento\Framework; class DataObject { protected $_data = []; public function __call($m, $a) { return null; } public function getData($k = null) { return $this->_data[$k] ?? null; } public function setData($k, $v = null) { $this->_data[$k] = $v; return $this; } }');
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

// Stub common Magento interfaces needed for mocking
$stubs = [
    'interface' => [
        'Magento\Sales\Api\Data\OrderInterface' => ['getEntityId', 'getIncrementId', 'getGrandTotal', 'getTotalDue', 'getDiscountAmount', 'getOrderCurrencyCode', 'getStatus', 'getShippingMethod', 'getShippingDescription', 'getCreatedAt', 'getUpdatedAt', 'getShippingAddress', 'getItems', 'getData', 'setData'],
        'Magento\Sales\Api\Data\OrderItemInterface' => ['getItemId', 'getParentItemId', 'getSku', 'getName', 'getPriceInclTax', 'getQtyOrdered', 'getWeight', 'setWeight'],
        'Magento\Sales\Api\Data\OrderAddressInterface' => ['getStreet', 'getCity', 'getPostcode', 'getRegion', 'getCountryId', 'getCompany'],
        'Magento\Sales\Api\OrderRepositoryInterface' => ['save', 'get', 'getList', 'delete'],
        'Magento\Framework\App\Config\ScopeConfigInterface' => ['getValue', 'isSetFlag'],
        'Magento\Framework\Message\ManagerInterface' => ['addSuccessMessage', 'addErrorMessage', 'addWarningMessage', 'addNoticeMessage'],
        'Magento\Framework\UrlInterface' => ['getUrl', 'getBaseUrl'],
        'Magento\Sales\Api\Data\OrderSearchResultInterface' => ['getItems', 'getTotalCount'],
        'Magento\Sales\Api\ShipOrderInterface' => ['execute'],
        'Magento\Sales\Api\Data\ShipmentItemCreationInterface' => ['setOrderItemId', 'setQty'],
        'Magento\Sales\Api\Data\ShipmentTrackCreationInterface' => ['setCarrierCode', 'setTitle', 'setTrackNumber'],
    ],
    'class' => [
        'Magento\Framework\HTTP\Client\Curl' => [],
        'Magento\Framework\HTTP\Client\CurlFactory' => ['create'],
        'Magento\Framework\Api\SearchCriteriaBuilder' => ['addFilter', 'create'],
        'Magento\Framework\Api\SearchCriteria' => [],
        'Magento\Sales\Model\Order\Shipment' => ['getTracks', 'getEntityId'],
        'Magento\Sales\Model\Order\Shipment\Track' => ['getTrackNumber'],
        'Magento\Sales\Model\Order\Shipment\TrackFactory' => ['create'],
        'Magento\Sales\Model\ResourceModel\Order\Shipment\Collection' => ['getItems'],
        'Magento\Store\Model\Store' => ['getBaseUrl'],
        'Magento\Store\Model\StoreManagerInterface' => [],
    ],
];

foreach ($stubs['interface'] as $fqcn => $methods) {
    if (!interface_exists($fqcn, false) && !class_exists($fqcn, false)) {
        $parts = explode('\\', $fqcn);
        $className = array_pop($parts);
        $namespace = implode('\\', $parts);
        $methodDecls = '';
        foreach ($methods as $method) {
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
