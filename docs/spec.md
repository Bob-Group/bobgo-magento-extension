# Bob Go Magento 2 Extension - Developer Specification

> **Module:** `BobGroup_BobGo`
> **Namespace:** `BobGroup\BobGo`
> **PHP Compatibility:** ^7.4 || ^8.0 || ^8.2
> **Current Version:** 1.0.62 (source of truth: `package.json`)
> **Carrier Code:** `bobgo`

---

## Table of Contents

1. [Overview](#1-overview)
2. [Installation](#2-installation)
3. [Project Structure](#3-project-structure)
4. [Configuration Reference](#4-configuration-reference)
5. [Architecture](#5-architecture)
6. [API Integration](#6-api-integration)
7. [Core Carrier - Rate Calculation](#7-core-carrier---rate-calculation)
8. [Order Push](#8-order-push)
9. [Fulfillment Sync](#9-fulfillment-sync)
10. [Webhook System](#10-webhook-system)
11. [Suburb Field (Custom Extension Attribute)](#11-suburb-field-custom-extension-attribute)
12. [Weight Handling](#12-weight-handling)
13. [Tracking Page](#13-tracking-page)
14. [Observers](#14-observers)
15. [Plugins](#15-plugins)
16. [Frontend JavaScript](#16-frontend-javascript)
17. [Database Schema](#17-database-schema)
18. [Cron Jobs](#18-cron-jobs)
19. [Dependency Injection](#19-dependency-injection)
20. [Admin Configuration UI](#20-admin-configuration-ui)
21. [Data Flow Diagrams](#21-data-flow-diagrams)
22. [Error Handling and Logging](#22-error-handling-and-logging)
23. [Security](#23-security)
24. [Testing](#24-testing)
25. [Build and CI/CD](#25-build-and-cicd)
26. [Version Management](#26-version-management)
27. [Known Limitations](#27-known-limitations)
28. [Troubleshooting](#28-troubleshooting)
29. [File Reference](#29-file-reference)

---

## 1. Overview

The Bob Go Shipping Extension integrates Magento 2 stores with the [Bob Go](https://www.bobgo.co.za) shipping platform for South African e-commerce. It provides:

- **Rates at Checkout** - Real-time shipping rate calculation from the Bob Go API displayed during checkout
- **Order Push** - Automatic synchronization of Magento orders to Bob Go for fulfillment
- **Fulfillment Sync** - Automatic creation of Magento shipments when orders are fulfilled in Bob Go (via webhooks and cron polling)
- **Tracking Updates** - Shipment tracking number synchronization from Bob Go back to Magento
- **Suburb Field** - Custom checkout field required for South African shipping address accuracy
- **Order Tracking Page** - Customer-facing tracking page (currently hidden/disabled)

### Key Concepts

| Concept | Description |
|---------|-------------|
| **Carrier Code** | `bobgo` - used throughout Magento's shipping framework |
| **Bob Go Order ID** | Stored on `sales_order.bobgo_order_id` after an order is pushed to Bob Go |
| **Channel Ref ID** | Magento's `entity_id` used as the unique order identifier in Bob Go |
| **Environment** | Sandbox or Production - determines which Bob Go API base URL is used |

---

## 2. Installation

### Via app/code (recommended for this extension)

```bash
# Copy the module into the Magento codebase
cp -r BobGroup /path/to/magento/app/code/

# Enable the module
php bin/magento module:enable BobGroup_BobGo

# Run setup upgrade (applies db_schema.xml changes)
php bin/magento setup:upgrade

# Compile DI (production mode)
php bin/magento setup:di:compile

# Deploy static content (production mode)
php bin/magento setup:static-content:deploy

# Clear cache
php bin/magento cache:clean
php bin/magento cache:flush
```

### Module Dependencies

Defined in `etc/module.xml` `<sequence>`:

- `Magento_Webapi` - REST API route for webhooks
- `Magento_Catalog` - Product data for rate requests
- `Magento_Store` - Store configuration and base URLs
- `Magento_Sales` - Orders, shipments, tracking
- `Magento_Quote` - Cart/quote for rate calculation
- `Magento_SalesRule` - Discount calculations
- `Magento_Config` - System configuration
- `Magento_Shipping` - Carrier framework

### Post-Installation Configuration

1. Navigate to **Stores > Configuration > Sales > Shipping Methods > Bob Go**
2. Set the **Environment** (Sandbox or Production)
3. Enter your **API Key** (obtained from Bob Go dashboard)
4. Enable **Bob Go rates at checkout**
5. Optionally enable **Order push** and **Fulfillment sync**
6. Set your store's **Suburb** under **Stores > Configuration > General > Store Information**

---

## 3. Project Structure

```
BobGroup/BobGo/
├── Api/
│   ├── BobGoApiClient.php          # HTTP client for Bob Go API (Bearer auth, CURL)
│   ├── BobGoApiException.php       # Custom exception with status code & response body
│   ├── OrderMapperInterface.php    # Interface: order-to-API-payload mapping
│   └── WebhookReceiverInterface.php # Interface: incoming webhook handling
├── Block/
│   ├── System/Config/Form/Field/
│   │   └── Version.php             # Displays version as clickable link in admin config
│   ├── TrackingBlock.php           # Template block for tracking page
│   └── TrackOrderLink.php          # Conditional "Track my order" link in customer account
├── Controller/
│   └── Tracking/
│       └── Index.php               # Frontend tracking page controller
├── Helper/
│   └── Data.php                    # Module helper (version, debug logging)
├── Model/
│   ├── Carrier/
│   │   ├── AdditionalInfo.php      # Extracts suburb/company/phone from checkout request body
│   │   └── BobGo.php              # Main carrier class (~1000 lines) - rate calculation
│   ├── Config/
│   │   └── ApiConfig.php           # Centralized API configuration (keys, URLs, feature flags)
│   ├── Source/
│   │   ├── Dropoff.php             # Config source: dropoff options
│   │   ├── Environment.php         # Config source: sandbox/production select
│   │   ├── Freemethod.php          # Config source: free method options
│   │   ├── Generic.php             # Base config source class
│   │   ├── Method.php              # Config source: shipping method options
│   │   ├── Packaging.php           # Config source: packaging options
│   │   └── Unitofmeasure.php       # Config source: weight unit options
│   ├── WebhookReceiver.php         # Routes webhook topics to FulfillmentService
│   └── WebhookValidator.php        # Validates webhook payload structure
├── Observer/
│   ├── ConfigChangeObserver.php    # Tests connectivity on admin config save
│   ├── ModifyShippingDescription.php # Cleans up shipping description on order place
│   └── OrderSaveObserver.php       # Pushes/updates orders to Bob Go on save
├── Plugin/
│   ├── AddWeightUnitToOrderPlugin.php # Converts LBS to KG on order save
│   ├── Checkout/Block/
│   │   └── LayoutProcessorPlugin.php  # Injects suburb field into checkout form
│   └── OrderRepositoryPlugin.php      # Loads/saves bobgo_order_id extension attribute
├── Service/
│   ├── FulfillmentCronService.php     # Cron: polls Bob Go for new fulfillments
│   ├── FulfillmentService.php         # Creates Magento shipments from Bob Go fulfillments
│   ├── OrderMapper.php                # Maps Magento orders to Bob Go API payload format
│   ├── OrderPushService.php           # POST/PATCH orders to Bob Go API
│   └── WebhookSubscriptionService.php # Manages webhook subscriptions with Bob Go
├── Test/
│   └── Unit/                          # PHPUnit test suite (see Testing section)
├── etc/
│   ├── acl.xml                        # Access control (minimal - inherits admin)
│   ├── adminhtml/
│   │   ├── events.xml                 # Admin event: config change observer
│   │   └── system.xml                 # Admin configuration UI fields
│   ├── config.xml                     # Default configuration values
│   ├── crontab.xml                    # Cron job: fulfillment sync every 15 min
│   ├── db_schema.xml                  # Database: adds bobgo_order_id to sales_order
│   ├── db_schema_whitelist.json       # Schema whitelist for declarative schema
│   ├── di.xml                         # Dependency injection: preferences, plugins, arguments
│   ├── events.xml                     # Frontend events: order save, order place
│   ├── extension_attributes.xml       # Extension attributes: suburb, bobgo_order_id
│   ├── frontend/
│   │   ├── di.xml                     # Frontend DI: checkout layout processor plugin
│   │   └── routes.xml                 # Frontend route: /bobgo/*
│   ├── module.xml                     # Module definition and dependencies
│   └── webapi.xml                     # REST API route: POST /V1/bobgo/webhook
├── view/
│   └── frontend/
│       ├── layout/
│       │   ├── bobgo_tracking_index.xml   # Tracking page layout
│       │   ├── checkout_cart_index.xml    # Cart page layout
│       │   ├── checkout_index_index.xml   # Checkout: registers rate validators
│       │   └── customer_account.xml       # Customer account: "Track my order" link
│       ├── requirejs-config.js            # RequireJS mixin for shipping info
│       ├── templates/
│       │   └── tracking/
│       │       └── index.phtml            # Tracking page template
│       └── web/js/
│           ├── action/
│           │   └── set-shipping-information-mixin.js  # Suburb → extension_attributes
│           ├── model/
│           │   ├── set-shipping-information.js         # (unused, superseded by mixin)
│           │   ├── shipping-rates-validation-rules.js  # Required fields for rate validation
│           │   └── shipping-rates-validator.js          # Validates address before rate fetch
│           └── view/
│               └── shipping-rates-validation.js        # Registers validators with Magento
├── registration.php                   # Magento module registration
├── composer.json                      # Composer package definition
├── package.json                       # NPM config (version source of truth)
├── make-zip.sh                        # Build script: creates distribution zip
├── update-version.js                  # Syncs version to composer.json & module.xml
├── .distignore                        # Files excluded from distribution zip
├── .gitlab-ci.yml                     # CI/CD pipeline definition
└── CLAUDE.md                          # AI assistant guidance
```

---

## 4. Configuration Reference

All configuration lives under the `carriers/bobgo/` path in Magento's system config.

### Configuration Paths

| Path | Type | Default | Description |
|------|------|---------|-------------|
| `carriers/bobgo/active` | Yes/No | `0` | Enable Bob Go rates at checkout |
| `carriers/bobgo/environment` | Select | `sandbox` | API environment (sandbox/production) |
| `carriers/bobgo/api_key` | Encrypted | *(empty)* | Bob Go API key (Bearer token) |
| `carriers/bobgo/additional_info` | Yes/No | *(unset)* | Show delivery timeframe on rates |
| `carriers/bobgo/enable_order_push` | Yes/No | `0` | Push orders to Bob Go on save |
| `carriers/bobgo/enable_fulfillment_sync` | Yes/No | `0` | Sync fulfillments back from Bob Go |
| `carriers/bobgo/notify_customer_on_shipment` | Yes/No | `1` | Email customer when shipment is created |
| `carriers/bobgo/enable_track_order` | Yes/No | *(hidden)* | Enable customer tracking page |
| `carriers/bobgo/price` | Decimal | `0.00` | Default shipping price |
| `carriers/bobgo/model` | String | `BobGroup\BobGo\Model\Carrier\BobGo` | Carrier model class |
| `carriers/bobgo/title` | String | `Bob Go` | Carrier title shown to customers |
| `carriers/bobgo/name` | String | `Bob Go` | Carrier name |
| `general/store_information/suburb` | Text | *(empty)* | Store origin suburb (added by this module) |

### ApiConfig Class Constants

Defined in `Model/Config/ApiConfig.php`:

```php
const XML_PATH_API_KEY              = 'carriers/bobgo/api_key';
const XML_PATH_ENVIRONMENT          = 'carriers/bobgo/environment';
const XML_PATH_ENABLE_ORDER_PUSH    = 'carriers/bobgo/enable_order_push';
const XML_PATH_ENABLE_FULFILLMENT_SYNC = 'carriers/bobgo/enable_fulfillment_sync';
const XML_PATH_NOTIFY_CUSTOMER      = 'carriers/bobgo/notify_customer_on_shipment';
const XML_PATH_ACTIVE               = 'carriers/bobgo/active';

const BASE_URL_SANDBOX    = 'https://api.sandbox.bobgo.co.za/v2/';
const BASE_URL_PRODUCTION = 'https://api.bobgo.co.za/v2/';

const ENV_SANDBOX    = 'sandbox';
const ENV_PRODUCTION = 'production';
```

**API Key Decryption:** The `getApiKey()` method uses `EncryptorInterface::decrypt()` to decrypt the API key, since Magento's `scopeConfig->getValue()` returns the raw encrypted value for fields with the `Backend\Encrypted` backend model.

---

## 5. Architecture

### Layer Diagram

```
┌──────────────────────────────────────────────────────────────────┐
│                        Magento Frontend                          │
│  (Checkout, Customer Account, Tracking Page)                     │
├──────────────────────────────────────────────────────────────────┤
│                     JavaScript Layer                             │
│  LayoutProcessorPlugin → Suburb Field                            │
│  set-shipping-information-mixin → extension_attributes           │
│  shipping-rates-validator → Address Validation                   │
├──────────────────────────────────────────────────────────────────┤
│                     Observer / Plugin Layer                       │
│  OrderSaveObserver     → Triggers order push                     │
│  ConfigChangeObserver  → Tests connectivity on config save       │
│  ModifyShippingDescription → Cleans shipping label               │
│  AddWeightUnitToOrderPlugin → LBS→KG conversion                  │
│  OrderRepositoryPlugin → bobgo_order_id extension attribute      │
├──────────────────────────────────────────────────────────────────┤
│                      Service Layer                               │
│  OrderPushService            → POST/PATCH orders to Bob Go       │
│  OrderMapper                 → Order → API payload transformation│
│  FulfillmentService          → Bob Go fulfillment → Magento ship │
│  FulfillmentCronService      → Polling fallback for fulfillments │
│  WebhookSubscriptionService  → Subscribe/unsubscribe webhooks    │
├──────────────────────────────────────────────────────────────────┤
│                       Model Layer                                │
│  BobGo (Carrier)    → collectRates(), rate formatting            │
│  AdditionalInfo     → Request body parsing (suburb, company)     │
│  WebhookReceiver    → Incoming webhook routing                   │
│  ApiConfig          → Centralized configuration access           │
├──────────────────────────────────────────────────────────────────┤
│                        API Layer                                 │
│  BobGoApiClient     → HTTP client (GET/POST/PATCH/DELETE)        │
│  BobGoApiException  → Structured error with status + body        │
├──────────────────────────────────────────────────────────────────┤
│                     Bob Go REST API v2                           │
│  Sandbox:    https://api.sandbox.bobgo.co.za/v2/                 │
│  Production: https://api.bobgo.co.za/v2/                         │
└──────────────────────────────────────────────────────────────────┘
```

### Key Design Decisions

1. **Bearer Token Auth** - API key stored encrypted in Magento config, sent as `Authorization: Bearer {key}` header
2. **Dual Fulfillment Sync** - Webhooks for real-time + cron polling every 15 minutes as fallback
3. **Idempotent Fulfillments** - Duplicate detection via tracking number matching prevents duplicate shipments
4. **South Africa Only** - `processAdditionalValidation()` restricts rates to `ZA` country code
5. **Suburb as Extension Attribute** - Custom field on `Magento\Quote\Api\Data\AddressInterface` because Magento doesn't have a native suburb field

---

## 6. API Integration

### BobGoApiClient (`Api/BobGoApiClient.php`)

Central HTTP client for all Bob Go API communication. Uses Magento's `CurlFactory`.

**Methods:**

| Method | Signature | Description |
|--------|-----------|-------------|
| `get` | `get(string $endpoint, array $queryParams = []): array` | GET request with optional query parameters |
| `post` | `post(string $endpoint, array $payload): array` | POST request with JSON body |
| `patch` | `patch(string $endpoint, array $payload): array` | PATCH request with JSON body |
| `delete` | `delete(string $endpoint): array` | DELETE request |

**Request Configuration:**
- **Headers:** `Content-Type: application/json`, `Authorization: Bearer {API_KEY}`
- **Timeout:** 30 seconds (`CURLOPT_TIMEOUT`)
- **Base URL:** Determined by environment setting via `ApiConfig::getBaseUrl()`

**Error Handling:**
- HTTP status >= 400 throws `BobGoApiException` with status code, response body, and endpoint
- API key is masked in log output (shows only last 4 characters: `****xxxx`)
- Empty responses return `[]`
- Non-array JSON responses return `[]`

### BobGoApiException (`Api/BobGoApiException.php`)

Extends `Magento\Framework\Exception\LocalizedException`. Adds:

- `getStatusCode(): int` - HTTP status code
- `getResponseBody(): string` - Raw response body
- `getEndpoint(): string` - The API endpoint that failed

### API Endpoints Used

| Endpoint | Method | Purpose | Used By |
|----------|--------|---------|---------|
| `rates-at-checkout` | POST | Get shipping rates for cart | `BobGo::uRates()`, `ConfigChangeObserver::testRacConnectivity()` |
| `orders` | POST | Create new order in Bob Go | `OrderPushService::pushOrder()` |
| `orders` | PATCH | Update existing order in Bob Go | `OrderPushService::updateOrder()` |
| `order-fulfillments` | GET | Fetch fulfillments for an order | `FulfillmentCronService::syncFulfillmentsForOrder()` |
| `webhooks` | GET | List webhook subscriptions | `ConfigChangeObserver::testConnectivity()`, `WebhookSubscriptionService` |
| `webhooks` | POST | Create webhook subscriptions | `WebhookSubscriptionService::subscribe()` |
| `webhooks/{id}` | DELETE | Delete a webhook subscription | `WebhookSubscriptionService::unsubscribe()` |
| `tracking` | GET | Fetch tracking info by reference | `Controller\Tracking\Index::execute()` |

---

## 7. Core Carrier - Rate Calculation

### Class: `Model/Carrier/BobGo.php`

Extends `AbstractCarrierOnline`, implements `CarrierInterface`. This is the main shipping carrier registered with Magento.

### Rate Request Flow

```
Customer enters shipping address at checkout
         │
         ▼
Magento calls BobGo::collectRates(RateRequest $request)
         │
         ▼
isActive() check ── false ──▶ return false
         │ true
         ▼
Extract destination info from $request
  - postcode, country, region, city, street
  - company (from AdditionalInfo via request body JSON)
  - suburb (from AdditionalInfo via custom_attributes[0])
         │
         ▼
Extract origin info from store configuration
  - general/store_information/* (country, region, city, street, suburb, name)
  - general/locale/weight_unit (KGS or LBS)
         │
         ▼
Build items array via getStoreItems()
  - For each cart item: { description, quantity, price, weight_kg, length_cm, width_cm, height_cm }
         │
         ▼
Build payload:
  { collection_address: {...}, delivery_address: {...}, items: [...], declared_value: 0 }
         │
         ▼
POST to Bob Go API: rates-at-checkout
         │
         ▼
_formatRates() - Convert API response to Magento rate result methods
         │
         ▼
Return Result with available shipping methods
```

### Rate Request Payload Structure

```json
{
  "collection_address": {
    "company": "Store Name",
    "street_address": "123 Main Street",
    "local_area": "Gardens",
    "city": "Cape Town",
    "zone": "WC",
    "country": "ZA",
    "code": "8001"
  },
  "delivery_address": {
    "company": "Customer Company",
    "street_address": "456 Oak Avenue",
    "local_area": "Sandton",
    "city": "Johannesburg",
    "zone": "GP",
    "country": "ZA",
    "code": "2196"
  },
  "items": [
    {
      "description": "Product Name",
      "quantity": 2,
      "price": 199.99,
      "length_cm": 0,
      "width_cm": 0,
      "height_cm": 0,
      "weight_kg": 1.5
    }
  ],
  "declared_value": 0
}
```

> **Note:** Item weights are in **kg** (converted from grams internally). Dimensions default to 0 when not available from Magento.

### Rate Response Processing

The API returns a response like:

```json
{
  "rates": [
    {
      "id": 334,
      "service_code": "bobgo_334_1_1",
      "service_name": "Standard shipping",
      "total_price": 104,
      "description": "Default standard shipping",
      "currency": "ZAR",
      "min_delivery_date": "2026-03-02",
      "max_delivery_date": "2026-03-03",
      "base_rate": 103.5,
      "type": "door",
      "service_level_priority": 2,
      "provider_slug": "demo",
      "service_level_code": "ECO"
    }
  ],
  "count": 1
}
```

`_formatRates()` processes each rate:
1. Strips the `bobgo_` prefix from `service_code` (e.g., `bobgo_standard` → `standard`)
2. If `additional_info` config is enabled, calculates working days (excluding weekends) between today and delivery dates, sets carrier title to "Delivery in X - Y days"
3. Sets method title from `service_name`
4. Sets price and cost from `total_price`

### Validation: `processAdditionalValidation()`

Runs before rate collection:

1. **Empty cart** - Returns `false` if no items
2. **Max weight** - 500 kg per item (validates product weight, accounting for qty increments)
3. **Postcode required** - For countries that require zip codes
4. **South Africa only** - Only allows `ZA` as destination country; all other countries get an error

---

## 8. Order Push

When **Enable order push** is turned on, orders are automatically sent to Bob Go.

### Flow

```
Order saved in Magento (sales_order_save_after event)
         │
         ▼
OrderSaveObserver::execute()
         │
         ▼
Check: isOrderPushEnabled() && isConfigured()
         │ yes
         ▼
Check: order has bobgo_order_id?
         │
    ┌────┴────┐
    │ no      │ yes
    ▼         ▼
pushOrder()  updateOrder()
    │         │
    ▼         ▼
POST /v2/   PATCH /v2/
orders       orders
    │
    ▼
Store bobgo_order_id on order
(from response['id'])
```

### Order Payload Structure (`Service/OrderMapper.php`)

```json
{
  "ChannelRefID": "12345",
  "ChannelOrderNumber": "100000001",
  "TotalPrice": 599.99,
  "TotalTax": 78.26,
  "TotalDiscount": 50.00,
  "Currency": "ZAR",
  "Status": "Active",
  "PaymentStatus": "Paid",
  "BuyerSelectedShippingMethodCodes": ["bobgo_standard"],
  "BuyerSelectedShippingCost": 99.00,
  "BuyerSelectedShippingMethod": "Standard Delivery",
  "DatePlacedOnChannel": "2024-01-10 14:30:00",
  "LastModifiedOnChannel": "2024-01-10 14:30:00",
  "DeliveryAddress": {
    "StreetAddress": "456 Oak Avenue, Unit 2",
    "LocalArea": "Johannesburg",
    "City": "Johannesburg",
    "Code": "2196",
    "Zone": "Gauteng",
    "Country": "ZA",
    "Company": "Acme Corp"
  },
  "Items": [
    {
      "ChannelRefID": "67890",
      "SKU": "PROD-001",
      "Description": "Product Name",
      "UnitPrice": 199.99,
      "Qty": 2,
      "UnitWeightKg": 1.5
    }
  ]
}
```

### Status Mapping

| Magento Status | Bob Go Status |
|---------------|---------------|
| `canceled` | `Cancelled` |
| `complete` | `Completed` |
| `closed` | `Closed` |
| `holded` | `On Hold` |
| *(all others)* | `Active` |

### Payment Status Mapping

| Condition | Bob Go Payment Status |
|-----------|----------------------|
| `TotalDue <= 0` | `Paid` |
| `TotalDue == GrandTotal` (within 0.01) | `Unpaid` |
| *(otherwise)* | `Partially Paid` |

### Important Notes

- Child items (e.g., configurable product children) are **skipped** during item mapping (`getParentItemId()` check)
- The `ChannelRefID` for orders is the Magento `entity_id` (NOT `increment_id`)
- The `ChannelRefID` for items is the Magento `item_id`
- Update payloads include the `id` field (Bob Go order ID) in addition to all create fields
- Errors are logged but **never thrown** to the caller - order saving is not blocked by push failures

---

## 9. Fulfillment Sync

Fulfillment sync creates Magento shipments when orders are fulfilled in Bob Go. It works via two mechanisms:

### Mechanism 1: Webhooks (Real-time)

Bob Go sends a POST to `/rest/V1/bobgo/webhook` with the topic `fulfillment/created`. See [Webhook System](#10-webhook-system).

### Mechanism 2: Cron Polling (Fallback)

`FulfillmentCronService` runs every 15 minutes as a safety net.

```
Cron fires (*/15 * * * *)
         │
         ▼
Check: isFulfillmentSyncEnabled() && isConfigured()
         │ yes
         ▼
Query: orders WHERE state = 'processing' AND bobgo_order_id IS NOT NULL
         │
         ▼
For each order:
  GET /v2/order-fulfillments?order_id={bobgo_order_id}
         │
         ▼
For each fulfillment:
  FulfillmentService::processFulfillment($data)
```

### FulfillmentService::processFulfillment() (`Service/FulfillmentService.php`)

```
Receive fulfillment data
         │
         ▼
Extract: channel_ref_id, fulfillment_id, tracking_numbers, line_items
         │
         ▼
Validate: channel_ref_id present?
         │ yes
         ▼
Find order by entity_id (channel_ref_id)
         │
         ▼
Check: order.canShip()?
         │ yes
         ▼
Idempotency check: hasExistingFulfillment()?
  (Checks if any existing shipment tracking number matches incoming tracking numbers)
         │ no duplicate
         ▼
Build shipment items (partial fulfillment support):
  - If line_items is empty → full fulfillment (ship all)
  - If line_items provided → map channel_ref_id to order item_id and qty
         │
         ▼
Build tracking entries:
  - carrier_code: 'bobgo'
  - title: tracking.carrier or 'Bob Go'
  - track_number: tracking.number
         │
         ▼
ShipOrderInterface::execute()
  - Optionally notifies customer (based on config)
```

### Fulfillment Webhook Payload Structure

```json
{
  "channel_ref_id": "12345",
  "fulfillment_id": "ful_abc123",
  "tracking_numbers": [
    {
      "number": "TRACK123456",
      "carrier": "The Courier Guy"
    }
  ],
  "line_items": [
    {
      "channel_ref_id": "67890",
      "quantity": 1
    }
  ]
}
```

### FulfillmentService::processTrackingUpdate()

Handles `tracking/updated` webhook topic. Adds new tracking numbers to the **latest** shipment on the order. Prevents duplicate tracking numbers.

---

## 10. Webhook System

### Inbound Webhook Endpoint

**Route:** `POST /rest/V1/bobgo/webhook`
**Access:** Anonymous (no authentication required)
**Defined in:** `etc/webapi.xml`
**Handler:** `WebhookReceiverInterface::receive(string $topic, $data): string`

### Supported Topics

| Topic | Handler | Description |
|-------|---------|-------------|
| `fulfillment/created` | `FulfillmentService::processFulfillment()` | Creates a Magento shipment |
| `tracking/updated` | `FulfillmentService::processTrackingUpdate()` | Adds tracking to existing shipment |

### Webhook Subscription Management (`Service/WebhookSubscriptionService.php`)

Subscriptions are automatically managed when the **Fulfillment sync** toggle, **Environment**, or **API key** changes in admin config.

**Subscribe:**
```json
POST /v2/webhooks
{
  "webhook_subscriptions": [
    {
      "delivery_url": "https://store.example.com/rest/V1/bobgo/webhook",
      "topic": "fulfillment/created",
      "status": "active"
    },
    {
      "delivery_url": "https://store.example.com/rest/V1/bobgo/webhook",
      "topic": "tracking/updated",
      "status": "active"
    }
  ]
}
```

**Unsubscribe:**
1. `GET /v2/webhooks` - List current subscriptions
2. `DELETE /v2/webhooks/{id}` - Delete each subscription

The delivery URL is built from `StoreManagerInterface::getStore()->getBaseUrl()` + `/rest/V1/bobgo/webhook`.

---

## 11. Suburb Field (Custom Extension Attribute)

South African shipping requires a suburb for accuracy. Magento doesn't have a native suburb field, so this extension adds one.

### Components

| Component | File | Role |
|-----------|------|------|
| Extension attribute definition | `etc/extension_attributes.xml` | Declares `suburb` on `AddressInterface` |
| Checkout field injection | `Plugin/Checkout/Block/LayoutProcessorPlugin.php` | Adds suburb input to shipping form |
| Validation rules | `view/frontend/web/js/model/shipping-rates-validation-rules.js` | Makes suburb required |
| Shipping info mixin | `view/frontend/web/js/action/set-shipping-information-mixin.js` | Copies suburb to extension_attributes |
| Request body extraction | `Model/Carrier/AdditionalInfo.php` | Reads suburb from `custom_attributes[0].value` |
| Admin store config | `etc/adminhtml/system.xml` | Adds suburb field to Store Information |

### Checkout Field Configuration

Injected at `checkout > steps > shipping-step > shippingAddress > shipping-address-fieldset`:

```php
[
    'component'  => 'Magento_Ui/js/form/element/abstract',
    'dataScope'  => 'shippingAddress.custom_attributes.suburb',
    'label'      => 'Suburb',
    'sortOrder'  => 80,
    'validation' => ['required-entry' => true],
    'tooltip'    => ['description' => 'Required for shipping accuracy'],
]
```

### How Suburb Flows Through the System

1. Customer types suburb in checkout form
2. `set-shipping-information-mixin.js` copies it to `extension_attributes.suburb`
3. Magento sends rate estimation request with address data as JSON body
4. `AdditionalInfo::getSuburb()` parses the raw request body JSON: `address.custom_attributes[0].value`
5. `BobGo::collectRates()` calls `getDestSuburb()` and includes it in the API payload

---

## 12. Weight Handling

### Rate Requests (Cart Items)

Weights are converted to **kg** for the Bob Go API. `BobGo::getItemWeight()` first converts to grams internally, then `getStoreItems()` divides by 1000 for the `weight_kg` field:

| Store Weight Unit | Internal (grams) | API (`weight_kg`) | Example |
|-------------------|-------------------|-------------------|---------|
| `kgs` | `weight * 1000` | `grams / 1000` | 1.5 kg → 1500 g → 1.5 kg |
| `lbs` (or anything else) | `weight * 0.45359237 * 1000` | `grams / 1000` | 3.3 lbs → 1497 g → 1.5 kg |

The weight unit is read from `general/locale/weight_unit`.

`weight_kg` is rounded to 2 decimal places via `round($massGrams / 1000, 2)`.

### Order Items (Order Push)

The `AddWeightUnitToOrderPlugin` (beforeSave on `OrderRepositoryInterface`) converts all item weights from LBS to KG before saving:

```php
if ($weightUnit === 'lbs') {
    $convertedWeight = $weight * 0.45359237;
    $orderItem->setWeight($convertedWeight);
}
```

The `OrderMapper` then sends item weights as-is in the `UnitWeightKg` field.

### Maximum Weight

`processAdditionalValidation()` enforces a **500 kg** maximum per item. Items exceeding this weight will cause the carrier to return an error or `false`.

---

## 13. Tracking Page

> **Status: Currently hidden/disabled** - The `enable_track_order` field has `showInDefault="0"` in system.xml.

### Route

`/bobgo/tracking/index` (defined in `etc/frontend/routes.xml`)

### Controller: `Controller/Tracking/Index.php`

1. Checks if `carriers/bobgo/enable_track_order` is enabled
2. If disabled, redirects to 404 (`noroute`)
3. Gets `order_reference` from request parameters
4. Calls `GET /v2/tracking?tracking_reference={order_reference}`
5. Registers first result in Magento's registry as `shipment_data`
6. Renders page using `TrackingBlock` template

### Template: `view/frontend/templates/tracking/index.phtml`

Displays:
- Search form (when no data)
- Shipment reference, order number, courier name
- Tracking checkpoints table (date/time, status, message)
- Status-specific messages (pending collection, cancelled, etc.)
- Courier contact info footer with Bob Go branding

### Customer Account Link

`Block/TrackOrderLink.php` conditionally renders a "Track my order" link in the customer account sidebar (defined in `view/frontend/layout/customer_account.xml`). Hidden when `enable_track_order` is disabled.

---

## 14. Observers

### OrderSaveObserver

| | |
|---|---|
| **Event** | `sales_order_save_after` |
| **File** | `Observer/OrderSaveObserver.php` |
| **Scope** | Frontend (`etc/events.xml`) |

**Behavior:**
- Checks `isOrderPushEnabled()` and `isConfigured()`
- If order has no `bobgo_order_id` → calls `OrderPushService::pushOrder()`
- If order has `bobgo_order_id` → calls `OrderPushService::updateOrder()`
- All exceptions are caught and logged; order save is never blocked

### ModifyShippingDescription

| | |
|---|---|
| **Event** | `sales_order_place_before` |
| **File** | `Observer/ModifyShippingDescription.php` |
| **Scope** | Frontend (`etc/events.xml`) |

**Behavior:**
- Extracts the part after the last ` - ` in the shipping description
- Example: `"Bob Go - Delivery in 3 - 5 days - Standard Delivery"` → `"Standard Delivery"`
- If no ` - ` found, returns the full description unchanged

### ConfigChangeObserver

| | |
|---|---|
| **Event** | `admin_system_config_changed_section_carriers` |
| **File** | `Observer/ConfigChangeObserver.php` |
| **Scope** | Adminhtml (`etc/adminhtml/events.xml`) |

**Behavior by changed path:**

| Changed Config | Action |
|---------------|--------|
| API key or environment | `testConnectivity()` - GETs `/v2/webhooks` to verify credentials |
| Active toggle (enabled) | `testRacConnectivity()` - POSTs test payload to `/v2/rates-at-checkout` |
| Fulfillment sync, environment, or API key | `manageWebhookSubscriptions()` - Subscribe if enabled, unsubscribe if disabled |

**Important:** The observer calls `ReinitableConfigInterface::reinit()` at the start of `execute()` to ensure it reads freshly saved config values. Without this, Magento's in-memory ScopeConfig cache returns stale values during the save process.

Results are communicated to the admin via `$messageManager->addSuccessMessage()` / `addErrorMessage()`. Error messages include the actual API error detail for easier debugging.

---

## 15. Plugins

### AddWeightUnitToOrderPlugin

| | |
|---|---|
| **Target** | `Magento\Sales\Api\OrderRepositoryInterface::beforeSave` |
| **File** | `Plugin/AddWeightUnitToOrderPlugin.php` |

Converts all order item weights from LBS to KG (`* 0.45359237`) if the store weight unit is `lbs`.

### LayoutProcessorPlugin

| | |
|---|---|
| **Target** | `Magento\Checkout\Block\Checkout\LayoutProcessor::afterProcess` |
| **File** | `Plugin/Checkout/Block/LayoutProcessorPlugin.php` |
| **Scope** | Frontend (`etc/frontend/di.xml`) |

Injects the suburb field into the checkout shipping address form. Navigates the deep jsLayout component tree to find the `shipping-address-fieldset.children` node.

### OrderRepositoryPlugin

| | |
|---|---|
| **Target** | `Magento\Sales\Api\OrderRepositoryInterface` |
| **File** | `Plugin/OrderRepositoryPlugin.php` |
| **Methods** | `afterGet`, `afterGetList`, `beforeSave` |

Manages the `bobgo_order_id` extension attribute:
- **afterGet / afterGetList** - Loads `bobgo_order_id` from `order.getData()` into extension attributes
- **beforeSave** - Syncs `bobgo_order_id` from extension attributes back to `order.setData()`

---

## 16. Frontend JavaScript

### RequireJS Configuration (`view/frontend/requirejs-config.js`)

Registers a mixin on `Magento_Checkout/js/action/set-shipping-information`:

```javascript
'Magento_Checkout/js/action/set-shipping-information': {
    'BobGroup_BobGo/js/action/set-shipping-information-mixin': true
}
```

### set-shipping-information-mixin.js

Wraps the original `setShippingInformationAction` to initialize `extension_attributes` on the shipping address if undefined. This ensures the suburb custom attribute can be passed through Magento's shipping information save action.

### shipping-rates-validation-rules.js

Defines required fields for Bob Go rate validation:

```javascript
{
    'postcode':   { 'required': true },
    'country_id': { 'required': true },
    'city':       { 'required': true },
    'suburb':     { 'required': true }
}
```

### shipping-rates-validator.js

Validates the shipping address against the rules above before Magento attempts to fetch rates. Prevents unnecessary API calls with incomplete addresses.

### shipping-rates-validation.js

Registers the Bob Go validator and validation rules with Magento's checkout validation framework at `checkout > steps > shipping-step > step-config > shipping-rates-validation > bobgo-rates-validation`.

---

## 17. Database Schema

### Table: `sales_order`

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| `bobgo_order_id` | VARCHAR(255) | Yes | Bob Go's internal order ID, set after successful push |

Defined in `etc/db_schema.xml`:

```xml
<table name="sales_order" resource="sales" comment="Sales Order">
    <column xsi:type="varchar" name="bobgo_order_id" nullable="true"
            length="255" comment="Bob Go Order ID"/>
</table>
```

Whitelisted in `etc/db_schema_whitelist.json`:

```json
{
    "sales_order": {
        "column": {
            "bobgo_order_id": true
        }
    }
}
```

### Extension Attributes

Defined in `etc/extension_attributes.xml`:

| Interface | Attribute | Type | Purpose |
|-----------|-----------|------|---------|
| `Magento\Quote\Api\Data\AddressInterface` | `suburb` | string | Suburb for shipping rate accuracy |
| `Magento\Sales\Api\Data\OrderInterface` | `bobgo_order_id` | string | Bob Go order tracking ID |

---

## 18. Cron Jobs

### bobgo_fulfillment_sync

| | |
|---|---|
| **Schedule** | `*/15 * * * *` (every 15 minutes) |
| **Group** | `default` |
| **Class** | `BobGroup\BobGo\Service\FulfillmentCronService` |
| **Method** | `execute()` |
| **Config file** | `etc/crontab.xml` |

**Prerequisites:**
- `enable_fulfillment_sync` must be enabled
- `api_key` must be configured

**Logic:**
1. Finds all orders in `processing` state with a non-null `bobgo_order_id`
2. For each order, calls `GET /v2/order-fulfillments?order_id={bobgo_order_id}`
3. Processes each fulfillment through `FulfillmentService::processFulfillment()`
4. Sets `channel_ref_id` to the Magento order's `entity_id` if not present in the response

---

## 19. Dependency Injection

### `etc/di.xml` (Global)

**Interface Preferences:**

```xml
<preference for="BobGroup\BobGo\Api\OrderMapperInterface"
            type="BobGroup\BobGo\Service\OrderMapper" />
<preference for="BobGroup\BobGo\Api\WebhookReceiverInterface"
            type="BobGroup\BobGo\Model\WebhookReceiver" />
```

**Plugins on OrderRepositoryInterface:**

```xml
<type name="Magento\Sales\Api\OrderRepositoryInterface">
    <plugin name="add_weight_unit_to_order_item"
            type="BobGroup\BobGo\Plugin\AddWeightUnitToOrderPlugin" />
    <plugin name="bobgo_order_repository_plugin"
            type="BobGroup\BobGo\Plugin\OrderRepositoryPlugin" sortOrder="20" />
</type>
```

**BobGoApiClient Arguments:**

```xml
<type name="BobGroup\BobGo\Api\BobGoApiClient">
    <arguments>
        <argument name="curlFactory" xsi:type="object">Magento\Framework\HTTP\Client\CurlFactory</argument>
        <argument name="logger" xsi:type="object">Psr\Log\LoggerInterface</argument>
    </arguments>
</type>
```

**BobGo Carrier Arguments:**

> **Important:** The custom constructor params use unique names (e.g., `$httpRequest` not `$request`) to avoid Magento's parent class DI argument name collision. Magento inherits DI argument mappings by name from parent classes, and common names like `request` can cause type mismatches.

```xml
<type name="BobGroup\BobGo\Model\Carrier\BobGo">
    <arguments>
        <argument name="httpRequest" xsi:type="object">Magento\Framework\App\Request\Http</argument>
        <argument name="apiClient" xsi:type="object">BobGroup\BobGo\Api\BobGoApiClient</argument>
        <argument name="apiConfig" xsi:type="object">BobGroup\BobGo\Model\Config\ApiConfig</argument>
    </arguments>
</type>
```

### `etc/frontend/di.xml`

**Checkout LayoutProcessor Plugin:**

```xml
<type name="Magento\Checkout\Block\Checkout\LayoutProcessor">
    <plugin name="add_custom_field_checkout_shipping_form"
            type="BobGroup\BobGo\Plugin\Checkout\Block\LayoutProcessorPlugin" sortOrder="10"/>
</type>
```

---

## 20. Admin Configuration UI

### Location

**Stores > Configuration > Sales > Shipping Methods > Bob Go**

### Fields (in display order)

| # | Field ID | Label | Type | Notes |
|---|----------|-------|------|-------|
| 0 | `version` | Version | Label | Read-only, clickable link to bobgo.co.za |
| 1 | `environment` | Environment | Select | Sandbox / Production |
| 2 | `api_key` | API Key | Obscure | Encrypted storage via `Backend\Encrypted` |
| 3 | `active` | Enable Bob Go rates at checkout | Yes/No | Triggers RAC connectivity test |
| 4 | `additional_info` | Show additional rate information | Yes/No | Shows delivery timeframe |
| 5 | `enable_order_push` | Enable order push | Yes/No | |
| 6 | `enable_fulfillment_sync` | Enable fulfillment sync | Yes/No | Manages webhook subscriptions |
| 7 | `notify_customer_on_shipment` | Notify customer on shipment | Yes/No | |
| 10 | `enable_track_order` | Enable Track my order | Yes/No | **Hidden** (showInDefault=0) |

### Store Information Addition

The module adds a **Suburb** field to **Stores > Configuration > General > Store Information** (section `general`, group `store_information`, field `suburb`).

---

## 21. Data Flow Diagrams

### Complete Checkout Rate Flow

```
┌─────────────┐     ┌──────────────────────┐     ┌─────────────────┐
│   Customer   │────▶│  Magento Checkout     │────▶│ JS Validation   │
│   Browser    │     │  (shipping step)      │     │ (suburb, city,  │
└─────────────┘     └──────────────────────┘     │  postcode, ZA)  │
                                                   └────────┬────────┘
                                                            │ valid
                                                            ▼
┌─────────────┐     ┌──────────────────────┐     ┌─────────────────┐
│  Bob Go API │◀────│  BobGo::uRates()     │◀────│ collectRates()  │
│  /v2/rates  │     │  via BobGoApiClient   │     │ + validation    │
│  -at-       │     └──────────────────────┘     └─────────────────┘
│  checkout   │
└──────┬──────┘
       │ response
       ▼
┌──────────────────────┐     ┌──────────────────────┐
│  _formatRates()      │────▶│  Magento Rate Result  │────▶ Display to
│  - strip bobgo_      │     │  Method objects        │      customer
│  - calc working days │     └──────────────────────┘
│  - set prices        │
└──────────────────────┘
```

### Complete Order Lifecycle

```
1. ORDER PLACED
   Customer places order
        │
        ▼
   ModifyShippingDescription (sales_order_place_before)
   → Cleans "Bob Go - X - Y" to just "Y"
        │
        ▼
   Order saved (sales_order_save_after)
        │
        ▼
   OrderSaveObserver → OrderPushService::pushOrder()
   → POST /v2/orders → store bobgo_order_id

2. ORDER UPDATED
   Any order save → OrderSaveObserver
   → OrderPushService::updateOrder()
   → PATCH /v2/orders

3. FULFILLMENT
   Option A: Webhook POST /rest/V1/bobgo/webhook
   → WebhookReceiver → FulfillmentService::processFulfillment()
   → Creates Magento shipment

   Option B: Cron (every 15 min)
   → FulfillmentCronService → GET /v2/order-fulfillments
   → FulfillmentService::processFulfillment()

4. TRACKING UPDATE
   Webhook POST /rest/V1/bobgo/webhook (tracking/updated)
   → FulfillmentService::processTrackingUpdate()
   → Adds tracking numbers to latest shipment
```

---

## 22. Error Handling and Logging

### Logging Strategy

All components log to Magento's standard logger (`Psr\Log\LoggerInterface`), which writes to `var/log/system.log` by default.

| Component | Log Prefix | Level | Context |
|-----------|-----------|-------|---------|
| BobGoApiClient | `Bob Go API error` | ERROR | endpoint, status_code, response, masked api_key |
| OrderPushService | `Bob Go: Order pushed/failed` | INFO/ERROR | order_id, increment_id, bobgo_order_id |
| FulfillmentService | `Bob Go fulfillment:` | INFO/ERROR | order_id, fulfillment_id, error |
| FulfillmentCronService | `Bob Go fulfillment cron:` | INFO/ERROR/WARNING | order_count, order_id, bobgo_order_id |
| ConfigChangeObserver | `Bob Go connectivity/RAC/webhook` | ERROR | error message |
| WebhookReceiver | `Bob Go webhook` | INFO/ERROR/WARNING | topic, error |
| OrderSaveObserver | `Bob Go: OrderSaveObserver` | ERROR | error message |
| Helper\Data | *(custom)* | DEBUG | Only when debug mode enabled |

### Error Recovery Patterns

1. **Non-blocking observers** - `OrderSaveObserver` wraps everything in try/catch. A Bob Go API failure will never prevent an order from being saved.
2. **Idempotent fulfillments** - `hasExistingFulfillment()` checks tracking numbers to prevent duplicate shipments if the same webhook fires twice or cron processes an already-handled fulfillment.
3. **Graceful API failures** - `BobGo::uRates()` returns `null` on API error; `_getRates()` logs and returns empty result. The customer sees no rates rather than an error page.
4. **Webhook resilience** - Unknown topics are logged as warnings and return `'unknown topic'`. Processing failures return `'error processing webhook'` but don't throw.

---

## 23. Security

### API Key Storage

- Stored encrypted using `Magento\Config\Model\Config\Backend\Encrypted`
- Config path: `carriers/bobgo/api_key`
- The admin UI field type is `obscure` (masked in form)
- In logs, the key is masked to show only the last 4 characters: `****xxxx`

### API Authentication

All Bob Go API requests use Bearer token authentication:

```
Authorization: Bearer {decrypted_api_key}
Content-Type: application/json
```

### Webhook Endpoint Security

The webhook endpoint at `/rest/V1/bobgo/webhook` is configured with **anonymous access** (`<resource ref="anonymous"/>` in `webapi.xml`). This means:

- No Magento authentication is required to call this endpoint
- The `WebhookValidator` only checks for `topic` and `data` keys in the payload
- There is **no HMAC signature verification** on incoming webhooks in the current implementation

> **Note for maintainers:** Consider adding webhook signature verification if Bob Go supports it, to prevent unauthorized webhook calls.

### Input Validation

- Weight validation: Max 500 kg per item
- Country validation: Only ZA (South Africa) accepted
- Fulfillment idempotency: Duplicate tracking numbers are rejected
- Order existence: Fulfillments are rejected if the referenced order doesn't exist
- Ship eligibility: `canShip()` check before creating shipments

---

## 24. Testing

### Test Framework

- **PHPUnit 9.5** with Magento framework mocks
- Tests do **not** require a running Magento instance
- Located in `Test/Unit/`

### Running Tests

```bash
# Run all unit tests
vendor/bin/phpunit --prepend Test/stubs/autoload-prepend.php \
                   --bootstrap Test/bootstrap.php \
                   Test/Unit/

# Or via npm
npm test

# Run a single test file
vendor/bin/phpunit --prepend Test/stubs/autoload-prepend.php \
                   --bootstrap Test/bootstrap.php \
                   Test/Unit/Model/Carrier/BobGoTest.php

# Run a specific test method
vendor/bin/phpunit --prepend Test/stubs/autoload-prepend.php \
                   --bootstrap Test/bootstrap.php \
                   --filter testCollectRates \
                   Test/Unit/Model/Carrier/BobGoTest.php
```

### Test Files

| Test File | Tests |
|-----------|-------|
| `Api/BobGoApiClientTest.php` | HTTP client: GET/POST/PATCH/DELETE, error handling, auth headers |
| `Block/System/Config/Form/Field/VersionTest.php` | Version display block |
| `Helper/DataTest.php` | Helper functions, debug logging |
| `Model/Carrier/AdditionalInfoTest.php` | Request body parsing (suburb, company, phone) |
| `Model/Carrier/BobGoTest.php` | Rate collection, validation, weight conversion, formatting |
| `Model/Config/ApiConfigTest.php` | Configuration getters, environment URLs, feature flags |
| `Model/Source/FreemethodTest.php` | Free method source model |
| `Model/Source/GenericTest.php` | Generic source model base |
| `Model/WebhookReceiverTest.php` | Webhook routing, topic handling |
| `Observer/ConfigChangeObserverTest.php` | Config change reactions, connectivity tests |
| `Observer/OrderSaveObserverTest.php` | Order push/update triggering |
| `Plugin/AddWeightUnitToOrderPluginTest.php` | LBS to KG conversion |
| `Service/FulfillmentCronServiceTest.php` | Cron polling logic |
| `Service/FulfillmentServiceTest.php` | Shipment creation, tracking updates, idempotency |
| `Service/OrderMapperTest.php` | Order-to-payload mapping, status mapping |
| `Service/OrderPushServiceTest.php` | Order POST/PATCH, bobgo_order_id storage |
| `Service/WebhookSubscriptionServiceTest.php` | Subscribe/unsubscribe webhook management |

### Test Bootstrap

- `Test/bootstrap.php` - Sets up autoloading and Magento framework stubs
- `Test/stubs/autoload-prepend.php` - Prepended autoloader for Magento class stubs
- `Test/stubs/` - Contains stub classes for Magento framework dependencies

---

## 25. Build and CI/CD

### Build Script: `make-zip.sh`

Creates a distribution zip (`bobgo-magento-plugin.zip`) for deployment.

**Steps:**
1. Installs `jq` if missing (macOS via Homebrew, Linux via apt-get)
2. Verifies Perl is installed
3. Extracts version from `package.json`
4. Runs `npm install`
5. Updates version in `composer.json` (Perl regex replacement)
6. Updates `setup_version` in `etc/module.xml` (Perl regex replacement)
7. Creates zip using rsync (excludes files listed in `.distignore`) then zip

**Excluded from distribution** (`.distignore`):
```
*.pdf, node_modules, .git, package.json, .husky, package-lock.json,
.distignore, make-zip.sh, update-version.js, .gitlab-ci.yml,
.gitignore, .gitattributes, package, scripts/
```

### GitLab CI/CD (`.gitlab-ci.yml`)

**Image:** `shiplogic/ci-wp-plugin:node18`

#### Stage: `deploy` (dev branch)

Triggered on push to `dev` branch:

```bash
./make-zip.sh
aws s3 cp bobgo-magento-plugin.zip s3://magento-plugin.dev.bobgo.co.za/ --region=af-south-1
```

#### Stage: `tag_deploy` (production)

Triggered on git tag creation:

1. Verifies the tag was created from the `prod` branch
2. Downloads the tagged archive from GitLab
3. Uploads to S3:
   - `s3://magento-plugin.bobgo.co.za/tags/bobgo-magento-extension-{tag}.zip`
   - `s3://magento-plugin.bobgo.co.za/latest/latest.zip`

### S3 Buckets

| Bucket | Purpose |
|--------|---------|
| `magento-plugin.dev.bobgo.co.za` | Development builds from `dev` branch |
| `magento-plugin.bobgo.co.za` | Production releases (tagged from `prod`) |

---

## 26. Version Management

### Source of Truth

`package.json` version field (currently `1.0.62`).

### Version Sync

The version is synced to two other locations:

1. **`composer.json`** - `"version"` field
2. **`etc/module.xml`** - `setup_version` attribute

Sync is handled by `update-version.js` (run via `npm run update-version-files`).

### Auto-Increment

The Husky pre-commit hook (`.husky/pre-commit`) automatically:

1. Runs `npm version patch` (increments patch version in `package.json`)
2. Runs `npm run update-version-files` (syncs to `composer.json` and `module.xml`)
3. Stages the changed files

This means **every commit automatically bumps the patch version**.

---

## 27. Known Limitations

1. **South Africa Only** - The `processAdditionalValidation()` method rejects all non-ZA countries. To support other countries, this validation must be modified.

2. **Tracking Page Hidden** - The `enable_track_order` setting is hidden in admin (`showInDefault="0"`). To enable it, update `etc/adminhtml/system.xml` to set `showInDefault="1"`.

3. **No Webhook Signature Verification** - Incoming webhooks at `/rest/V1/bobgo/webhook` accept anonymous requests with no HMAC verification. The `WebhookValidator` only checks for `topic` and `data` keys.

4. **Suburb Extraction Fragility** - `AdditionalInfo::getSuburb()` reads from `custom_attributes[0].value` (hardcoded index 0). If other custom attributes are added before suburb, this will break.

5. **Single Carrier Instance** - The extension only supports one Bob Go carrier configuration per store. Multi-store setups share the same carrier code `bobgo`.

6. **Weight Conversion on Every Save** - `AddWeightUnitToOrderPlugin` converts LBS to KG on every `OrderRepository::save()` call, which could compound if an order is saved multiple times.

7. **ModifyShippingDescription Regex** - Uses `strrpos(' - ')` which may incorrectly parse shipping descriptions that contain ` - ` in the service name itself.

8. **AdditionalInfo Direct Instantiation** - `AdditionalInfo` is created via `new AdditionalInfo()` in the `BobGo` constructor rather than through DI, making it harder to mock in tests.

9. **Registry Deprecation** - `TrackingBlock` and `Controller\Tracking\Index` use `Magento\Framework\Registry`, which is deprecated since Magento 2.3. Should migrate to view models or request parameters.

10. **No Rate Caching** - Every checkout address change triggers a new API call. No caching of rate responses.

---

## 28. Troubleshooting

### Rates Not Showing at Checkout

1. **Check carrier is active:** Verify `carriers/bobgo/active` = `1` in config
2. **Check API key:** Verify API key is configured and valid
3. **Check environment:** Ensure correct environment (sandbox vs production)
4. **Check country:** Only South Africa (ZA) is supported
5. **Check logs:** Look in `var/log/system.log` for `Bob Go` entries
6. **Check suburb:** The suburb field must be filled in at checkout
7. **Check store origin:** Verify Store Information has suburb, city, country set
8. **Test connectivity:** Save the carrier config - the ConfigChangeObserver will test the connection and show a success/error message

### Orders Not Pushing to Bob Go

1. **Check `enable_order_push`** is enabled
2. **Check API key** is configured
3. **Check logs** for `Bob Go: Failed to push order`
4. **Verify** the order has no `bobgo_order_id` yet (check `sales_order` table)

### Fulfillments Not Syncing

1. **Check `enable_fulfillment_sync`** is enabled
2. **Verify webhooks:** Save config and check for "webhook subscriptions activated" message
3. **Check webhook URL:** Ensure your store's base URL is publicly accessible (Bob Go needs to POST to it)
4. **Check cron:** Verify Magento cron is running (`crontab -l` should show Magento cron entries)
5. **Check order state:** Only `processing` orders with `bobgo_order_id` are polled by cron
6. **Check logs** for `Bob Go fulfillment:` and `Bob Go fulfillment cron:` entries

### Duplicate Shipments

The extension has idempotency checks (tracking number matching). If duplicates still occur:
1. Check if the same fulfillment is being sent via both webhook AND cron
2. Check if fulfillments have different tracking numbers for the same logical shipment

### Weight Issues

1. **Verify store weight unit:** Check `general/locale/weight_unit` (should be `kgs` or `lbs`)
2. **Check product weights:** Ensure products have weights set in the catalog
3. **Note:** Weights are sent in grams to the API. A 1.5 kg item = 1500 grams.

---

## 29. File Reference

### PHP Classes - Quick Reference

| Class | Purpose |
|-------|---------|
| `Api\BobGoApiClient` | HTTP client for all Bob Go API calls |
| `Api\BobGoApiException` | API error with status code, body, endpoint |
| `Api\OrderMapperInterface` | Interface for order→payload mapping |
| `Api\WebhookReceiverInterface` | Interface for webhook reception |
| `Block\System\Config\Form\Field\Version` | Version display in admin |
| `Block\TrackingBlock` | Template block for tracking page |
| `Block\TrackOrderLink` | Conditional tracking link |
| `Controller\Tracking\Index` | Tracking page controller |
| `Helper\Data` | Module helper (version, debug log) |
| `Model\Carrier\AdditionalInfo` | Extracts suburb/company from request body |
| `Model\Carrier\BobGo` | Main carrier - rate calculation |
| `Model\Config\ApiConfig` | Centralized API config access |
| `Model\Source\Environment` | Sandbox/Production dropdown |
| `Model\WebhookReceiver` | Routes webhooks to handlers |
| `Model\WebhookValidator` | Validates webhook structure |
| `Observer\ConfigChangeObserver` | Admin config change handler |
| `Observer\ModifyShippingDescription` | Cleans shipping description |
| `Observer\OrderSaveObserver` | Triggers order push on save |
| `Plugin\AddWeightUnitToOrderPlugin` | LBS→KG weight conversion |
| `Plugin\Checkout\Block\LayoutProcessorPlugin` | Adds suburb to checkout |
| `Plugin\OrderRepositoryPlugin` | Manages bobgo_order_id ext attr |
| `Service\FulfillmentCronService` | Cron: polls for fulfillments |
| `Service\FulfillmentService` | Creates shipments from fulfillments |
| `Service\OrderMapper` | Order→API payload transformation |
| `Service\OrderPushService` | POST/PATCH orders to Bob Go |
| `Service\WebhookSubscriptionService` | Manages webhook subscriptions |

### XML Configuration Files - Quick Reference

| File | Purpose |
|------|---------|
| `etc/module.xml` | Module definition, version, dependencies |
| `etc/config.xml` | Default config values |
| `etc/di.xml` | DI preferences, plugins, arguments |
| `etc/events.xml` | Frontend event observers |
| `etc/adminhtml/events.xml` | Admin event observers |
| `etc/adminhtml/system.xml` | Admin configuration UI |
| `etc/extension_attributes.xml` | suburb + bobgo_order_id attributes |
| `etc/db_schema.xml` | Database column: bobgo_order_id |
| `etc/crontab.xml` | Fulfillment sync cron job |
| `etc/webapi.xml` | REST webhook endpoint |
| `etc/acl.xml` | Access control list |
| `etc/frontend/di.xml` | Frontend checkout plugin |
| `etc/frontend/routes.xml` | Frontend /bobgo route |

---

*Last updated: 2026-02-23*
*Extension version: 1.0.62*
