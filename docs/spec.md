# Bob Go Magento 2 Extension - Developer Specification

> **Module:** `BobGroup_BobGo`
> **Namespace:** `BobGroup\BobGo`
> **PHP Compatibility:** ^7.4 || ^8.0 || ^8.2
> **Current Version:** 1.1.0 (source of truth: `composer.json`)
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
    - 10a. [Reconciliation](#10a-reconciliation)
    - 10b. [Admin Order Panel](#10b-admin-order-panel)
    - 10c. [Sync Log](#10c-sync-log)
11. [Suburb Field (Custom Extension Attribute)](#11-suburb-field-custom-extension-attribute)
12. [Weight Handling](#12-weight-handling)
13. [Tracking Page](#13-tracking-page)
14. [Observers](#14-observers)
15. [Plugins](#15-plugins)
16. [Frontend JavaScript](#16-frontend-javascript)
17. [Database Schema](#17-database-schema)
18. [Dependency Injection](#18-dependency-injection)
19. [Admin Configuration UI](#19-admin-configuration-ui)
20. [Data Flow Diagrams](#20-data-flow-diagrams)
21. [Error Handling and Logging](#21-error-handling-and-logging)
22. [Security](#22-security)
23. [Testing](#23-testing)
24. [Build and CI/CD](#24-build-and-cicd)
25. [Version Management](#25-version-management)
26. [Known Limitations](#26-known-limitations)
27. [Troubleshooting](#27-troubleshooting)
28. [File Reference](#28-file-reference)

---

## 1. Overview

The Bob Go Shipping Extension integrates Magento 2 stores with the [Bob Go](https://www.bobgo.co.za) shipping platform for South African e-commerce. It provides:

- **Rates at Checkout** - Real-time shipping rate calculation from the Bob Go API displayed during checkout
- **Order Push** - Automatic synchronization of Magento orders to Bob Go for fulfillment, with sync-hash dirty checking
- **Fulfillment Sync** - Automatic creation of Magento shipments when orders are fulfilled in Bob Go via signed webhooks
- **HMAC Webhook Verification** - All inbound webhooks must carry a valid `Bobgo-Webhook-Signature` HMAC-SHA256 of the raw body
- **Reconciliation Cron** - Hourly safety-net job that re-fetches authoritative fulfilment state for active orders (catches lost webhooks)
- **Sync Log** - Dedicated `bobgo_sync_log` table records every inbound/outbound API event for audit and debugging
- **Tracking Updates** - Shipment tracking number synchronization from Bob Go back to Magento
- **Suburb Field** - Custom checkout field required for South African shipping address accuracy
- **Admin Order Panel** - Bob Go sync status, last-synced/last-webhook timestamps, shipments, and a Resync button on the order detail page
- **Order Tracking Page** - Customer-facing tracking page (currently hidden/disabled)

### Key Concepts

| Concept | Description |
|---------|-------------|
| **Carrier Code** | `bobgo` - used throughout Magento's shipping framework |
| **Bob Go Order ID** | Numeric Bob Go id stored on `sales_order.bobgo_order_id` after an order is pushed |
| **Bob Go Order Ref** | Immutable string reference (when Bob Go returns one) stored on `sales_order.bobgo_order_ref` |
| **`channel_ref_id`** | Magento `entity_id`, sent as the immutable idempotency key on order create |
| **`channel_order_number`** | Magento `increment_id`, the human-readable order number, sent alongside `channel_ref_id` |
| **Channel Identifier** | The canonical store base URL, sent on every outbound call via the `bobgo-channel-identifier` header |
| **Webhook Secret** | Merchant-issued HMAC signing key; encrypted at rest under `carriers/bobgo/webhook_secret` |
| **Sync Hash** | MD5 of the canonicalised order payload; PATCH calls are skipped when the hash hasn't changed |
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
│   ├── BobGoApiClient.php          # HTTP client for Bob Go API (Bearer auth + channel id header)
│   ├── BobGoApiException.php       # Custom exception with status code & response body
│   └── OrderMapperInterface.php    # Interface: order-to-API-payload mapping
├── Block/
│   ├── Adminhtml/Order/View/
│   │   └── BobGoInfo.php           # Admin order detail panel block (sync state + shipments)
│   ├── System/Config/Form/Field/
│   │   └── Version.php             # Displays version as clickable link in admin config
│   ├── TrackingBlock.php           # Template block for tracking page
│   └── TrackOrderLink.php          # Conditional "Track my order" link in customer account
├── Controller/
│   ├── Adminhtml/Order/
│   │   └── Resync.php              # Admin Resync action (re-push + reconcile a single order)
│   ├── Tracking/
│   │   └── Index.php               # Frontend tracking page controller
│   └── Webhook/
│       └── Receive.php             # Unified webhook controller (HMAC verify, event_id dedup, route)
├── Cron/
│   └── Reconcile.php               # Hourly cron entry — thin wrapper over ReconciliationService
├── Helper/
│   └── Data.php                    # Module helper (version, debug logging)
├── Model/
│   ├── Carrier/
│   │   ├── AdditionalInfo.php      # Extracts suburb/company/phone from checkout request body
│   │   └── BobGo.php              # Main carrier class (~1115 lines) - rate calculation
│   ├── Config/
│   │   └── ApiConfig.php           # Centralized API config (keys, URLs, feature flags, webhook secret)
│   ├── ResourceModel/
│   │   ├── SyncLog.php             # Resource model for bobgo_sync_log table
│   │   └── SyncLog/
│   │       └── Collection.php      # Collection class for sync log queries
│   ├── Source/
│   │   ├── Dropoff.php             # Config source: dropoff options
│   │   ├── Environment.php         # Config source: sandbox/production select
│   │   ├── Freemethod.php          # Config source: free method options
│   │   ├── Generic.php             # Base config source class
│   │   ├── Method.php              # Config source: shipping method options
│   │   ├── Packaging.php           # Config source: packaging options
│   │   └── Unitofmeasure.php       # Config source: weight unit options
│   └── SyncLog.php                 # Model for a single bobgo_sync_log row + event/direction constants
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
│   ├── FulfillmentService.php         # Creates Magento shipments from Bob Go fulfillments
│   ├── OrderMapper.php                # Maps Magento orders to Bob Go API payload format
│   ├── OrderPushService.php           # POST/PATCH orders to Bob Go (with sync-hash dirty check)
│   ├── ReconciliationService.php      # Hourly safety net: re-fetch authoritative shipments
│   ├── SyncLogger.php                 # Single writer for bobgo_sync_log + event_id dedup helper
│   ├── WebhookSignatureVerifier.php   # HMAC-SHA256 verification of inbound webhook bodies
│   └── WebhookSubscriptionService.php # Manages webhook subscriptions with Bob Go
├── Test/
│   └── Unit/                          # PHPUnit test suite (see Testing section)
├── etc/
│   ├── acl.xml                        # Access control (minimal - inherits admin)
│   ├── adminhtml/
│   │   ├── events.xml                 # Admin event: config change observer
│   │   ├── routes.xml                 # Admin route /bobgo/* (Resync controller)
│   │   └── system.xml                 # Admin configuration UI fields
│   ├── config.xml                     # Default configuration values
│   ├── crontab.xml                    # Cron schedule: hourly reconciliation
│   ├── db_schema.xml                  # Sales order columns + bobgo_sync_log table
│   ├── db_schema_whitelist.json       # Schema whitelist for declarative schema
│   ├── di.xml                         # Dependency injection: preferences, plugins, arguments
│   ├── events.xml                     # Frontend events: order save, order place
│   ├── extension_attributes.xml       # Extension attributes: suburb, bobgo_order_id
│   ├── frontend/
│   │   ├── di.xml                     # Frontend DI: checkout layout processor plugin
│   │   └── routes.xml                 # Frontend route /bobgo/* (webhook + tracking)
│   ├── module.xml                     # Module definition and dependencies
│   └── webapi.xml                     # Reserved (empty; webhook moved to standard frontend route)
├── view/
│   ├── adminhtml/
│   │   ├── layout/
│   │   │   └── sales_order_view.xml          # Inject Bob Go panel into admin order detail
│   │   └── templates/
│   │       └── order/view/
│   │           └── bobgo_info.phtml          # Bob Go admin panel template
│   └── frontend/
│       ├── layout/
│       │   ├── bobgo_tracking_index.xml      # Tracking page layout
│       │   ├── checkout_cart_index.xml       # Cart page layout
│       │   ├── checkout_index_index.xml      # Checkout: registers rate validators
│       │   └── customer_account.xml          # Customer account: "Track my order" link
│       ├── requirejs-config.js               # RequireJS mixin for shipping info
│       ├── templates/
│       │   └── tracking/
│       │       └── index.phtml               # Tracking page template
│       └── web/js/
│           ├── action/
│           │   └── set-shipping-information-mixin.js  # Suburb → extension_attributes
│           ├── model/
│           │   ├── set-shipping-information.js         # (unused, superseded by mixin)
│           │   ├── shipping-rates-validation-rules.js  # Required fields for rate validation
│           │   └── shipping-rates-validator.js          # Validates address before rate fetch
│           └── view/
│               └── shipping-rates-validation.js        # Registers validators with Magento
├── registration.php                   # Magento module registration (class_exists-guarded)
├── composer.json                      # Composer package definition
├── phpstan.neon                       # PHPStan level 2 config (`composer stan`)
├── phpunit.xml.dist                   # PHPUnit config
├── bump-version.sh                    # Version bump script (patch|minor|major|x.y.z)
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
| `carriers/bobgo/webhook_secret` | Encrypted | *(empty)* | Merchant-issued HMAC secret for verifying inbound webhooks |
| `carriers/bobgo/additional_info` | Yes/No | *(unset)* | Show delivery timeframe on rates |
| `carriers/bobgo/enable_order_push` | Yes/No | `0` | Push orders to Bob Go on save |
| `carriers/bobgo/enable_fulfillment_sync` | Yes/No | `0` | Sync fulfillments back from Bob Go (also drives hourly reconciliation) |
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
const XML_PATH_WEBHOOK_SECRET       = 'carriers/bobgo/webhook_secret';
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

**Encrypted-field decryption:** Both `getApiKey()` and `getWebhookSecret()` use `EncryptorInterface::decrypt()`, since Magento's `scopeConfig->getValue()` returns the raw encrypted value for fields backed by `Backend\Encrypted`.

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
│                     Observer / Plugin Layer                      │
│  OrderSaveObserver     → Triggers order push                     │
│  ConfigChangeObserver  → Tests connectivity on config save       │
│  ModifyShippingDescription → Cleans shipping label               │
│  AddWeightUnitToOrderPlugin → LBS→KG conversion                  │
│  OrderRepositoryPlugin → bobgo_order_id extension attribute      │
├──────────────────────────────────────────────────────────────────┤
│              Inbound (Webhook) Surface                           │
│  Controller\Webhook\Receive  → HMAC verify → dedup → route       │
│  WebhookSignatureVerifier    → HMAC-SHA256 base64 (constant-time)│
│  SyncLogger                  → bobgo_sync_log writer + dedup     │
│  FulfillmentService          → Webhook payload → Magento ship    │
├──────────────────────────────────────────────────────────────────┤
│              Outbound + Reconciliation Surface                   │
│  OrderPushService            → POST/PATCH orders (sync-hash gated)│
│  OrderMapper                 → Order → API payload transformation│
│  ReconciliationService       → Hourly safety net: re-fetch state │
│  Cron\Reconcile              → Cron entry point                  │
│  WebhookSubscriptionService  → Subscribe/unsubscribe webhooks    │
├──────────────────────────────────────────────────────────────────┤
│              Admin Surface                                       │
│  Block\Adminhtml\Order\View\BobGoInfo → Order panel block        │
│  Controller\Adminhtml\Order\Resync    → Manual resync action     │
├──────────────────────────────────────────────────────────────────┤
│                       Model Layer                                │
│  BobGo (Carrier)    → collectRates(), rate formatting            │
│  AdditionalInfo     → Request body parsing (suburb, company)     │
│  SyncLog + ResourceModel → bobgo_sync_log persistence            │
│  ApiConfig          → Centralized configuration access           │
├──────────────────────────────────────────────────────────────────┤
│                        API Layer                                 │
│  BobGoApiClient     → HTTP client (Bearer + channel-identifier)  │
│  BobGoApiException  → Structured error with status + body        │
├──────────────────────────────────────────────────────────────────┤
│                     Bob Go REST API v2                           │
│  Sandbox:    https://api.sandbox.bobgo.co.za/v2/                 │
│  Production: https://api.bobgo.co.za/v2/                         │
└──────────────────────────────────────────────────────────────────┘
```

### Key Design Decisions

1. **Bearer Token Auth + Channel Identifier** - API key stored encrypted, sent as `Authorization: Bearer {key}`. Every outbound call also carries `bobgo-channel-identifier: {store base URL}` so Bob Go can associate the call with the right channel.
2. **HMAC-Verified Webhooks** - Every inbound webhook body is verified against `Bobgo-Webhook-Signature` (HMAC-SHA256 base64, constant-time compare) using a merchant-issued secret. Verification is the first gate; the body is never inspected before it passes.
3. **Event-ID Webhook Dedup** - Bob Go retries failed deliveries; `SyncLogger::wasEventIdProcessed()` short-circuits duplicates to a 200 so they don't re-process.
4. **Sync-Hash Dirty Checking** - `OrderPushService::updateOrder()` skips PATCH calls entirely when the canonicalised payload hash matches the last successful sync.
5. **Reconciliation as Safety Net** - Webhooks remain the primary fulfillment signal. An hourly cron (`Cron\Reconcile`) refetches `GET /v2/order-fulfillments?order_id=...` for active orders and full-replaces `sales_order.bobgo_shipments`, closing the gap when a webhook is lost or delayed.
6. **Sync Log as Single Source of Truth** - Every inbound and outbound API event flows through `SyncLogger`, recording event type, direction, payload, HTTP status, success flag, and `event_id` for correlation.
7. **Idempotent Fulfillments** - Webhook dedup via `event_id`; shipment dedup via tracking number matching in `FulfillmentService::hasExistingTrackingNumber()`.
8. **South Africa Only** - `processAdditionalValidation()` restricts rates to `ZA` country code.
9. **Suburb as Extension Attribute** - Custom field on `Magento\Quote\Api\Data\AddressInterface` because Magento doesn't have a native suburb field.

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
- **Headers (every request):**
  - `Content-Type: application/json`
  - `Accept: application/json`
  - `Authorization: Bearer {API_KEY}`
  - `bobgo-channel-identifier: {canonical store base URL}` — derived from `StoreManagerInterface::getStore()->getBaseUrl()`, trailing slash trimmed
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
| `orders` | PATCH | Update existing order in Bob Go | `OrderPushService::updateOrder()` (skipped when sync-hash matches) |
| `order-fulfillments` | GET | Authoritative fulfilment state for an order (`?order_id={id}`) | `ReconciliationService::reconcileOrder()` |
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
OrderSaveObserver::execute()  ── re-entrancy guard prevents nested re-firing
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
    │             │
    │             ▼
    │       Compute MD5(canonicalised payload)
    │             │
    │             ▼
    │       hash == order.bobgo_sync_hash?  ── yes ──▶  no-op, no API call, no log entry
    │             │ no
    │             ▼
    │       PATCH /v2/orders
    ▼
POST /v2/orders
    │
    ▼
On success:
  - Store response['id'] → bobgo_order_id
  - Store response['order_ref'] or ['reference'] → bobgo_order_ref (if present)
  - Store hash → bobgo_sync_hash
  - Set bobgo_sync_status = 'success'
  - Set bobgo_last_synced = now (UTC)
  - Log to bobgo_sync_log via SyncLogger (order_created / order_updated_outbound)

On failure:
  - Set bobgo_sync_status = 'failed'
  - Log error + payload to bobgo_sync_log with success=0 + HTTP status (if BobGoApiException)
  - Swallow the exception so order save is never blocked
```

### Order Payload Structure (`Service/OrderMapper.php`)

The mapper emits snake_case fields. `channel_ref_id` is the immutable Magento `entity_id` — Bob Go's idempotency key. `channel_order_number` is the human-readable `increment_id`.

```json
{
  "channel_ref_id": "12345",
  "channel_order_number": "100000001",
  "customer_name": "Acme",
  "customer_surname": "Corp",
  "customer_email": "buyer@example.com",
  "customer_phone": "+27 21 555 0100",
  "currency": "ZAR",
  "buyer_selected_service_code": "bobgo_standard",
  "buyer_selected_shipping_cost": 99.00,
  "buyer_selected_shipping_method": "Standard Delivery",
  "payment_status": "paid",
  "delivery_address": {
    "company": "Acme Corp",
    "street_address": "456 Oak Avenue, Unit 2",
    "local_area": "Johannesburg",
    "city": "Johannesburg",
    "zone": "Gauteng",
    "country": "ZA",
    "code": "2196"
  },
  "order_items": [
    {
      "channel_ref_id": "67890",
      "description": "Product Name",
      "sku": "PROD-001",
      "unit_price": 199.99,
      "qty": 2,
      "unit_weight_kg": 1.5,
      "channel_image_url": "https://store/media/catalog/product/.../image.jpg"
    }
  ]
}
```

Update payloads add `id` (the Bob Go order id) at the top level. Line items that already have a Bob Go id from a previous response carry `id` on the line.

### Payment Status Mapping

| Condition | Bob Go Payment Status |
|-----------|----------------------|
| `TotalDue <= 0` | `paid` |
| *(otherwise)* | `unpaid` |

### Important Notes

- Child items (e.g., configurable product children) are **skipped** during item mapping (`getParentItemId()` check)
- `channel_ref_id` on the order is the Magento `entity_id` (NOT `increment_id`)
- `channel_ref_id` on each line item is the Magento `item_id`
- Update payloads include the `id` field (Bob Go order id) at the top level
- `updateOrder()` is a no-op (no API call, no log entry) when the payload hash matches `bobgo_sync_hash` — a repeated save with no material change does nothing
- Errors are logged + recorded in `bobgo_sync_log` but **never thrown** to the caller — order saving is not blocked by push failures

---

## 9. Fulfillment Sync

Fulfillment sync creates Magento shipments when orders are fulfilled in Bob Go. It works primarily via webhooks: Bob Go sends a signed POST to `/bobgo/webhook/receive` with the topic `fulfillment/created`. See [Webhook System](#10-webhook-system). Bob Go retries failed webhook deliveries, and an **hourly reconciliation cron** (see [Reconciliation](#10a-reconciliation)) provides a safety net for any deliveries that are lost or delayed beyond the retry window.

Every accepted fulfillment / tracking webhook also stamps `sales_order.bobgo_last_webhook` with the current UTC timestamp so operators can see when Bob Go last contacted the store for that order.

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

**Route:** `POST /bobgo/webhook/receive`
**Access:** Anonymous (CSRF disabled — auth is via HMAC, not Magento's CSRF token)
**Defined in:** `etc/frontend/routes.xml` + `Controller/Webhook/Receive.php`
**Topic resolution order:**
1. `X-BobGo-Topic` header
2. `X-Webhook-Topic` header
3. `X-Topic` header
4. Payload-shape inference (`shipment_tracking_reference` / `checkpoints` → `tracking/updated`; `method_reference` + `order_items` → `fulfillment/created`)

### Inbound Pipeline

```
POST /bobgo/webhook/receive
       │
       ▼
  Read raw body  ── never decoded before signature check
       │
       ▼
  WebhookSignatureVerifier::verify(rawBody, Bobgo-Webhook-Signature)
   ┌──────────────────┴──────────────────┐
   │ false                                │ true
   ▼                                      ▼
  403 + log webhook_rejected         json_decode(body)
                                          │
                                          ▼
                                     Resolve topic + event_id
                                          │
                                          ▼
                                     SyncLogger::wasEventIdProcessed(event_id)?
                                       ┌──────────┴──────────┐
                                       │ true                  │ false
                                       ▼                       ▼
                                  200 "duplicate"         Route to handler
                                                               │
                                            ┌──────────────────┼──────────────────┐
                                            ▼                  ▼                  ▼
                                  fulfillment/created   tracking/updated      unknown
                                            │                  │                  │
                                            ▼                  ▼                  ▼
                                  FulfillmentService::    FulfillmentService::  log warn,
                                  processFulfillment()    processTrackingUpdate() 200 "ignored"
                                            │                  │
                                            └────────┬─────────┘
                                                     ▼
                                          stampLastWebhook(order)
                                                     ▼
                                          SyncLogger::logInbound(..., success=true, 200)
```

### Signature Verification (`Service/WebhookSignatureVerifier.php`)

- **Algorithm:** HMAC-SHA256
- **Key:** decrypted `carriers/bobgo/webhook_secret` (merchant-issued, never auto-generated)
- **Encoding:** Base64
- **Header:** `Bobgo-Webhook-Signature`
- **Compare:** `hash_equals()` (constant-time)
- **Failure modes (all return 403, body never inspected):**
  - Webhook secret not configured → warning logged
  - Signature header missing or empty → warning logged
  - Computed digest ≠ provided digest → silent reject (no detail leaked)

### Event-ID Deduplication

Each inbound delivery is checked for prior successful processing via `SyncLogger::wasEventIdProcessed($eventId)`. The id is read from:
1. `Bobgo-Webhook-Event-Id` header (preferred)
2. `event_id` key in the JSON body (fallback)

Duplicates return `200 "duplicate, ignored"` and are **not** re-processed or re-logged as new events.

### Sync Log Entries Written

Every inbound delivery results in exactly one `bobgo_sync_log` row, regardless of outcome:

| Event type | When |
|-----------|------|
| `webhook_rejected` | Signature missing/invalid/secret unset, or body unparseable, or topic indeterminate |
| `fulfillment_received` | `fulfillment/created` accepted and processed |
| `tracking_updated`    | `tracking/updated` accepted and processed |
| `webhook_received`    | Unknown topic accepted (logged + ignored) |

### Supported Topics

| Topic | Handler | Description |
|-------|---------|-------------|
| `fulfillment/created` | `FulfillmentService::processFulfillment()` | Creates a Magento shipment |
| `tracking/updated` | `FulfillmentService::processTrackingUpdate()` | Adds tracking to the latest shipment + status comment |

### Webhook Subscription Management (`Service/WebhookSubscriptionService.php`)

Subscriptions are automatically managed when the **Fulfillment sync** toggle, **Environment**, or **API key** changes in admin config.

**Subscribe:**
```json
POST /v2/webhooks
{
  "webhook_subscriptions": [
    { "delivery_url": "https://store.example.com/bobgo/webhook/receive", "topic": "fulfillment/created", "status": "active" },
    { "delivery_url": "https://store.example.com/bobgo/webhook/receive", "topic": "tracking/updated", "status": "active" }
  ]
}
```

**Unsubscribe:**
1. `GET /v2/webhooks` — list current subscriptions
2. `DELETE /v2/webhooks/{id}` — delete each subscription

The delivery URL is built from `StoreManagerInterface::getStore()->getBaseUrl()` + `bobgo/webhook/receive`.

---

## 10a. Reconciliation

`Service/ReconciliationService.php` is the safety-net job that catches webhooks Bob Go retried-out-of or never delivered.

### Cron schedule

`etc/crontab.xml`:

```xml
<job name="bobgo_reconcile_fulfillments" instance="BobGroup\BobGo\Cron\Reconcile" method="execute">
    <schedule>0 * * * *</schedule>
</job>
```

`Cron\Reconcile::execute()` is a thin wrapper that swallows any unexpected exceptions and delegates to `ReconciliationService::run()`.

### Selection criteria

Orders considered "in active fulfilment" and eligible for reconciliation are those with:
- `bobgo_order_id IS NOT NULL` (i.e. previously pushed to Bob Go)
- `state IN (new, processing, holded)`

Batched at **100 orders per run** (`ReconciliationService::BATCH_SIZE`).

### Behaviour per order

For each candidate, `reconcileOrder(OrderInterface $order)`:
1. Calls `GET /v2/order-fulfillments?order_id={bobgo_order_id}`.
2. Extracts the shipments array, tolerating any of these response wrappers: `order_fulfillments`, `fulfillments`, `shipments`, `data` — or a bare top-level list.
3. JSON-encodes the shipments and compares to the previous `bobgo_shipments` value.
4. **Only writes** when the value actually changed (quiet runs are no-ops; reconciliation must not force a write each tick).
5. When it does write, also updates `bobgo_last_synced`.
6. Records a `reconciliation_fetched` row in `bobgo_sync_log` with status 200 (success) or the upstream HTTP status (failure).

### Skip conditions

`run()` exits immediately without scanning when:
- `isFulfillmentSyncEnabled()` is false, or
- `isConfigured()` is false (no API key).

`reconcileOrder()` skips silently when the order has no `bobgo_order_id`.

### Manual invocation

`reconcileOrder()` is `public` and is reused by the admin "Resync" button on the order detail page (see [Admin Order Panel](#10b-admin-order-panel)).

---

## 10b. Admin Order Panel

A Bob Go panel is injected into the admin order detail page (`sales_order_view` layout, `order_additional_info` container).

### Files

| File | Role |
|------|------|
| `Block/Adminhtml/Order/View/BobGoInfo.php` | Block — reads `current_order` from registry, exposes `getShipments()` (decoded `bobgo_shipments`) and `getResyncUrl()` |
| `view/adminhtml/layout/sales_order_view.xml` | Injects the block into the order view layout |
| `view/adminhtml/templates/order/view/bobgo_info.phtml` | Template — renders sync state + per-shipment cards + Resync button |
| `Controller/Adminhtml/Order/Resync.php` | Admin action handler for the Resync button (ACL: `Magento_Sales::actions_edit`) |
| `etc/adminhtml/routes.xml` | Declares the admin `bobgo` route |

### What it renders

A summary table:
- Bob Go order id (`bobgo_order_id`)
- Bob Go order ref (`bobgo_order_ref`) — falls back to `—` when Bob Go didn't return one
- Sync status (`pending` / `success` / `failed`)
- Last synced (`bobgo_last_synced`)
- Last webhook received (`bobgo_last_webhook`)

Followed by one card per shipment (when `bobgo_shipments` is populated), showing tracking number, courier, status. Field-name fallbacks are tolerant (`courier` ↔ `courier_name`) for cases where Bob Go's payload shape varies.

### Resync action

`POST /bobgo/order/resync/order_id/{id}`:

1. Loads the order.
2. **Clears** `bobgo_sync_hash` so the dirty-check doesn't short-circuit.
3. Calls `OrderPushService::updateOrder()` (or `pushOrder()` if `bobgo_order_id` is empty).
4. Calls `ReconciliationService::reconcileOrder()` to refresh `bobgo_shipments`.
5. Adds a success/error message and redirects back to the order view.

---

## 10c. Sync Log

`bobgo_sync_log` is the single audit trail for all Bob Go-related API activity. Every inbound webhook (accepted **and** rejected) and every outbound POST/PATCH/GET writes exactly one row.

### Components

| File | Role |
|------|------|
| `Model/SyncLog.php` | Entity + event-type and direction constants |
| `Model/ResourceModel/SyncLog.php` | Resource model (table `bobgo_sync_log`) |
| `Model/ResourceModel/SyncLog/Collection.php` | Collection for filtered queries |
| `Service/SyncLogger.php` | Single writer — `logInbound`, `logOutbound`, `wasEventIdProcessed` |

### Event types (constants on `Model\SyncLog`)

| Constant | Value | Direction |
|----------|-------|-----------|
| `EVENT_WEBHOOK_RECEIVED` | `webhook_received` | inbound |
| `EVENT_WEBHOOK_REJECTED` | `webhook_rejected` | inbound |
| `EVENT_FULFILLMENT_RECEIVED` | `fulfillment_received` | inbound |
| `EVENT_TRACKING_UPDATED` | `tracking_updated` | inbound |
| `EVENT_ORDER_UPDATED_INBOUND` | `order_updated_inbound` | inbound |
| `EVENT_ORDER_CREATED` | `order_created` | outbound |
| `EVENT_ORDER_UPDATED_OUTBOUND` | `order_updated_outbound` | outbound |
| `EVENT_RECONCILIATION_FETCHED` | `reconciliation_fetched` | outbound |

### Schema

See [Database Schema](#17-database-schema) §17 for the full column list. Indexed on `event_id`, `order_id`, and `created_at` for efficient dedup / debugging queries.

### Payload truncation

Payloads (request bodies, response bodies, error messages) are truncated to **65,535 bytes** before persisting to fit the `TEXT` column reliably across MySQL configurations.

### Dedup guarantees

`SyncLogger::wasEventIdProcessed($eventId)` returns `true` only when a prior row exists with the same `event_id`, `direction = 'inbound'`, and `success = 1`. Null/empty event ids are never considered duplicates.

> **Retention:** No automatic purging is wired up yet. Plan to add a daily cron + configurable retention window.

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

### Table: `sales_order` (added columns)

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| `bobgo_order_id` | VARCHAR(255) | Yes | Bob Go's internal numeric order id (set after successful push) |
| `bobgo_order_ref` | VARCHAR(255) | Yes | Bob Go's immutable string reference (used for webhook lookups) |
| `bobgo_sync_status` | VARCHAR(32) | Yes | `pending` / `success` / `failed` |
| `bobgo_sync_hash` | VARCHAR(64) | Yes | MD5 of last successfully-synced payload (drives dirty-check) |
| `bobgo_last_synced` | TIMESTAMP | Yes | UTC timestamp of last successful outbound sync |
| `bobgo_last_webhook` | TIMESTAMP | Yes | UTC timestamp of last accepted inbound Bob Go webhook |
| `bobgo_shipments` | TEXT | Yes | JSON-encoded shipments array from Bob Go (authoritative; refreshed by reconciliation) |

### Table: `sales_order_item` (added columns)

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| `bobgo_order_item_id` | VARCHAR(255) | Yes | Bob Go's internal line item id, returned from POST `/v2/orders` |

### Table: `bobgo_sync_log` (new)

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| `entity_id` | INT UNSIGNED (identity) | No | Primary key |
| `order_id` | INT UNSIGNED | Yes | Magento `sales_order.entity_id` (null for non-order events) |
| `event_type` | VARCHAR(64) | No | See [Sync Log](#10c-sync-log) for the constant list |
| `direction` | VARCHAR(16) | No | `inbound` or `outbound` |
| `event_id` | VARCHAR(128) | Yes | Provider-issued event id for dedup |
| `payload` | TEXT | Yes | JSON-encoded request/response body (truncated to 65,535 bytes) |
| `http_status` | SMALLINT UNSIGNED | Yes | HTTP status returned / observed |
| `success` | BOOLEAN | No | Outcome flag (default 0) |
| `retry_count` | SMALLINT UNSIGNED | No | Number of retries observed (default 0) |
| `created_at` | TIMESTAMP | No | Default `CURRENT_TIMESTAMP` |

**Indexes:**
- `BOBGO_SYNC_LOG_EVENT_ID` on `event_id`
- `BOBGO_SYNC_LOG_ORDER_ID` on `order_id`
- `BOBGO_SYNC_LOG_CREATED_AT` on `created_at`

All schema changes are declared in `etc/db_schema.xml` and whitelisted in `etc/db_schema_whitelist.json`.

### Extension Attributes

Defined in `etc/extension_attributes.xml`:

| Interface | Attribute | Type | Purpose |
|-----------|-----------|------|---------|
| `Magento\Quote\Api\Data\AddressInterface` | `suburb` | string | Suburb for shipping rate accuracy |
| `Magento\Sales\Api\Data\OrderInterface` | `bobgo_order_id` | string | Bob Go order tracking ID |

---

## 18. Dependency Injection

### `etc/di.xml` (Global)

**Interface Preferences:**

```xml
<preference for="BobGroup\BobGo\Api\OrderMapperInterface"
            type="BobGroup\BobGo\Service\OrderMapper" />
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
        <argument name="storeManager" xsi:type="object">Magento\Store\Model\StoreManagerInterface</argument>
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

## 19. Admin Configuration UI

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
| 6 | `enable_fulfillment_sync` | Enable fulfillment sync | Yes/No | Manages webhook subscriptions + drives reconciliation cron |
| 7 | `notify_customer_on_shipment` | Notify customer on shipment | Yes/No | |
| 8 | `webhook_secret` | Webhook Signing Secret | Obscure | Encrypted; **required** for fulfillment sync — without it, inbound webhooks 403 |
| 10 | `enable_track_order` | Enable Track my order | Yes/No | **Hidden** (showInDefault=0) |

### Store Information Addition

The module adds a **Suburb** field to **Stores > Configuration > General > Store Information** (section `general`, group `store_information`, field `suburb`).

---

## 20. Data Flow Diagrams

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
   Webhook POST /rest/V1/bobgo/webhook
   → WebhookReceiver → FulfillmentService::processFulfillment()
   → Creates Magento shipment

4. TRACKING UPDATE
   Webhook POST /rest/V1/bobgo/webhook (tracking/updated)
   → FulfillmentService::processTrackingUpdate()
   → Adds tracking numbers to latest shipment
```

---

## 21. Error Handling and Logging

### Logging Strategy

All components log to Magento's standard logger (`Psr\Log\LoggerInterface`), which writes to `var/log/system.log` by default.

| Component | Log Prefix | Level | Context |
|-----------|-----------|-------|---------|
| BobGoApiClient | `Bob Go API error` | ERROR | endpoint, status_code, response, masked api_key |
| OrderPushService | `Bob Go: Order pushed/failed` | INFO/ERROR | order_id, increment_id, bobgo_order_id |
| FulfillmentService | `Bob Go fulfillment:` | INFO/ERROR | order_id, fulfillment_id, error |
| ConfigChangeObserver | `Bob Go connectivity/RAC/webhook` | ERROR | error message |
| WebhookReceiver | `Bob Go webhook` | INFO/ERROR/WARNING | topic, error |
| OrderSaveObserver | `Bob Go: OrderSaveObserver` | ERROR | error message |
| Helper\Data | *(custom)* | DEBUG | Only when debug mode enabled |

### Error Recovery Patterns

1. **Non-blocking observers** - `OrderSaveObserver` wraps everything in try/catch. A Bob Go API failure will never prevent an order from being saved.
2. **Idempotent fulfillments** - `hasExistingFulfillment()` checks tracking numbers to prevent duplicate shipments if the same webhook fires twice.
3. **Graceful API failures** - `BobGo::uRates()` returns `null` on API error; `_getRates()` logs and returns empty result. The customer sees no rates rather than an error page.
4. **Webhook resilience** - Unknown topics are logged as warnings and return `'unknown topic'`. Processing failures return `'error processing webhook'` but don't throw.

---

## 22. Security

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

The webhook endpoint at `/bobgo/webhook/receive` is anonymous at the Magento layer (no Magento user / CSRF token required) but is **authenticated cryptographically**:

- Every request must carry a valid `Bobgo-Webhook-Signature` header
- The signature is `base64( hmac_sha256(raw_body, merchant_webhook_secret) )`
- The integration recomputes the digest over the same raw byte sequence and compares with `hash_equals()` (constant time)
- The body is **never decoded or inspected** before the signature passes
- Failure modes — missing secret, missing header, mismatch — all return 403 and log a `webhook_rejected` row in `bobgo_sync_log`

The webhook secret is merchant-issued (never auto-generated by this extension) and stored encrypted under `carriers/bobgo/webhook_secret` via `Backend\Encrypted`.

### Channel Identifier on Outbound Calls

Every outbound API call carries `bobgo-channel-identifier: {store base URL}` so Bob Go can associate the call with the correct channel and refuse cross-channel access.

### Input Validation

- Weight validation: Max 500 kg per item
- Country validation: Only ZA (South Africa) accepted
- Fulfillment idempotency: Duplicate tracking numbers are rejected
- Order existence: Fulfillments are rejected if the referenced order doesn't exist
- Ship eligibility: `canShip()` check before creating shipments

---

## 23. Testing

### Test Framework

- **PHPUnit 9.5** with Magento framework mocks
- **PHPStan 1.x** at level 2 for static analysis
- Tests do **not** require a running Magento instance — `Test/bootstrap.php` stubs the Magento framework
- Located in `Test/Unit/`

### Running Tests

```bash
# Composer scripts (recommended)
composer test    # phpunit
composer stan    # phpstan analyse
composer check   # both, in sequence

# Direct invocations
vendor/bin/phpunit --prepend Test/stubs/autoload-prepend.php \
                   --bootstrap Test/bootstrap.php \
                   Test/Unit/

vendor/bin/phpstan analyse --no-progress

# Run a specific test
vendor/bin/phpunit --prepend Test/stubs/autoload-prepend.php \
                   --bootstrap Test/bootstrap.php \
                   --filter testCollectRates \
                   Test/Unit/Model/Carrier/BobGoTest.php
```

**Status:** 115 tests / 204 assertions passing. PHPStan: 0 errors at level 2.

### Test Files

| Test File | Tests |
|-----------|-------|
| `Api/BobGoApiClientTest.php` | HTTP client: GET/POST/PATCH/DELETE, error handling, auth + channel-id headers |
| `Block/System/Config/Form/Field/VersionTest.php` | Version display block |
| `Helper/DataTest.php` | Helper functions, debug logging |
| `Model/Carrier/AdditionalInfoTest.php` | Request body parsing (suburb, company, phone) |
| `Model/Carrier/BobGoTest.php` | Rate collection, validation, weight conversion, formatting |
| `Model/Config/ApiConfigTest.php` | Configuration getters, environment URLs, feature flags |
| `Model/Source/FreemethodTest.php` | Free method source model |
| `Model/Source/GenericTest.php` | Generic source model base |
| `Observer/ConfigChangeObserverTest.php` | Config change reactions, connectivity tests |
| `Observer/OrderSaveObserverTest.php` | Order push/update triggering |
| `Plugin/AddWeightUnitToOrderPluginTest.php` | LBS to KG conversion |
| `Service/FulfillmentServiceTest.php` | Shipment creation, tracking updates, idempotency |
| `Service/OrderMapperTest.php` | Order-to-payload mapping, status mapping |
| `Service/OrderPushServiceTest.php` | Order POST/PATCH, sync-hash dirty-check, sync-log writes |
| `Service/ReconciliationServiceTest.php` | Reconciliation cron — gated by config, batched fetch, change-detection, API error handling |
| `Service/WebhookSignatureVerifierTest.php` | HMAC verification — correct/wrong/tampered/missing-secret/missing-header/different-secret |
| `Service/WebhookSubscriptionServiceTest.php` | Subscribe/unsubscribe webhook management |

### Test Bootstrap

- `Test/bootstrap.php` — Magento framework stubs (DataObject, AbstractCarrier, AbstractModel, Action, RequestInterface, DateTime, etc.)
- `Test/stubs/autoload-prepend.php` — `ComponentRegistrar` stub loaded **before** composer autoload via `--prepend`
- `phpstan.neon` — PHPStan loads the same stubs via `bootstrapFiles`; `ignoreErrors` patterns suppress noise from stubbed Magento APIs that PHPStan can't fully resolve

---

## 24. Build and Distribution

Distribution zips are created with `git archive`. The `.gitattributes` file defines export-ignore rules to exclude dev-only files (tests, docs, build scripts).

```bash
git archive --format=zip HEAD -o bobgo-magento-extension.zip
```

---

## 25. Version Management

### Source of Truth

`composer.json` `"version"` field.

### Version Sync

The version is maintained in two locations:

1. **`composer.json`** - `"version"` field
2. **`etc/module.xml`** - `setup_version` attribute

### Bumping

Run `./bump-version.sh` manually when preparing a release:

```bash
./bump-version.sh patch   # 1.0.62 -> 1.0.63
./bump-version.sh minor   # 1.0.62 -> 1.1.0
./bump-version.sh major   # 1.0.62 -> 2.0.0
./bump-version.sh 2.0.0   # explicit version
```

The script updates both `composer.json` and `etc/module.xml`.

---

## 26. Known Limitations

1. **South Africa Only** — The `processAdditionalValidation()` method rejects all non-ZA countries. To support other countries, this validation must be modified.

2. **Tracking Page Hidden** — The `enable_track_order` setting is hidden in admin (`showInDefault="0"`). To enable it, update `etc/adminhtml/system.xml` to set `showInDefault="1"`. The whole tracking-page surface is dormant code at this point — either ship it or delete it.

3. **Order Push is Synchronous** — `OrderSaveObserver` calls Bob Go inline on `sales_order_save_after`. Bob Go API latency blocks order saves. The sync-hash dirty-check helps on repeated saves (no redundant calls), but the first save is still inline. Async queue is on the roadmap (spec §4 of the channel integration spec).

4. **Suburb Extraction Fragility** — `AdditionalInfo::getSuburb()` reads from `custom_attributes[0].value` (hardcoded index 0). If other custom attributes are added before suburb, this will break.

5. **Single Carrier Instance** — The extension only supports one Bob Go carrier configuration per store. Multi-store setups share the same carrier code `bobgo`.

6. **Weight Conversion on Every Save** — `AddWeightUnitToOrderPlugin` converts LBS to KG on every `OrderRepository::save()` call, which could compound if an order is saved multiple times.

7. **ModifyShippingDescription Regex** — Uses `strrpos(' - ')` which may incorrectly parse shipping descriptions that contain ` - ` in the service name itself.

8. **AdditionalInfo Direct Instantiation** — `AdditionalInfo` is created via `new AdditionalInfo()` in the `BobGo` constructor rather than through DI, making it harder to mock in tests.

9. **Registry Deprecation** — `TrackingBlock` and `Controller\Tracking\Index` use `Magento\Framework\Registry`, deprecated since Magento 2.3. Should migrate to view models or request parameters.

10. **No Rate Caching** — Every checkout address change triggers a new API call. No caching of rate responses (spec §3.4 of the channel integration spec).

11. **`bobgo_order_ref` field-name guesswork** — `OrderPushService::applySuccess()` tries `response['order_ref']` then `response['reference']`. If Bob Go's actual response key for the immutable string ref is neither, `bobgo_order_ref` stays null forever. Worth verifying against sandbox.

12. **Reconciliation has no pagination** — `ReconciliationService::loadCandidateOrders()` uses `setPageSize(100)` with no offset. Stores with >100 active orders will leave the tail permanently stale until a webhook arrives.

13. **Sync log retention** — `bobgo_sync_log` has no automatic purge cron. Will grow unbounded on busy stores.

14. **`etc/webapi.xml` is now empty** — The webhook endpoint moved off `/rest/V1/bobgo/webhook` (Magento webapi) to `/bobgo/webhook/receive` (standard frontend route). The empty `webapi.xml` and the `Magento_Webapi` `<sequence>` dependency in `module.xml` are vestigial and can be removed.

---

## 27. Troubleshooting

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
4. **Check logs** for `Bob Go fulfillment:` entries

### Duplicate Shipments

The extension has idempotency checks (tracking number matching). If duplicates still occur:
1. Check if fulfillments have different tracking numbers for the same logical shipment

### Weight Issues

1. **Verify store weight unit:** Check `general/locale/weight_unit` (should be `kgs` or `lbs`)
2. **Check product weights:** Ensure products have weights set in the catalog
3. **Note:** Weights are sent in grams to the API. A 1.5 kg item = 1500 grams.

---

## 28. File Reference

### PHP Classes - Quick Reference

| Class | Purpose |
|-------|---------|
| `Api\BobGoApiClient` | HTTP client for all Bob Go API calls (Bearer + channel-identifier headers) |
| `Api\BobGoApiException` | API error with status code, body, endpoint |
| `Api\OrderMapperInterface` | Interface for order→payload mapping |
| `Block\Adminhtml\Order\View\BobGoInfo` | Admin order detail panel block |
| `Block\System\Config\Form\Field\Version` | Version display in admin |
| `Block\TrackingBlock` | Template block for tracking page |
| `Block\TrackOrderLink` | Conditional tracking link |
| `Controller\Adminhtml\Order\Resync` | Admin Resync action (re-push + reconcile) |
| `Controller\Tracking\Index` | Tracking page controller |
| `Controller\Webhook\Receive` | Unified webhook controller (HMAC verify, dedup, route) |
| `Cron\Reconcile` | Hourly cron entry point — wraps `ReconciliationService::run()` |
| `Helper\Data` | Module helper (version, debug log) |
| `Model\Carrier\AdditionalInfo` | Extracts suburb/company from request body |
| `Model\Carrier\BobGo` | Main carrier - rate calculation |
| `Model\Config\ApiConfig` | Centralized API config access (incl. webhook secret) |
| `Model\ResourceModel\SyncLog` | Resource model for `bobgo_sync_log` |
| `Model\ResourceModel\SyncLog\Collection` | Collection for sync log queries |
| `Model\Source\Environment` | Sandbox/Production dropdown |
| `Model\SyncLog` | Sync log entity + event/direction constants |
| `Observer\ConfigChangeObserver` | Admin config change handler |
| `Observer\ModifyShippingDescription` | Cleans shipping description |
| `Observer\OrderSaveObserver` | Triggers order push on save |
| `Plugin\AddWeightUnitToOrderPlugin` | LBS→KG weight conversion |
| `Plugin\Checkout\Block\LayoutProcessorPlugin` | Adds suburb to checkout |
| `Plugin\OrderRepositoryPlugin` | Manages bobgo_order_id ext attr |
| `Service\FulfillmentService` | Creates shipments from fulfillments (stamps `bobgo_last_webhook`) |
| `Service\OrderMapper` | Order→API payload transformation (emits `channel_ref_id`) |
| `Service\OrderPushService` | POST/PATCH orders to Bob Go (sync-hash dirty-check) |
| `Service\ReconciliationService` | Hourly reconciliation — re-fetch authoritative shipments |
| `Service\SyncLogger` | Single writer for `bobgo_sync_log` + event_id dedup |
| `Service\WebhookSignatureVerifier` | HMAC-SHA256 verification of inbound webhooks |
| `Service\WebhookSubscriptionService` | Manages webhook subscriptions |

### XML Configuration Files - Quick Reference

| File | Purpose |
|------|---------|
| `etc/module.xml` | Module definition, version, dependencies |
| `etc/config.xml` | Default config values |
| `etc/crontab.xml` | Hourly reconciliation schedule |
| `etc/di.xml` | DI preferences, plugins, arguments |
| `etc/events.xml` | Frontend event observers |
| `etc/adminhtml/events.xml` | Admin event observers |
| `etc/adminhtml/routes.xml` | Admin `bobgo` route (Resync action) |
| `etc/adminhtml/system.xml` | Admin configuration UI |
| `etc/extension_attributes.xml` | suburb + bobgo_order_id attributes |
| `etc/db_schema.xml` | sales_order columns + `bobgo_sync_log` table |
| `etc/db_schema_whitelist.json` | Declarative schema whitelist |
| `etc/webapi.xml` | Empty (vestigial; webhook route moved to frontend `etc/frontend/routes.xml`) |
| `etc/acl.xml` | Access control list |
| `etc/frontend/di.xml` | Frontend checkout plugin |
| `etc/frontend/routes.xml` | Frontend `/bobgo` route (webhook + tracking) |
| `view/adminhtml/layout/sales_order_view.xml` | Injects Bob Go panel into admin order view |
| `view/adminhtml/templates/order/view/bobgo_info.phtml` | Bob Go admin panel template |
| `phpstan.neon` | PHPStan level 2 config |

---

*Last updated: 2026-05-19*
*Extension version: 1.1.0*
