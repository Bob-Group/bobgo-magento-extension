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

- `Magento_Catalog` - Product data for rate requests
- `Magento_Store` - Store configuration and base URLs
- `Magento_Sales` - Orders, shipments, tracking
- `Magento_Quote` - Cart/quote for rate calculation
- `Magento_SalesRule` - Discount calculations
- `Magento_Config` - System configuration
- `Magento_Shipping` - Carrier framework

> Earlier 1.0.x builds also depended on `Magento_Webapi` for a REST webhook
> route. The webhook moved to a standard frontend controller; the dependency
> was dropped along with the now-empty `etc/webapi.xml`.

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
│   ├── Reconcile.php               # Hourly cron entry — thin wrapper over ReconciliationService
│   └── PruneSyncLog.php            # Daily cron — deletes bobgo_sync_log rows older than 30 days
├── Helper/
│   └── Data.php                    # Module helper (version, debug logging)
├── Model/
│   ├── Carrier/
│   │   ├── AdditionalInfo.php      # Extracts suburb/company/phone from checkout request body
│   │   └── BobGo.php              # Main carrier class - rate calculation (region_id → code resolution)
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
│   ├── Checkout/Block/
│   │   └── LayoutProcessorPlugin.php  # Injects suburb field into checkout form
│   ├── Quote/
│   │   └── ToOrderAddressPlugin.php   # Copies suburb from quote address → order address on conversion
│   └── OrderRepositoryPlugin.php      # Loads/saves bobgo_order_id extension attribute
├── Service/
│   ├── FulfillmentService.php         # Creates Magento shipments from Bob Go fulfillments
│   ├── OrderMapper.php                # Maps Magento orders to Bob Go API payload (incl. LBS→KG)
│   ├── OrderPushService.php           # POST/PATCH orders to Bob Go (with sync-hash dirty check); returns bool
│   ├── ReconciliationService.php      # Hourly safety net: re-fetch authoritative shipments
│   ├── SyncLogger.php                 # Single writer for bobgo_sync_log + race-safe claim/release dedup
│   ├── SyncLogRetentionService.php    # Prunes bobgo_sync_log rows older than 30 days
│   ├── TransientWebhookException.php  # Marker exception — controller turns it into HTTP 500 for Bob Go retry
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
│   ├── crontab.xml                    # Cron schedule: hourly reconciliation + daily sync log prune
│   ├── db_schema.xml                  # Sales order/shipment/sync_log columns (incl. UNIQUE event_id+direction)
│   ├── db_schema_whitelist.json       # Schema whitelist for declarative schema
│   ├── di.xml                         # Dependency injection: preferences, plugins, arguments
│   ├── events.xml                     # Frontend events: order save, order place
│   ├── extension_attributes.xml       # Extension attributes: suburb (quote+order address), bobgo_order_id
│   ├── frontend/
│   │   ├── di.xml                     # Frontend DI: checkout layout processor plugin
│   │   └── routes.xml                 # Frontend route /bobgo/* (webhook + tracking)
│   └── module.xml                     # Module definition and dependencies
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
- **Timeouts:**
  - `CURLOPT_TIMEOUT`: 8 s for `rates-at-checkout` (checkout-blocking — fast failure beats a slow success), 15 s default for every other endpoint.
  - `CURLOPT_CONNECTTIMEOUT`: 5 s. Stops a black-holed DNS / firewall from eating the whole request budget before bytes ever leave the box.
  - Fast-path endpoints are declared by name in `BobGoApiClient::FAST_PATH_ENDPOINTS`.
- **Base URL:** Determined by environment setting via `ApiConfig::getBaseUrl()`

**Error Handling:**
- HTTP status >= 400 throws `BobGoApiException` with status code, response body, and endpoint
- API key is masked in log output (shows only last 4 characters: `****xxxx`)
- Response bodies in `Bob Go API error` log entries are capped at 512 bytes (`response_snippet`) — Bob Go's 4xx/5xx responses echo the offending payload back, which can include PII that we don't want recurring in `system.log`
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

- Configurable **parents** are skipped; the simple child is sent with the parent's price mirrored onto it (the child's `getPrice()` is 0)
- `channel_ref_id` on the order is the Magento `entity_id` (NOT `increment_id`)
- `channel_ref_id` on each line item is the Magento `item_id`
- Update payloads include the `id` field (Bob Go order id) at the top level
- `updateOrder()` is a no-op (no API call, no log entry) when the payload hash matches `bobgo_sync_hash` — a repeated save with no material change does nothing
- Errors are logged + recorded in `bobgo_sync_log` but **never thrown** to the caller — order saving is not blocked by push failures
- `pushOrder()` and `updateOrder()` return `bool`. The observer ignores the return value (order save must never block), but the admin Resync controller uses it to surface real success/failure to the operator instead of always claiming "triggered"
- `local_area` is read from the order shipping address's `suburb` extension attribute (set by `ToOrderAddressPlugin`) — falls back to `city` when the suburb isn't populated
- Item weights are normalised to kilograms by `OrderMapper::normaliseWeightKg()` at payload-build time. The order row itself is never mutated; see §12 for the failure mode this fixes
- `bobgo_order_ref` is persisted from the API response (`response['reference']` or `response['order_ref']`) — the schema column was unused before 1.1.0

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
         │ yes (otherwise: log + return — permanent, 200)
         ▼
Idempotency checks (in order):
  1. tracking number already on a shipment for this order → skip
  2. fulfillment_id already stamped on a shipment for this order → skip
     (bobgo_fulfillment_id column on sales_shipment)
  3. payload had neither identifier → REFUSE
     (an empty $items array would otherwise become "ship everything")
         │ no duplicate
         ▼
Build shipment items (partial fulfillment support):
  - Match line items by Bob Go order_item id first (bobgo_order_item_id link)
  - Fall back to SKU, popping from a per-SKU queue so duplicate SKUs on
    the order aren't collapsed onto one line
  - If the payload listed items but none matched → throw
    TransientWebhookException; 500 → Bob Go retries
  - If line_items was empty in the payload → full fulfillment (ship all)
         │
         ▼
Build tracking entries:
  - carrier_code: 'bobgo'
  - title: courier_name | shipment.provider.name | 'Bob Go'
  - track_number: method_reference
         │
         ▼
ShipOrderInterface::execute() — returns new shipment id
  - Optionally notifies customer (based on config)
         │
         ▼
Stamp bobgo_fulfillment_id on the new shipment row so future
duplicate events for the same fulfillment_id are deduped even
after webhook event_id retention expires.

On a thrown exception from execute(): wrap in
TransientWebhookException — Bob Go retries.
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
  403 + log webhook_rejected         apiConfig.isFulfillmentSyncEnabled()?
   (body truncated to 256 B)              │
                                          │ no  → 200 "fulfillment sync disabled" (don't process)
                                          │ yes
                                          ▼
                                     json_decode(body)
                                          │
                                          ▼
                                     Resolve topic + event_id
                                          │
                                          ▼
                                     SyncLogger::claimEventId(event_id, topic)
                                       ┌────────┴────────┐
                                       │ false (dup)     │ true (claimed)
                                       ▼                 ▼
                                  200 "duplicate"   Route to handler
                                                         │
                                          ┌──────────────┼──────────────┐
                                          ▼              ▼              ▼
                                fulfillment/created tracking/updated unknown
                                          │              │              │
                                          ▼              ▼              ▼
                                processFulfillment() processTracking() webhook_unknown_topic
                                          │              │              │
                                          ├── success ──┼── success ────┴── 200, log success=true
                                          │              │
                                          │              │             (upgradeClaim → success=1)
                                          │              │
                                          ├── TransientWebhookException
                                          │     │
                                          │     ▼
                                          │  releaseEventIdClaim()
                                          │  logInbound(..., event_id=NULL, success=false)
                                          │  500 → Bob Go retries
                                          │
                                          └── any other \Throwable
                                                │
                                                ▼
                                            KEEP the claim row (don't release)
                                            logInbound(..., event_id=NULL, success=false)
                                            500 (operator must intervene)
```

The `event_id=NULL` on failure rows is load-bearing. Writing the failure
under the same `event_id` would re-occupy the `UNIQUE (event_id, direction)`
slot, and Bob Go's retry would look like a duplicate and be 200'd — the
event would be silently dropped. Passing `null` keeps the slot free for
the retry to claim.

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

### Event-ID Deduplication (race-safe)

Inbound deliveries are deduped via an atomic claim against the `UNIQUE (event_id, direction)` constraint on `bobgo_sync_log`.

`SyncLogger::claimEventId($eventId, $topic)`:
1. INSERTs a sentinel row with `event_type = 'webhook_claim'`, `success = 0`.
2. On `AlreadyExistsException` or a `SQLSTATE[23000]` driver-level duplicate-key error → returns `false`; the caller 200's the request without processing.
3. On any other DB error → logs and returns `true` (fail-open: a transient DB issue must not silently drop events).

On successful processing, `logInbound()` upgrades the same claim row in place (`success=1`, real event type, full payload) via `upgradeClaim()` — so there's still only one row per `event_id`.

On transient failure (`TransientWebhookException`), `releaseEventIdClaim($eventId)` DELETEs the claim, and the failure log row is written with `event_id = NULL` so the next retry can claim cleanly.

On any other unexpected `\Throwable`, the claim row stays. Retries from Bob Go are short-circuited at the claim step (200 "duplicate") rather than re-running potentially broken code. An operator clears the row to allow a replay.

The event id is read from:
1. `Bobgo-Webhook-Event-Id` header (preferred)
2. `event_id` key in the JSON body (fallback)

NULL/empty event ids skip dedup entirely (MySQL treats NULLs as not-equal in unique indexes, so the constraint allows multiple).

### Sync Log Entries Written

| Event type | When |
|-----------|------|
| `webhook_claim`         | Sentinel row written by `claimEventId()` — upgraded to a real event type on success |
| `webhook_rejected`      | Signature missing/invalid/secret unset, or body unparseable (body truncated to 256 B) |
| `webhook_received`      | Transient/unexpected failure log (event_id intentionally NULL) |
| `webhook_unknown_topic` | Topic unrecognised — 200 returned, but `success=false` so operators can grep for it |
| `fulfillment_received`  | `fulfillment/created` accepted and processed |
| `tracking_updated`      | `tracking/updated` accepted and processed |

### Supported Topics

| Topic | Handler | Description |
|-------|---------|-------------|
| `fulfillment/created` | `FulfillmentService::processFulfillment()` | Creates a Magento shipment |
| `tracking/updated` | `FulfillmentService::processTrackingUpdate()` | Adds tracking to the latest shipment + status comment |
| `order/updated` | _(none — acknowledge + log only)_ | HMAC-verified, deduped, and written to `bobgo_sync_log` as `EVENT_ORDER_UPDATED_INBOUND`. Local order is not mutated; field-mapping is reserved for a future pass against real payloads. |

### Webhook Subscription Management (`Service/WebhookSubscriptionService.php`)

Subscriptions are automatically managed when the **Fulfillment sync** toggle, **Environment**, or **API key** changes in admin config.

**Subscribe:**
```json
POST /v2/webhooks
{
  "webhook_subscriptions": [
    { "delivery_url": "https://store.example.com/bobgo/webhook/receive", "topic": "fulfillment/created", "status": "active" },
    { "delivery_url": "https://store.example.com/bobgo/webhook/receive", "topic": "tracking/updated", "status": "active" },
    { "delivery_url": "https://store.example.com/bobgo/webhook/receive", "topic": "order/updated",      "status": "active" }
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
<job name="bobgo_prune_sync_log" instance="BobGroup\BobGo\Cron\PruneSyncLog" method="execute">
    <schedule>15 3 * * *</schedule>
</job>
```

`Cron\Reconcile::execute()` is a thin wrapper that swallows any unexpected exceptions and delegates to `ReconciliationService::run()`. `Cron\PruneSyncLog::execute()` does the same for `SyncLogRetentionService::prune()` (see [Sync Log retention](#sync-log-retention)).

### Selection criteria

`loadCandidateOrders()` issues **two scoped queries** and merges the results (deduped by entity id, capped at `BATCH_SIZE = 100`). One query can't express the intent with `SearchCriteriaBuilder` because filter groups AND together — a single `updated_at` filter would also exclude long-stuck active-state orders, which are exactly what reconciliation exists for.

| Query | Filter | Reason |
|-------|--------|--------|
| Active states | `bobgo_order_id IS NOT NULL` AND `state IN (new, processing, holded)` | Always included, regardless of age. Stuck orders need reconciling. |
| Completed lookback | `bobgo_order_id IS NOT NULL` AND `state = complete` AND `updated_at >= now() - 14 days` | Catches late tracking checkpoints (proof of delivery, etc.) without pulling every historical order in. |

The 14-day lookback is `ReconciliationService::COMPLETE_LOOKBACK_DAYS`.

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
| `Service/SyncLogger.php` | Single writer — `logInbound`, `logOutbound`, `claimEventId`, `releaseEventIdClaim`, `wasEventIdProcessed` |
| `Service/SyncLogRetentionService.php` | Daily prune (rows older than 30 days; preserves active `webhook_claim` rows) |
| `Cron/PruneSyncLog.php` | Cron entry point — wraps the retention service |

### Event types (constants on `Model\SyncLog`)

| Constant | Value | Direction | Notes |
|----------|-------|-----------|-------|
| `EVENT_WEBHOOK_CLAIM`         | `webhook_claim`         | inbound  | Sentinel row inserted by `claimEventId()`. Upgraded in place to the real outcome on success. |
| `EVENT_WEBHOOK_RECEIVED`      | `webhook_received`      | inbound  | Used for transient/unexpected processing failures (with `event_id = NULL` so retries can re-claim). |
| `EVENT_WEBHOOK_REJECTED`      | `webhook_rejected`      | inbound  | Signature or body parse failure. Payload truncated to 256 B before persistence. |
| `EVENT_WEBHOOK_UNKNOWN_TOPIC` | `webhook_unknown_topic` | inbound  | Topic resolved but isn't one of the known routes (`fulfillment/created`, `tracking/updated`, `order/updated`). 200 returned, `success = false` so operators can grep. |
| `EVENT_FULFILLMENT_RECEIVED`  | `fulfillment_received`  | inbound  | |
| `EVENT_TRACKING_UPDATED`      | `tracking_updated`      | inbound  | |
| `EVENT_ORDER_UPDATED_INBOUND` | `order_updated_inbound` | inbound  | `order/updated` webhook acknowledged. No local mutation yet — reserved for future field-mapping work. |
| `EVENT_ORDER_UPDATED_INBOUND` | `order_updated_inbound` | inbound  | Reserved (no current emitter). |
| `EVENT_ORDER_CREATED`         | `order_created`         | outbound | |
| `EVENT_ORDER_UPDATED_OUTBOUND`| `order_updated_outbound`| outbound | |
| `EVENT_RECONCILIATION_FETCHED`| `reconciliation_fetched`| outbound | |

### Schema

See [Database Schema](#17-database-schema) §17 for the full column list. Indexed on `event_id`, `order_id`, and `created_at`. **UNIQUE** on `(event_id, direction)` — load-bearing for race-safe webhook dedup. NULL `event_id`s are exempt (MySQL allows multiple NULLs in unique indexes).

### Payload truncation and redaction

Payloads are JSON-encoded, PII-redacted, then truncated to **65,535 bytes** before persisting (fits the `TEXT` column reliably across MySQL configurations).

`SyncLogger::PII_KEYS` (case-insensitive) are recursively replaced with the string `***`:

- **Customer identifiers** — `customer_email`, `customer_phone`, `customer_name`, `customer_surname`, `telephone`, `phone`, `email`
- **Address fields** — `street_address`, `street`, `street1`, `street2`, `address_line_1`, `address_line_2`, `local_area`, `suburb`, `city`, `postcode`, `postal_code`, `zip`, `code` (Bob Go v2's postal-code key)

Rejected webhook bodies are additionally capped at 256 bytes before persistence (`REJECTED_BODY_CAP_BYTES` in the controller) so an unauthenticated attacker can't bloat the table with sustained traffic.

### Dedup guarantees

**`claimEventId($eventId, $topic): bool`** — atomic claim under the UNIQUE constraint. Returns `false` if the slot is already taken (concurrent worker or prior delivery). Returns `true` on a fresh claim OR when the underlying error wasn't a duplicate-key (fail-open: we'd rather process twice than silently drop on a DB blip).

**`releaseEventIdClaim($eventId): void`** — DELETEs the matching `webhook_claim` row so a transient-failure retry can re-claim cleanly.

**`wasEventIdProcessed($eventId): bool`** — legacy non-atomic check, kept for backwards compatibility with code paths that don't need the claim semantics.

NULL/empty event ids skip dedup entirely.

### Sync Log retention

`Cron\PruneSyncLog` runs daily at 03:15 UTC and DELETEs rows older than **30 days** (`SyncLogRetentionService::RETENTION_DAYS`). The `webhook_claim` event type is **excluded** from the prune so an in-flight delivery can't be wiped mid-flight.

The order row keeps `bobgo_last_synced` / `bobgo_last_webhook` for long-term audit; the sync log itself is a short-term debugging buffer.

---

## 11. Suburb Field (Custom Extension Attribute)

South African shipping requires a suburb for accuracy. Magento doesn't have a native suburb field, so this extension adds one. The value flows from the checkout input through to the outbound order push payload as `local_area`.

### Components

| Component | File | Role |
|-----------|------|------|
| Extension attribute (quote address) | `etc/extension_attributes.xml` | Declares `suburb` on `Quote\Api\Data\AddressInterface` |
| Extension attribute (order address) | `etc/extension_attributes.xml` | Declares `suburb` on `Sales\Api\Data\OrderAddressInterface` — required for it to survive the quote → order conversion |
| Checkout field injection | `Plugin/Checkout/Block/LayoutProcessorPlugin.php` | Adds suburb input to shipping form |
| Validation rules | `view/frontend/web/js/model/shipping-rates-validation-rules.js` | Makes suburb required |
| Shipping info mixin | `view/frontend/web/js/action/set-shipping-information-mixin.js` | Copies suburb from `custom_attributes.suburb` → `extension_attributes.suburb` before the AJAX call. Handles three observed shapes: object map, object map of objects, and list of `{attribute_code, value}` |
| Quote → order conversion | `Plugin/Quote/ToOrderAddressPlugin.php` | `afterConvert` plugin on `Magento\Quote\Model\Quote\Address\ToOrderAddress` — copies the suburb extension attribute (or custom attribute / raw data fallback) onto the order address. Without this the value is dropped at order placement. |
| Outbound mapping | `Service/OrderMapper.php::extractSuburb()` | Reads from order address extension attribute → custom attribute → `getData('suburb')` (in that order) and emits as `local_area` |
| Rate-time request body extraction | `Model/Carrier/AdditionalInfo.php` | Reads suburb from rate-estimation request body. Matches `custom_attributes` entries by `attribute_code === 'suburb'` (not positional index), and tolerates the associative-map shape some Magento builds emit |
| Admin store config | `etc/adminhtml/system.xml` | Adds suburb field to Store Information (origin) |

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

**Rate request (checkout)**
1. Customer types suburb in checkout form (bound to `shippingAddress.custom_attributes.suburb`)
2. `set-shipping-information-mixin.js` copies it to `shippingAddress.extension_attributes.suburb` before the AJAX call
3. Magento sends rate estimation request with address data as JSON body
4. `AdditionalInfo::getSuburb()` walks `address.custom_attributes` and matches on `attribute_code === 'suburb'` — also tolerates the `{ suburb: { value: ... } }` associative-map shape
5. `BobGo::collectRates()` calls `getDestSuburb()` and includes it as `local_area` in the API payload

**Order push (post-placement)**
1. The quote address (carrying `extension_attributes.suburb`) is converted to an order address by Magento's `ToOrderAddress` converter
2. `ToOrderAddressPlugin::afterConvert` copies the suburb onto the order address (sets both the extension attribute and the raw `suburb` data key)
3. `OrderMapper::extractSuburb()` reads it back when building the outbound payload and emits as `delivery_address.local_area`; falls back to `city` if no suburb was captured

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

LBS → KG conversion happens **in `OrderMapper::normaliseWeightKg()`** at payload-build time. The order row itself is never mutated.

```php
private function normaliseWeightKg(float $weight): float
{
    if ($weight <= 0.0) {
        return 0.0;
    }
    $unit = $this->scopeConfig->getValue('general/locale/weight_unit', ScopeInterface::SCOPE_STORE);
    if (is_string($unit) && strtolower($unit) === 'lbs') {
        return $weight * self::LBS_TO_KG; // 0.45359237
    }
    return $weight;
}
```

**Why not a `beforeSave` plugin?** Older versions used `Plugin\AddWeightUnitToOrderPlugin::beforeSave` on `OrderRepositoryInterface`. The plugin had no "already converted" guard, and the order is saved multiple times in normal flow (initial placement, push success applies, webhook timestamp updates, reconciliation writes, etc.). Each save re-multiplied the stored item weight by `0.45359237`, silently corrupting the order. After four saves an item that started at 10 lb ended up stored as ~0.42. The plugin was removed in 1.1.0; conversion now happens only when building the outbound payload.

### Maximum Weight

`processAdditionalValidation()` enforces a **500 kg** maximum per item. Items exceeding this weight will cause the carrier to return an error or `false`.

---

## 13. Tracking Page

> **Status: Currently hidden/disabled** - The `enable_track_order` field has `showInDefault="0"` in system.xml.

### Route

`/bobgo/tracking/index` (defined in `etc/frontend/routes.xml`)

### Controller: `Controller/Tracking/Index.php`

1. Checks `carriers/bobgo/enable_track_order`. Disabled → redirect to `noroute`.
2. **GET** request → render the empty form. No API call. This is the only response shape for a casual visitor.
3. **POST** request:
   1. Validate `form_key` via `Magento\Framework\Data\Form\FormKey\Validator`. Missing/invalid → redirect back to the form. (CSRF protection.)
   2. Read `order_reference` from the form. Refuse to hit Bob Go with the raw value — instead, resolve it against this store's data first:
      - If the input matches an order's `increment_id`, pull the most recent tracking number off one of that order's shipments.
      - Otherwise, scan recently-bobgo'd orders (limit 100) for a shipment track whose `track_number` exactly matches the input.
      - No match → log + render empty page (no 404 leak; no Bob Go call).
   3. Only when a local match is found does the controller call `GET /v2/tracking?tracking_reference={resolved}` and register the first result as `shipment_data`.

This **anti-enumeration** layer matters because the endpoint is otherwise an unauthenticated proxy to the merchant's Bob Go account. `form_key` alone only stops CSRF; without the local-order check, anyone with valid form keys could fingerprint Bob Go tracking references against this store's API key.

Even with both layers, the standalone page should remain disabled until a merchant explicitly needs it. The default tracking surface is the popup in customer account → orders, which goes through Magento's normal access controls.

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
- **Only runs for Bob Go orders.** Guards on `getShippingMethod()` starting with `bobgo_`; orders shipped via DHL, UPS, flat rate, etc. are left alone. Earlier 1.0.x builds ran for every order — any other carrier whose description contained ` - ` got its label corrupted on placement.
- Null-safe: returns early if `getShippingMethod()` or `getShippingDescription()` is null (e.g. virtual orders).
- Extracts the part after the last ` - ` in the shipping description.
- Example: `"Bob Go - Delivery in 3 - 5 days - Standard Delivery"` → `"Standard Delivery"`
- If no ` - ` is found, returns the full description unchanged.

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

### ToOrderAddressPlugin

| | |
|---|---|
| **Target** | `Magento\Quote\Model\Quote\Address\ToOrderAddress::afterConvert` |
| **File** | `Plugin/Quote/ToOrderAddressPlugin.php` |
| **Scope** | Global (`etc/di.xml`) |

Carries the `suburb` value from the quote address to the order address as the order is being placed. Reads from (in order):

1. Quote address extension attribute `getSuburb()` (set by the checkout JS mixin)
2. Quote address custom attribute `suburb`
3. Quote address raw data key `suburb`

Writes both the order address extension attribute (`setSuburb()`) and the raw `suburb` data key so downstream code can read it whichever way it asks. Without this plugin the suburb is captured during checkout, persisted on the quote address, then silently dropped at the quote → order conversion.

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

Wraps the original `setShippingInformationAction` to copy the suburb from `shippingAddress.custom_attributes.suburb` (where the layout processor binds it) into `shippingAddress.extension_attributes.suburb` (where the server-side ToOrderAddress conversion looks for it). Without this step the suburb is rendered on the form but never reaches the server.

Tolerates the three `custom_attributes` shapes Magento builds emit:
1. Object map — `{ suburb: 'Sandton' }`
2. Object map of objects — `{ suburb: { value: 'Sandton' } }`
3. List of `{attribute_code, value}` entries — `[{ attribute_code: 'suburb', value: 'Sandton' }]`

A `customAttributes` (camelCase) variant is also checked, since some Magento builds expose the same data under that key.

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

### Table: `sales_shipment` (added columns)

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| `bobgo_fulfillment_id` | VARCHAR(128) | Yes | Bob Go fulfilment id. Stamped after creating a shipment so duplicate fulfilment webhooks past the `event_id` retention window can still be deduped. |

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
- `BOBGO_SYNC_LOG_EVENT_ID` on `event_id` (btree, non-unique — for lookup-by-event-id queries)
- `BOBGO_SYNC_LOG_ORDER_ID` on `order_id`
- `BOBGO_SYNC_LOG_CREATED_AT` on `created_at`

**Constraints:**
- **`BOBGO_SYNC_LOG_EVENT_ID_DIRECTION_UNQ`** — UNIQUE on `(event_id, direction)`. Load-bearing for race-safe webhook dedup: two concurrent deliveries of the same `event_id` can't both pass a check-then-insert race because the second `INSERT` violates the constraint and `SyncLogger::claimEventId()` returns `false`. NULL event ids are not part of the constraint (MySQL treats NULLs as not-equal), so unidentified deliveries log freely.

All schema changes are declared in `etc/db_schema.xml` and whitelisted in `etc/db_schema_whitelist.json`.

### Extension Attributes

Defined in `etc/extension_attributes.xml`:

| Interface | Attribute | Type | Purpose |
|-----------|-----------|------|---------|
| `Magento\Quote\Api\Data\AddressInterface`      | `suburb`         | string | Suburb captured at checkout (rate request + later carried to order address) |
| `Magento\Sales\Api\Data\OrderAddressInterface` | `suburb`         | string | Suburb on the persisted order address — set by `ToOrderAddressPlugin` |
| `Magento\Sales\Api\Data\OrderInterface`        | `bobgo_order_id` | string | Bob Go order tracking ID |

---

## 18. Dependency Injection

### `etc/di.xml` (Global)

**Interface Preferences:**

```xml
<preference for="BobGroup\BobGo\Api\OrderMapperInterface"
            type="BobGroup\BobGo\Service\OrderMapper" />
```

**OrderMapper Arguments:**

```xml
<type name="BobGroup\BobGo\Service\OrderMapper">
    <arguments>
        <argument name="productRepository" xsi:type="object">Magento\Catalog\Api\ProductRepositoryInterface</argument>
        <argument name="storeManager"      xsi:type="object">Magento\Store\Model\StoreManagerInterface</argument>
        <argument name="scopeConfig"       xsi:type="object">Magento\Framework\App\Config\ScopeConfigInterface</argument>
    </arguments>
</type>
```

`scopeConfig` is used to read `general/locale/weight_unit` for the LBS→KG conversion at payload-build time.

**Plugins on OrderRepositoryInterface:**

```xml
<type name="Magento\Sales\Api\OrderRepositoryInterface">
    <plugin name="bobgo_order_repository_plugin"
            type="BobGroup\BobGo\Plugin\OrderRepositoryPlugin" sortOrder="20" />
</type>
```

**Plugin on ToOrderAddress:**

```xml
<type name="Magento\Quote\Model\Quote\Address\ToOrderAddress">
    <plugin name="bobgo_to_order_address_suburb"
            type="BobGroup\BobGo\Plugin\Quote\ToOrderAddressPlugin" />
</type>
```

Carries the suburb extension attribute through the quote → order conversion (see §15).

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
   Webhook POST /bobgo/webhook/receive
   → Receive controller (HMAC verify + claimEventId)
   → FulfillmentService::processFulfillment()
   → Creates Magento shipment, stamps bobgo_fulfillment_id

4. TRACKING UPDATE
   Webhook POST /bobgo/webhook/receive (tracking/updated)
   → FulfillmentService::processTrackingUpdate()
   → Adds tracking number to the matching shipment (by track number), or
     to the last shipment as a fallback
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
2. **Idempotent fulfillments** - tracking-number check, fulfillment_id check, and a refusal-to-ship when the payload has items but none mapped (prevents an "unknown SKU" payload from blowing out into a full shipment).
3. **Graceful API failures** - `BobGo::uRates()` returns `null` on API error; `_getRates()` logs and returns empty result. The customer sees no rates rather than an error page. Timeouts are tight (8 s for rates, 5 s connect, 15 s elsewhere) so a Bob Go outage can't hang checkout.
4. **Webhook retry semantics** - `TransientWebhookException` from processing → controller releases the dedup claim, writes a failure log row with `event_id = NULL`, returns 500. Bob Go retries. Any other `\Throwable` → claim row stays, retries 200 at the dedup gate (operator must clear to replay).
5. **PII redaction** - `SyncLogger::PII_KEYS` scrubs customer and address fields before persistence. API error responses are capped at 512 B in `system.log` for the same reason.
6. **Sync log retention** - Rows older than 30 days are pruned by `Cron\PruneSyncLog` so the table stays bounded.

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
- Failure modes — missing secret, missing header, mismatch — all return 403 and log a `webhook_rejected` row in `bobgo_sync_log` with the body truncated to 256 bytes (so an unauthenticated attacker can't bloat the table via sustained traffic)
- After signature passes, the controller also gates on `isFulfillmentSyncEnabled()`. A merchant who has disabled sync but whose stale subscriptions are still firing gets 200 + no processing — operator intent is "stop touching orders," and Bob Go shouldn't retry against it.

The webhook secret is merchant-issued (never auto-generated by this extension) and stored encrypted under `carriers/bobgo/webhook_secret` via `Backend\Encrypted`.

### Admin Resync CSRF

The admin `Resync` action implements `Magento\Framework\App\Action\HttpPostActionInterface`, which makes Magento enforce form-key validation on POSTs and reject other methods. The admin order panel renders the button as a `<form method="post">` with a hidden `form_key`. A stray GET to the URL (e.g. crawled from browser history) is a no-op.

### Tracking Page (Hidden by default)

When `carriers/bobgo/enable_track_order` is on, the standalone tracking page at `/bobgo/tracking/index` is doubly gated:

1. POST + valid `form_key` (CSRF).
2. The submitted reference must match a local order increment_id OR a tracking number already recorded on a local shipment — otherwise no Bob Go call is made.

Without the local-order check, anyone with a valid form key could use the endpoint as a tracking-reference oracle against the merchant's Bob Go account. The field is hidden from the admin UI (`showInDefault="0"`) and should remain that way until a merchant explicitly opts in. See §13 for details.

### Channel Identifier on Outbound Calls

Every outbound API call carries `bobgo-channel-identifier: {store base URL}` so Bob Go can associate the call with the correct channel and refuse cross-channel access.

### PII in Logs

`SyncLogger` redacts a fixed list of PII keys (customer identifiers + address fields — see §10c) before persisting payloads. The API client caps error-response bodies in `system.log` at 512 bytes for the same reason.

### Input Validation

- Weight validation: Max 500 kg per item
- Country validation: Only ZA (South Africa) accepted; postcode required for ZA destinations (no longer silently cleared)
- Origin region: numeric `region_id` from store configuration is resolved to a region code (e.g. `GP`, `WC`) via `RegionFactory` before being sent
- Fulfillment idempotency: tracking-number OR fulfillment_id; refuse-to-ship when payload had items but none mapped
- Order existence: Fulfillments are rejected if the referenced order doesn't exist (permanent — 200, Bob Go doesn't retry)
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

**Status:** 143 tests / 257 assertions passing. PHPStan: 0 errors at level 2 (run with `--memory-limit=512M`).

### Test Files

| Test File | Tests |
|-----------|-------|
| `Api/BobGoApiClientTest.php` | HTTP client: GET/POST/PATCH/DELETE, error handling, auth + channel-id headers |
| `Block/System/Config/Form/Field/VersionTest.php` | Version display block |
| `Controller/Webhook/ReceiveTest.php` | Webhook controller: transient failure releases claim + logs `event_id=NULL`; success doesn't release; duplicate claim short-circuits to 200; disabled fulfillment sync skips entirely |
| `Helper/DataTest.php` | Helper functions, debug logging |
| `Model/Carrier/AdditionalInfoTest.php` | Request body parsing (suburb attribute_code matching, associative-map shape, company, phone) |
| `Model/Carrier/BobGoTest.php` | Rate collection, validation, weight conversion, formatting |
| `Model/Config/ApiConfigTest.php` | Configuration getters, environment URLs, feature flags |
| `Model/Source/FreemethodTest.php` | Free method source model |
| `Model/Source/GenericTest.php` | Generic source model base |
| `Observer/ConfigChangeObserverTest.php` | Config change reactions, connectivity tests |
| `Observer/ModifyShippingDescriptionTest.php` | Carrier filter + null safety on the description-rewrite observer |
| `Observer/OrderSaveObserverTest.php` | Order push/update triggering |
| `Plugin/Quote/ToOrderAddressPluginTest.php` | Suburb carries quote → order address (extension attr, custom attr, raw data fallback) |
| `Service/FulfillmentServiceTest.php` | Shipment creation, tracking updates, fulfillment-id + tracking-number idempotency |
| `Service/OrderMapperTest.php` | Order-to-payload mapping, status mapping, LBS→KG conversion, suburb resolution |
| `Service/OrderPushServiceTest.php` | Order POST/PATCH, sync-hash dirty-check, sync-log writes |
| `Service/ReconciliationServiceTest.php` | Reconciliation cron — gated by config, batched fetch, change-detection, API error handling |
| `Service/SyncLoggerTest.php` | Atomic claim/release, duplicate-key detection (AlreadyExistsException + raw SQLSTATE 23000), fail-open on unexpected DB errors, address-field PII redaction |
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

1. **South Africa Only** — `processAdditionalValidation()` rejects all non-ZA countries. To support other countries, this validation must be modified.

2. **Tracking Page Hidden** — `enable_track_order` is hidden in admin (`showInDefault="0"`). When enabled, the controller is doubly gated (form_key + local-order match) but the feature should still be considered experimental until a merchant explicitly opts in.

3. **Order Push is Synchronous** — `OrderSaveObserver` calls Bob Go inline on `sales_order_save_after`. API timeouts (15 s default; 5 s connect) bound the worst case, and the sync-hash dirty-check avoids redundant calls, but the first save is still inline. Async queue is on the roadmap.

4. **Single Carrier Instance** — One Bob Go carrier configuration per store. Multi-store setups share the carrier code `bobgo`.

5. **AdditionalInfo Direct Instantiation** — `AdditionalInfo` is created via `new AdditionalInfo()` in the `BobGo` constructor rather than through DI, making it harder to mock in tests.

6. **Registry Deprecation** — `TrackingBlock` and `Controller\Tracking\Index` use `Magento\Framework\Registry`, deprecated since Magento 2.3. Should migrate to view models or request parameters.

7. **No Rate Caching** — Every checkout address change triggers a new API call. The 8 s `rates-at-checkout` timeout caps the user-visible cost of that, but a cache layer would still be a win.

8. **`bobgo_order_ref` field-name guesswork** — `OrderPushService::applySuccess()` tries `response['reference']` then `response['order_ref']`. If Bob Go's actual response key for the immutable string ref is neither, `bobgo_order_ref` stays null forever. Worth verifying against sandbox.

9. **Reconciliation has no pagination** — `ReconciliationService::loadCandidateOrders()` uses `setPageSize(100)` with no offset. Stores with >100 active orders will leave the tail permanently stale until a webhook arrives.

10. **Tracking-page reference resolution paginates by 100** — When the customer pastes a tracking number (not an order increment_id), the controller scans up to 100 recently-Bob-Go'd orders to confirm it belongs to the store. Direct shipment_track queries aren't cleanly exposed via Magento repositories; acceptable while the feature is hidden by default, would need a real query for production use.

11. **Throwable-on-webhook keeps the claim** — Any exception other than `TransientWebhookException` leaves the dedup claim in the table, so retries return 200 at the dedup gate. Intentional (don't loop on crash bugs) but means an operator has to manually clear the claim row to allow a replay after fixing the underlying issue.

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
| `Controller\Webhook\Receive` | Unified webhook controller (HMAC verify, fulfillment-sync gate, atomic claim, route) |
| `Cron\Reconcile` | Hourly cron entry point — wraps `ReconciliationService::run()` |
| `Cron\PruneSyncLog` | Daily cron — prunes old sync log rows via `SyncLogRetentionService` |
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
| `Plugin\Checkout\Block\LayoutProcessorPlugin` | Adds suburb to checkout |
| `Plugin\OrderRepositoryPlugin` | Manages bobgo_order_id ext attr |
| `Plugin\Quote\ToOrderAddressPlugin` | Copies suburb from quote address → order address on conversion |
| `Service\FulfillmentService` | Creates shipments from fulfillments (stamps `bobgo_last_webhook` and `bobgo_fulfillment_id`) |
| `Service\OrderMapper` | Order→API payload transformation (emits `channel_ref_id`, normalises weight, resolves suburb) |
| `Service\OrderPushService` | POST/PATCH orders to Bob Go (sync-hash dirty-check); returns `bool` |
| `Service\ReconciliationService` | Hourly reconciliation — re-fetch authoritative shipments; two scoped queries (active + complete lookback) |
| `Service\SyncLogger` | Single writer for `bobgo_sync_log` + atomic claim/release dedup + PII redaction |
| `Service\SyncLogRetentionService` | Prunes `bobgo_sync_log` rows older than 30 days |
| `Service\TransientWebhookException` | Marker exception routing webhook failures to HTTP 500 (retry) |
| `Service\WebhookSignatureVerifier` | HMAC-SHA256 verification of inbound webhooks |
| `Service\WebhookSubscriptionService` | Manages webhook subscriptions |

### XML Configuration Files - Quick Reference

| File | Purpose |
|------|---------|
| `etc/module.xml` | Module definition, version, dependencies |
| `etc/config.xml` | Default config values |
| `etc/crontab.xml` | Hourly reconciliation + daily sync-log prune |
| `etc/di.xml` | DI preferences, plugins, arguments |
| `etc/events.xml` | Frontend event observers |
| `etc/adminhtml/events.xml` | Admin event observers |
| `etc/adminhtml/routes.xml` | Admin `bobgo` route (Resync action) |
| `etc/adminhtml/system.xml` | Admin configuration UI |
| `etc/extension_attributes.xml` | suburb (quote + order address), bobgo_order_id |
| `etc/db_schema.xml` | sales_order, sales_order_item, sales_shipment columns + `bobgo_sync_log` table (UNIQUE on event_id+direction) |
| `etc/db_schema_whitelist.json` | Declarative schema whitelist |
| `etc/acl.xml` | Access control list |
| `etc/frontend/di.xml` | Frontend checkout plugin |
| `etc/frontend/routes.xml` | Frontend `/bobgo` route (webhook + tracking) |
| `view/adminhtml/layout/sales_order_view.xml` | Injects Bob Go panel into admin order view |
| `view/adminhtml/templates/order/view/bobgo_info.phtml` | Bob Go admin panel template |
| `phpstan.neon` | PHPStan level 2 config |

---

*Last updated: 2026-05-26*
*Extension version: 1.1.0*
