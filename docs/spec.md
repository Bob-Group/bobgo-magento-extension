# Bob Go Magento 2 Extension - Developer Specification

> **Module:** `BobGroup_BobGo`
> **Namespace:** `BobGroup\BobGo`
> **PHP Compatibility:** ^7.4 || ^8.0 || ^8.2
> **Current Version:** 1.2.0 (source of truth: `composer.json`)
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
- [Appendix A — Endpoint reference](#appendix-a--endpoint-reference)
- [Appendix B — Address shape](#appendix-b--address-shape)
- [Appendix C — Open questions for Bob Go](#appendix-c--open-questions-for-bob-go)

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
| **Channel Identifier** | The store's `host[/path]` — base URL with the scheme stripped — sent on every outbound call via the `bobgo-channel-identifier` header, resolved in the order's store scope |
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
- `Magento_Backend` - Admin controller and blocks (sync-log page, config field)
- `Magento_Ui` - UI component grid for the sync log

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
│   ├── PushOrders.php              # Every minute — drains the order-push outbox
│   ├── Reconcile.php               # Hourly cron entry — reconciliation + webhook health check
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
│   ├── OrderResolution.php            # Value object: matched / no-reference / unresolved
│   ├── OrderResolver.php              # Maps an inbound webhook payload to a local order (never guesses)
│   ├── FulfilmentSyncService.php      # Re-fetches authoritative fulfilment state and reconciles locally
│   ├── OrderSyncPolicy.php            # Which orders Bob Go hears about, and what status to forward
│   ├── OrderSyncQueue.php             # Outbox between the save observer and the push cron
│   ├── RateCache.php                  # Rates-at-checkout cache (memo + TTL by address precision)
│   ├── ConnectionHealth.php           # Write-through credential state, fed by real API traffic
│   ├── DisplayOptionsMapper.php       # Product options -> Bob Go display_options
│   ├── InboundGuard.php               # Marks inbound-driven saves so they don't echo outbound
│   ├── StoreScope.php                 # Runs work in the order's own store scope
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
│           │   ├── shipping-rates-validation-rules.js  # Makes suburb an observable field
│           │   └── shipping-rates-validator.js          # (inert — see §16)
│           └── view/
│               ├── shipping-information-mixin.js       # Hides the empty "carrier - method" separator
│               └── shipping-rates-validation.js        # Registers rules + validator with Magento
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
| `carriers/bobgo/max_rates` | Integer | `20` | Ceiling on rates shown at checkout; overflow is logged |
| `carriers/bobgo/send_display_options` | Yes/No | `1` | Forward the variant / custom options / personalisation text the customer chose |
| `carriers/bobgo/display_options_blocklist` | Textarea | *(empty)* | Comma- or newline-separated option keys to withhold. Blocklist, not allowlist — a new product option flows without a settings visit |
| `carriers/bobgo/dimension_attribute_length` | Text | *(empty)* | Product attribute holding length in cm. Magento has no native dimension attributes |
| `carriers/bobgo/dimension_attribute_width` | Text | *(empty)* | Product attribute holding width in cm |
| `carriers/bobgo/dimension_attribute_height` | Text | *(empty)* | Product attribute holding height in cm |
| `carriers/bobgo/origin/company` | Text | *(empty)* | Collection-address override. Each field falls back to Store Information when blank |
| `carriers/bobgo/origin/street` | Text | *(empty)* | " |
| `carriers/bobgo/origin/suburb` | Text | *(empty)* | " |
| `carriers/bobgo/origin/city` | Text | *(empty)* | " |
| `carriers/bobgo/origin/region` | Text | *(empty)* | Province code, e.g. `GP` |
| `carriers/bobgo/origin/postcode` | Text | *(empty)* | " |
| `carriers/bobgo/origin/country_id` | Text | *(empty)* | Two-letter country code |
| `carriers/bobgo/suburb_label` | Text | *(empty → "Suburb")* | Checkout label for the suburb field |
| `carriers/bobgo/suburb_tooltip` | Text | *(empty → "Required for shipping accuracy")* | Checkout help text for the suburb field |
| `carriers/bobgo/enable_track_order` | Yes/No | *(hidden)* | Enable customer tracking page |
| `carriers/bobgo/price` | Decimal | `0.00` | Default shipping price |
| `carriers/bobgo/model` | String | `BobGroup\BobGo\Model\Carrier\BobGo` | Carrier model class |
| `carriers/bobgo/title` | String | `Bob Go` | Carrier title shown to customers |
| `carriers/bobgo/name` | String | `Bob Go` | Carrier name |
| `general/store_information/suburb` | Text | *(empty)* | Store origin suburb (added by this module) |

> `origin/*` sits in a nested config group, so its paths carry the extra segment.
> Getting that wrong (`carriers/bobgo/origin_city` vs
> `carriers/bobgo/origin/city`) silently reads nothing — a nested `<group>` in
> `system.xml` always contributes a path segment.

### Non-config state (`flag` table)

Some state is operational rather than configuration, so it lives in Magento's
`flag` table instead of `core_config_data`:

| Flag | Written by | Purpose |
|------|-----------|---------|
| `bobgo_connection_state` | `ConnectionHealth` | `valid` / `invalid` — folded from observed HTTP statuses |
| `bobgo_connection_checked_at` | `ConnectionHealth` | When that state last *changed* |
| `bobgo_webhook_health_checked_at` | `WebhookSubscriptionService` | Rate-limits the subscription health check to one conclusive run a day |
| `bobgo_reconcile_page` | `ReconciliationService` | Page cursor, so successive runs work through the whole population |

All four are removed by `Setup\Uninstall`.

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
│  OrderSaveObserver     → Queues an order push (no API call)       │
│  ConfigChangeObserver  → Tests connectivity on config save       │
│  ModifyShippingDescription → Cleans shipping label               │
│  OrderRepositoryPlugin → bobgo_order_id extension attribute      │
│  ToOrderAddressPlugin  → Carries suburb quote → order            │
├──────────────────────────────────────────────────────────────────┤
│              Inbound (Webhook) Surface                           │
│  Controller\Webhook\Receive  → HMAC verify → dedup → route       │
│  WebhookSignatureVerifier    → HMAC-SHA256 base64 (constant-time)│
│  SyncLogger                  → bobgo_sync_log writer + dedup     │
│  FulfillmentService          → Webhook payload → Magento ship    │
├──────────────────────────────────────────────────────────────────┤
│              Outbound + Reconciliation Surface                   │
│  OrderSyncQueue              → Outbox; observer writes, cron reads│
│  Cron\PushOrders             → Drains it every minute            │
│  OrderSyncPolicy             → Which orders, which statuses       │
│  OrderPushService            → POST/PATCH orders (sync-hash gated)│
│  OrderMapper + DisplayOptions→ Order → API payload transformation│
│  FulfilmentSyncService       → Authoritative refresh + shipments │
│  ReconciliationService       → Hourly batch over the above        │
│  WebhookSubscriptionService  → Subscribe/repair/unsubscribe       │
│  StoreScope                  → Runs all of it in the order's store│
├──────────────────────────────────────────────────────────────────┤
│              Admin Surface                                       │
│  Block\Adminhtml\Order\View\BobGoInfo → Order panel block        │
│  Controller\Adminhtml\Order\Resync    → Manual resync action     │
│  Controller\Adminhtml\SyncLog\Index   → Sync log grid            │
│  ConnectionStatus (config field)      → Credential health        │
│  FailedSyncMessage                    → Admin banner            │
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
3. **Event-ID Webhook Dedup** - Bob Go retries failed deliveries; `SyncLogger::claimEventId()` claims the event atomically under a unique index and short-circuits duplicates to a 200 so they don't re-process.
3b. **Acknowledge, Don't Reject** - Webhook subscriptions are account-wide, so this endpoint receives events for orders that aren't ours. Anything authentic and well-formed gets a 200 even when we do nothing with it, because Bob Go disables the whole subscription after three days without a successful delivery.
3c. **Never Guess an Order** - `OrderResolver` walks a strict reference ladder and refuses ambiguous matches. Magento's default `increment_id` sequence is identical across stores, so an order number alone can never establish ownership.
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
  - `bobgo-channel-identifier: {host[/path]}` — derived from `StoreManagerInterface::getStore()->getBaseUrl()` with the **scheme stripped** and the trailing slash trimmed. The scheme is omitted because a `:` in a header value breaks downstream parsers that split on the first colon. Note that the WooCommerce integration sends the full canonical URL here; the two disagree, and which Bob Go expects is an open question (Appendix C)
  - On background paths the header and the API key are resolved in the **order's** store scope via `Service/StoreScope.php` — cron has no store context, and the webhook endpoint resolves whichever store its single delivery URL maps to, so without that a multi-store order would be pushed into the wrong channel
- **Timeouts:**
  - `CURLOPT_TIMEOUT`: 8 s for `rates-at-checkout` (checkout-blocking — fast failure beats a slow success), 15 s default for every other endpoint.
  - `CURLOPT_CONNECTTIMEOUT`: 5 s. Stops a black-holed DNS / firewall from eating the whole request budget before bytes ever leave the box.
  - Fast-path endpoints are declared by name in `BobGoApiClient::FAST_PATH_ENDPOINTS`.
- **Base URL:** Determined by environment setting via `ApiConfig::getBaseUrl()`

**Error Handling:**
- HTTP status >= 400 throws `BobGoApiException` with status code, response body, and endpoint
- **Transport failures** — connect timeout, read timeout, DNS failure, TLS error — are caught and rethrown as `BobGoApiException` with `statusCode = 0` (the same convention as a missing API key). Magento's `Curl::doError()` raises these as a bare `\Exception`, which no caller up the stack catches; leaving them unwrapped turned a Bob Go outage into a 500 on the checkout shipping step
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
| `webhooks` | DELETE | Delete subscriptions in bulk, `{"ids": [...]}` | `WebhookSubscriptionService::unsubscribe()`, `verifyAndRepair()` |
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
  "declared_value": 399.98,
  "order_total_price": 349.98,
  "handling_time": 0
}
```

> `declared_value` is the **pre**-discount value of the shippable goods;
> `order_total_price` is the **post**-discount total, and it is what Bob Go
> evaluates free-shipping-over-X thresholds against. Both are required — sending
> only the former grants free shipping that wasn't earned and denies it to
> shoppers who did earn it. Builds before 1.2.0 sent a hardcoded
> `declared_value: 0` and no total at all, so those thresholds could not work.
>
> Item dimensions are still sent as 0 on the rate path. The order payload reads
> them from merchant-nominated product attributes; wiring the same into rates
> needs a collection load to avoid an N+1 per cart line.

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

When **Enable order push** is turned on, orders are sent to Bob Go — **asynchronously**.
The observer only queues; a cron a minute later does the HTTP.

### Why asynchronous

`sales_order_save_after` fires during checkout, on every admin order save, on
invoice creation, on shipment creation, and from our own webhook handlers. Doing
the API call inline meant all of those paid for it, and a slow or unreachable
Bob Go was paid for by the customer placing the order. It also meant a failure had
no retry: the order sat wrong until somebody re-saved it.

**A table, not Magento's message queue.** The queue needs consumer processes
running, and a stuck or disabled consumer means order push silently stops with
nothing to inspect. Cron is already a hard Magento requirement, and
`bobgo_order_sync_queue` answers "what is pending and why" with one `SELECT`.
Swapping the drain loop for a consumer later would not touch the enqueue side.

**A table, not a flag on `sales_order`.** The producer runs *inside* the order's
own save, so setting a column there would mean saving the order again from within
its own `afterSave` and re-entering every other module's observers with it.

### Flow

```
Order saved (sales_order_save_after — GLOBAL, so admin/cron/webhook too)
         │
         ▼
OrderSaveObserver
   ├── order push disabled / not configured      → return
   ├── InboundGuard says this save came FROM      → return  (don't echo Bob Go's
   │   Bob Go                                              own change back)
   ├── OrderSyncPolicy::shouldPush() == false     → return
   ▼
INSERT ... ON DUPLICATE KEY UPDATE into bobgo_order_sync_queue   (one row per order)
         │
         │   ... up to a minute later ...
         ▼
Cron\PushOrders (every minute)
         │
         ▼
queue->claim(50)  — rows whose next_attempt_at has passed
         │
         ▼
for each order id:
    load the order fresh
    ├── gone                                  → release (nothing to retry)
    ├── shouldPush() now false                → release  (re-checked here, because
    │                                                     a deferred job can run
    │                                                     after the order moved on)
    ▼
    StoreScope::forOrder()  — emulate the order's store, so the API key and the
    │                          bobgo-channel-identifier header come from the
    │                          order's store and not from cron's default store
    ▼
    has bobgo_order_id?
    ├── no  → POST /v2/orders
    └── yes → PATCH /v2/orders, unless the payload hash is unchanged
                   (hash match = no API call, no log row, reported as success)
    ▼
    statusToForward()?  → PATCH /v2/orders with {id, status}   (see below)
    ▼
    all succeeded ── yes ──▶ queue->release()
                  └── no  ──▶ queue->defer()   attempts+1, backoff 60s/5m/15m/1h,
                                               given up (and logged) after 10
```

### Which orders are pushed — `Service/OrderSyncPolicy.php`

Checked twice: once when queueing, and again inside the job, because a deferred
job can run after the order has moved on.

| Case | Pushed? | Why |
|------|---------|-----|
| Virtual / downloadable order | **Never** | No shipping address, so `delivery_address` would be `null`, the API would reject it, and the order would be marked failed and retried on every subsequent save — forever |
| Unlinked, state `new` / `processing` / `holded` / `complete` | Yes (POST) | |
| Unlinked, state `pending_payment` / `payment_review` | No | The sale isn't real yet; pushing on every abandoned card attempt fills the merchant's Bob Go account with orders that never ship |
| Unlinked, state `canceled` / `closed` | No | Nothing to fulfil, so don't create it just to cancel it |
| Already has `bobgo_order_id` | Yes (PATCH), any state | Bob Go still needs to hear about a change or a cancellation after the fact |

### Status forwarding

Only `cancelled` and `completed` are forwarded, and only via
`PATCH /v2/orders` carrying `{id, status}` — deliberately a separate call from the
ordinary update:

- the create POST accepts **no** status field at all;
- keeping `status` out of the routine payload means the catch-up PATCH wave that
  follows any payload-shape change can't re-assert a terminal status as a side
  effect.

`sales_order.bobgo_status_synced` records what was sent. Bob Go treats a repeated
`completed` as a 200 no-op, but completing an already-cancelled order is a **400**,
so the transition is tracked rather than relying on idempotency.

### On success

- `response['id']` → `bobgo_order_id`
- `response['reference']` or `['order_ref']` → `bobgo_order_ref` (when present)
- payload hash → `bobgo_sync_hash`
- `bobgo_sync_status = 'success'`, `bobgo_last_synced = now` (UTC)
- Bob Go line-item ids → `sales_order_item.bobgo_order_item_id`
- a row in `bobgo_sync_log` (`order_created` / `order_updated_outbound` / `status_updated`)

### On failure

- `bobgo_sync_status = 'failed'`, and **no hash is written**, so the order stays in
  the retry population
- error + payload to `bobgo_sync_log` with `success = 0` and the HTTP status
- the queue row is deferred with a backoff rather than dropped
- **a 2xx that yields no usable order id counts as a failure.** Recording it as
  synced orphans the order: reconciliation only looks at orders with a
  `bobgo_order_id`, webhooks can't resolve to it, and the dirty check suppresses
  every future PATCH. It would sit invisible and never retried.
- the admin sees a banner counting orders stuck in `failed`
  (`Model\AdminNotification\FailedSyncMessage`) — since the push is a background
  job there is no request left to attach an error message to

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
  "note": "Leave at the back door",
  "total_tax": 19.50,
  "total_discount": 50.00,
  "date_placed_on_channel": "2026-07-30T09:15:00+00:00",
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
      "channel_ref_id": 67890,
      "description": "Product Name",
      "sku": "PROD-001",
      "unit_price": 199.99,
      "qty": 2,
      "unit_weight_kg": 1.5,
      "unit_length_cm": 30.0,
      "unit_width_cm": 20.0,
      "unit_height_cm": 10.0,
      "channel_image_url": "https://store/media/catalog/product/.../image.jpg",
      "display_options": [
        {
          "key": "colour",
          "value": "50",
          "display_key": "Colour",
          "display_value": "Pure Hazel"
        }
      ]
    }
  ]
}
```

`note`, `total_tax`, `total_discount`, `date_placed_on_channel`, the three
`unit_*_cm` fields and `display_options` are **omitted when empty or zero** rather
than sent as blanks, so the sync hash doesn't churn on fields a store never
populates.

Update payloads add `id` (the Bob Go order id) at the top level. Line items that already have a Bob Go id from a previous response carry `id` on the line.

### `display_options`

What the customer actually chose — the variant, the custom options, the
personalisation text — so it reaches the Bob Go picking list. Built by
`Service/DisplayOptionsMapper.php` from the order item's `product_options`, which
Magento spreads across `attributes_info` (configurable variants), `options` (custom
options) and `bundle_options`. `info_buyRequest` lives in the same array and is
deliberately ignored: internal request state, not something the customer saw.

Each entry carries the raw pair **and** the human pair. Magento has no slug
equivalent of a WooCommerce taxonomy key, so `key` is derived from the label and
`value` prefers Magento's recorded `option_value`.

Rules, each inherited from a WooCommerce lesson:

- Duplicate keys are **never merged** — two selections sharing a key are two
  selections, and joining slug values corrupts them.
- NUL bytes are stripped from raw values; Bob Go's column is PostgreSQL JSONB,
  which rejects them outright.
- Display values are stripped of markup, entity-decoded, cleared of control
  characters and whitespace-collapsed, so markup from a rich-text option doesn't
  land in a picking list.
- 30 entries per item, 500 characters per field, with an explicit
  `bobgo_truncated` marker rather than silent loss.
- Merchant control is a **blocklist**, not an allowlist, so a newly added product
  option flows without a settings visit. Master toggle defaults to on.

### Payment Status Mapping

Evaluated in this order — the first match wins:

| Condition | Bob Go Payment Status |
|-----------|----------------------|
| `TotalRefunded > 0` | `refunded` |
| state is `pending_payment` or `payment_review` | `pending` |
| `TotalDue <= 0` | `paid` |
| *(otherwise)* | `unpaid` |

`refunded` is checked first deliberately: an order refunded in full is not "paid",
and reporting it as paid invites a shipment for something the customer got their
money back for. `pending` distinguishes awaiting an offline payment or a gateway
review from a customer who simply hasn't paid.

### Important Notes

- Configurable **parents** are skipped; the simple child is sent with the parent's price mirrored onto it (the child's `getPrice()` is 0)
- `channel_ref_id` on the order is the Magento `entity_id` (NOT `increment_id`)
- `channel_ref_id` on each line item is the Magento `item_id`
- Update payloads include the `id` field (Bob Go order id) at the top level
- `updateOrder()` is a no-op (no API call, no log entry) when the payload hash matches `bobgo_sync_hash` — a repeated save with no material change does nothing
- Errors are logged + recorded in `bobgo_sync_log` but **never thrown** to the caller — order saving is not blocked by push failures
- `pushOrder()`, `updateOrder()` and `pushStatus()` return `bool`. `Cron\PushOrders` uses the result to decide release-or-defer; the admin Resync controller uses it to surface real success/failure to the operator instead of always claiming "triggered"
- `local_area` is read from the order shipping address's `suburb` extension attribute (set by `ToOrderAddressPlugin`) — falls back to `city` when the suburb isn't populated
- Item weights are normalised to kilograms by `OrderMapper::normaliseWeightKg()` at payload-build time and **rounded to one decimal**, matching what Bob Go persists server-side. The order row itself is never mutated; see §12 for the failure mode this fixes
- `buyer_selected_service_code` is omitted for the free-shipping sentinel method (see §7), which is a local method code rather than anything Bob Go can resolve
- Products are loaded once per payload build and memoised — the image URL and the dimensions both need the product, so a ten-line order was doing twenty loads
- `bobgo_order_ref` is persisted from the API response (`response['reference']` or `response['order_ref']`) — the schema column was unused before 1.1.0

---

## 9. Fulfillment Sync

Fulfillment sync creates Magento shipments when orders are fulfilled in Bob Go.

**Webhooks are treated as triggers, not as data.** Every inbound signal — a
`fulfillment/created` webhook, a `tracking/updated` webhook, the hourly
reconciliation tick, the admin Resync button — funnels into one method,
`FulfilmentSyncService::syncOrder()`, which re-fetches the authoritative state from
`GET /v2/order-fulfillments` and reconciles local state against it. Nothing patches
fulfilment state from a webhook body.

Three things follow from that single decision:

- **Out-of-order and duplicate deliveries stop mattering.** `tracking/updated`
  overtaking `fulfillment/created` used to force a 500-and-retry dance, because the
  shipment it wanted to annotate didn't exist yet. Either delivery now creates
  whatever is missing.
- **Lost webhooks self-heal.** Reconciliation runs the identical code path, so a
  delivery that was never processed — order on hold at the time, an unresolvable
  reference, an exception — is picked up within the hour. Before 1.2.0 the webhook
  was the *only* path that could create a Magento shipment, so a dropped one left
  an order shipped in Bob Go and never shipped in Magento, with no alert and no
  recovery.
- **Cancelled fulfilments are excluded.** Bob Go retains cancelled shipment
  records; counting them as shipped is what pinned orders on "shipped" forever on
  the WooCommerce integration.

Every accepted fulfillment / tracking webhook also stamps `sales_order.bobgo_last_webhook` with the current UTC timestamp so operators can see when Bob Go last contacted the store for that order.

### `FulfilmentSyncService::syncOrder()`

```
syncOrder(OrderInterface $order, array $webhookItems = [])
         │
         ▼
order has bobgo_order_id?  ── no ──▶ return false
         │ yes                        (NO LINK, NO REQUEST — never substitute a
         ▼                             Magento id into Bob Go's id namespace)
GET /v2/order-fulfillments?order_id={bobgo_order_id}
         │
         ├── BobGoApiException ──▶ log + reconciliation_fetched row (success=0), return false
         ▼
normalise the response
  (tolerates order_fulfillments / fulfillments / shipments / data wrappers, or a
   bare list; each entry flat or nested under shipment / order_fulfillment)
         │
         ▼
full-replace sales_order.bobgo_shipments — but ONLY when the value changed, so a
quiet tick doesn't touch the order row (a write would re-fire every observer)
         │
         ▼
for each fulfilment:
    ├── status contains cancel/failed/rejected      → skip
    ├── shipment already exists (by stamped         → refresh a placeholder courier
    │   bobgo_fulfillment_id, then tracking number)   title, then done
    ├── no tracking number AND no fulfilment id     → skip (nothing to dedup on)
    ├── order->canShip() == false                   → warn and skip; reconciliation
    │                                                 retries hourly
    ▼
    which items does this fulfilment cover?   (see below)
    ▼
    ShipOrderInterface::execute(order, items, notify, false, null, tracks)
    ▼
    stamp bobgo_fulfillment_id on the new shipment row, so later duplicate events
    dedup against it even after webhook event_id retention has expired
```

A failure from `ShipOrderInterface` is wrapped in `TransientWebhookException`: on
the webhook path the controller turns that into a 500 so Bob Go retries; on the
cron path `reconcileOrder()` catches it per order so one bad order can't end the
batch.

### Item scope — never guess "everything"

An empty item list means **ship the whole order** to `ShipOrderInterface`, so
defaulting to it when the scope is simply unknown silently closes a partly
fulfilled order. The resolution order is:

1. Items enumerated on the authoritative fulfilment record — use them.
2. Otherwise, items from the webhook body if this call came from one.
3. Otherwise, ship everything **only if Bob Go reports exactly one live fulfilment
   for the order** — the overwhelmingly common case, and the one where
   "everything" is right by definition. Logged at info.
4. Otherwise refuse, and log an error.

A fulfilment that *names* items none of which match the order is also refused
(logged at error). Unlike the old webhook-payload path this is **not** transient:
the authoritative record won't change on a retry.

Items are matched to Magento lines by Bob Go `channel_ref_id` → stored
`bobgo_order_item_id` → SKU, the SKU fallback popping from a per-SKU queue so
duplicate SKUs on one order don't collapse onto a single line.

> **Unverified:** which key the fulfilments response uses for its item array.
> `ITEM_KEYS` tolerates `items` / `order_items` / `fulfillment_items` /
> `line_items`. This is the first thing to confirm against a live sandbox — see
> Known Limitations #15.

### Fulfillment Webhook Payload Structure

The real `fulfillment/created` shape. Note that the top-level `id` is the
**fulfilment** id — the Bob Go *order* id arrives as `order_id`:

```json
{
  "channel_ref_id": "12345",
  "channel_order_number": "100000001",
  "order_id": 987,
  "id": 4321,
  "method_reference": "TRACK123456",
  "courier_name": "The Courier Guy",
  "order_items": [
    {
      "channel_ref_id": 67890,
      "sku": "PROD-001",
      "fulfilled_qty": 1
    }
  ]
}
```

> Earlier revisions of this document described a `fulfillment_id` /
> `tracking_numbers[]` / `line_items[]` shape. That was never what the code
> read, and never what Bob Go sends.

### The webhook handlers — `Service/FulfillmentService.php`

Deliberately thin. Each one records the webhook and hands off to the refresh:

| Topic | What the handler adds beyond the refresh |
|-------|------------------------------------------|
| `fulfillment/created` | Passes the payload's `order_items` through as the item-scope fallback |
| `tracking/updated` | Adds the human-readable checkpoint text to order history — the one thing only the payload has |
| `order/updated` | Cancels the Magento order when `status === cancelled`, then re-baselines the sync hash (see §10) |

The last-webhook timestamp and any order-history comment are written in a **single**
save. Every order save re-fires `sales_order_save_after` and therefore the outbound
push observer, so saving once per concern made a webhook three times as expensive
for no benefit.

On `tracking/updated` the top-level `id` is the **tracking-reference string** and
the payload carries no Bob Go order id at all — see the resolution ladder in §10 for
why reading it as one corrupts the link.

---

## 10. Webhook System

### Inbound Webhook Endpoint

**Route:** `POST /bobgo/webhook/receive`
**Access:** Anonymous (CSRF disabled — auth is via HMAC, not Magento's CSRF token)
**Defined in:** `etc/frontend/routes.xml` + `Controller/Webhook/Receive.php`

**Topic resolution order:**
1. `topic` in the JSON body — Bob Go's primary channel
2. `X-Bobgroup-Topic`, `X-BobGo-Topic`, `X-Webhook-Topic`, `X-Topic` headers
3. Payload-shape inference (`shipment_tracking_reference` / `checkpoints` → `tracking/updated`; `method_reference` + `order_items` → `fulfillment/created`)

**Event id resolution order:**
1. `event_id` in the JSON body
2. `Bobgo-Webhook-Event-Id` header
3. `Bob-Go-Request-Id` header
4. `X-Request-Id` header — last resort only. CDNs, load balancers and nginx
   commonly stamp this with a fresh value per request; preferring it would give
   each retry of one event a different id and silently defeat dedup.

### Response policy

**This is the most consequential thing in the inbound path.** Bob Go's delivery
layer counts **any non-2xx as a delivery failure and disables the entire
subscription after three days without a success** — emailing the merchant only;
the integration is never told. And subscriptions are **account-wide**, so this
endpoint receives every event on the merchant's Bob Go account: manual
shipments, CSV imports, other channels' orders. A quiet trading period in which
foreign traffic is the only traffic is therefore enough to silently kill
fulfilment sync for the whole store.

So 4xx/5xx is reserved for input that is malformed or unauthentic, and for our
own transient failures. "Not one of ours" is a 200.

| Outcome | Status | Sync-log row |
|---|---|---|
| Processed | 200 | `fulfillment_received` / `tracking_updated` / `order_updated_inbound`, success |
| Signature missing/invalid | 403 | `webhook_rejected` |
| Unparseable body, no resolvable topic | 400 | `webhook_rejected` |
| Fulfillment sync disabled | 200 | none |
| Known topic, **no order reference at all** | 200 `{"status":"ignored"}` | **none** — the volume would drown the log |
| Known topic, reference **matched no local order** | 200 `{"status":"ignored","reason":…}` | `webhook_ignored` |
| Unknown topic | 200 | `webhook_unknown_topic` |
| Our own transient failure | 500 (Bob Go retries) | `webhook_received` |
| Unexpected `\Throwable` | 500 | `webhook_received`, claim retained |

> The unknown-topic case deliberately diverges from the WooCommerce integration,
> which returns 400. An unhandled topic is not malformed input, and under
> account-wide delivery a 4xx there burns the subscription's success budget for
> no reason.

### Order resolution — never guess

`Service/OrderResolver.php` decides which local order an inbound payload refers
to, and its outcome (`OrderResolution`) decides the HTTP status above.

Magento makes this sharper than it is on other platforms: **every store is
handed the same `increment_id` sequence by default** (`000000001`,
`1000000001`…), so "this order number exists here" is no evidence at all that an
account-wide event belongs to this store. A lookup that falls back to "some
order that looks close enough" attaches a stranger's shipment to a real customer
order, overwrites its tracking, and marks it synced so it is never pushed at
all. That has happened in production on the WooCommerce integration.

The ladder, in order:

| # | Key | Matched against | Notes |
|---|---|---|---|
| 1 | `channel_ref_id` | `entity_id` | Authoritative **and terminal** — if present we match on it or give up. Requires one corroborating field (see below). |
| 2 | Bob Go order id | `bobgo_order_id` | `order_id` on `fulfillment/created`; top-level `id` on `order/updated` only. |
| 3 | `order_ref` | `bobgo_order_ref`, then `bobgo_order_id` | Older payload shapes put the numeric id here. |
| 4 | `channel_order_number`, `order_number`, `custom_order_name` | `increment_id` **only** | Last resort. Tolerates a leading `#`. |

Invariants at every rung:

- **Exactly one match required.** More than one → refuse and log.
- **Never re-point an order already linked to a different Bob Go order.**
- **Ownership cross-check on rung 1.** `entity_id`s are unique per store but not
  per Bob Go account, so the id alone isn't proof: either the stored
  `bobgo_order_id` must agree with the payload's, or the order number must match.
- **Never match an order number against a stored Bob Go id.** Different
  namespaces; matching across them is a hijack.
- **Verify the row carries the value we filtered on.** Magento can silently
  ignore a filter on an attribute it doesn't recognise and return an unfiltered
  page, whose first row looks exactly like a good match. The tripwire in
  `findExactlyOneBy()` turns that class of bug into a logged refusal.

A rung-4 match on an order with no stored link is accepted but logged at
**warning** level — it's legitimate for orders pushed before `channel_ref_id`
echo, and it's also the shape a mis-link takes.

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
  403 + log webhook_rejected         json_decode(body) ── not an array → 400 + webhook_rejected
   (body truncated to 256 B,              │
    event_id NULL)                        ▼
                                     apiConfig.isFulfillmentSyncEnabled()?
                                          │ no  → 200 "fulfillment sync disabled" (no log row)
                                          │ yes
                                          ▼
                                     Resolve topic (body → headers → shape)
                                          │  none → 400 + webhook_rejected
                                          │  not a known topic → 200 + webhook_unknown_topic
                                          ▼
                                     OrderResolver::resolve(data, topic)
                                       ┌──────────┼───────────────┐
                                       │          │               │
                              NO_REFERENCE   UNRESOLVED        MATCHED
                                       │          │               │
                                       ▼          ▼               ▼
                              200 "ignored"  200 "ignored"   SyncLogger::claimEventId()
                              (no log row)   + webhook_          ┌──────┴───────┐
                                             ignored             │ false (dup)  │ true
                                                                 ▼              ▼
                                                        200 "duplicate"    Route to handler
                                                                                │
                                                        ┌───────────────────────┼───────────────┐
                                                        ▼                       ▼               ▼
                                              fulfillment/created      tracking/updated   order/updated
                                                        │                       │               │
                                                        ├── success ────────────┴───────────────┴── 200,
                                                        │                              log success=true with order_id
                                                        │                              (upgradeClaim → success=1)
                                                        │
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

Two properties of that flow are load-bearing:

**1. Failure and ignored rows are written with `event_id = NULL`.** Anything
written with a non-null `event_id` occupies the `UNIQUE (event_id, direction)`
slot permanently, so the next delivery of that event fails `claimEventId()` and
is answered "duplicate, ignored" — dropped for good. This applies to the 403 and
400 branches too, not just the transient one: the classic trigger is a merchant
who enables fulfilment sync **before** pasting the webhook secret (the field sits
below the toggle in the admin UI, and the same save creates the subscriptions).
Every delivery in that window is 403'd, and without this rule none of the retries
can ever land. The event id still travels inside the logged payload so operators
can trace it.

**2. Resolution happens before the claim.** Routine foreign traffic therefore
leaves no trace at all — no claim row, no log row.

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
| `webhook_rejected`      | Signature missing/invalid/secret unset, body unparseable, or no resolvable topic (body truncated to 256 B, `event_id` NULL) |
| `webhook_received`      | Transient/unexpected failure log (`event_id` intentionally NULL) |
| `webhook_ignored`       | Authentic, well-formed, carried an order reference — but it matched no local order. 200 returned; `success=false` and the reason recorded, because this is the shape an attempted mis-link takes |
| `webhook_unknown_topic` | Topic resolved but not one we handle — 200 returned, `success=false` so operators can grep for it |
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
2. `DELETE /v2/webhooks` with `{"ids": [...]}` — **one bulk call**, not one per
   subscription. Earlier revisions of this document described a path-style
   `DELETE /v2/webhooks/{id}`; bulk-by-ids is what the code has always sent and
   what the WooCommerce integration's endpoint reference confirms.

The delivery URL is built from `StoreManagerInterface::getStore()->getBaseUrl()` + `bobgo/webhook/receive`.

---

## 10a. Reconciliation

`Service/ReconciliationService.php` is the safety net that catches webhooks Bob Go
retried-out-of or never delivered. Since 1.2.0 it owns only the **batch** — which
orders to look at and how many. The per-order work is
`FulfilmentSyncService::syncOrder()`, deliberately the same code path the webhook
handlers use, which is what makes this a genuine safety net rather than a display
refresh: a fulfilment whose webhook was never processed gets its Magento shipment
created here.

### Cron schedule

`etc/crontab.xml`:

```xml
<job name="bobgo_push_orders" instance="BobGroup\BobGo\Cron\PushOrders" method="execute">
    <schedule>* * * * *</schedule>
</job>
<job name="bobgo_reconcile_fulfillments" instance="BobGroup\BobGo\Cron\Reconcile" method="execute">
    <schedule>0 * * * *</schedule>
</job>
<job name="bobgo_prune_sync_log" instance="BobGroup\BobGo\Cron\PruneSyncLog" method="execute">
    <schedule>15 3 * * *</schedule>
</job>
```

`Cron\Reconcile::execute()` is a thin wrapper that swallows unexpected exceptions
and delegates to `ReconciliationService::run()`.

### Selection criteria

`loadCandidateOrderIds()` issues **two scoped queries** and merges the results
(deduped, capped at `BATCH_SIZE = 100`). One query can't express the intent with
`SearchCriteriaBuilder` because filter groups AND together — a single `updated_at`
filter would also exclude long-stuck active-state orders, which are exactly what
reconciliation exists for.

| Query | Filter | Reason |
|-------|--------|--------|
| Active states | `bobgo_order_id IS NOT NULL` AND `state IN (new, processing, holded)` | Always included, regardless of age. Stuck orders need reconciling. |
| Completed lookback | `bobgo_order_id IS NOT NULL` AND `state = complete` AND `updated_at >= now() - 14 days` | Catches late tracking checkpoints (proof of delivery, etc.) without pulling every historical order in. |

### Paging

Both queries are paged by a shared cursor in the `flag` table
(`bobgo_reconcile_page`). Successive runs advance through the population and reset
on a short page.

Without it — and this was the behaviour up to 1.1.0 — both queries used
`setPageSize(100)` with no offset, so a store with more than 100 active orders
re-scanned the same first page every hour and left the tail permanently stale.

The two scopes share one cursor, which means the (much smaller, 14-day-bounded)
complete set is only revisited when the cursor is back on page 1. Acceptable for a
safety net, and far better than never reaching the active tail at all.

### Behaviour per order

1. The order is **reloaded fresh inside the loop**, not reused from the objects the
   batch query returned. A webhook can relink an order mid-run, and refreshing
   under a stale link would write another order's fulfilments onto it.
2. `StoreScope::forOrder()` emulates the order's store, because cron has no store
   context and would otherwise resolve the default store's API key and channel
   identifier.
3. `FulfilmentSyncService::syncOrder()` does the work (§9).
4. Exceptions are caught **per order**, so one bad order can't end the batch.

### Webhook subscription health check

`run()` also calls `WebhookSubscriptionService::verifyAndRepair()`, internally
rate-limited to one **conclusive** check per day via the
`bobgo_webhook_health_checked_at` flag.

This is the only way the integration ever discovers that Bob Go disabled its
subscription — that happens after three days of failed deliveries, and only the
merchant is emailed. The check:

- treats a subscription row with **no `status` field at all** as active, to avoid
  churning a repair on every run;
- **deletes before creating** when re-registering, or repairs stack duplicates;
- **respects a deliberate disconnect** — a merchant who turned fulfilment sync off
  is not "broken", and conflating the two permanently disabled self-healing on the
  WooCommerce integration;
- does **not** stamp its flag on an inconclusive fetch, so one transient failure
  doesn't cost a day of self-healing;
- purges subscriptions pointing at a delivery URL this store no longer serves —
  notably the `Magento_Webapi` REST route that 1.0.x registered, which 404s on
  every delivery and counts against the same three-day window.

### Skip conditions

`run()` exits immediately when `isFulfillmentSyncEnabled()` or `isConfigured()` is
false. `syncOrder()` returns without calling the API when the order has no
`bobgo_order_id`.

### Manual invocation

`reconcileOrder()` is `public` and is reused by the admin "Resync" button on the
order detail page (see [Admin Order Panel](#10b-admin-order-panel)).

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
| `Service/SyncLogger.php` | Single writer — `logInbound`, `logOutbound`, `claimEventId`, `releaseEventIdClaim` |
| `Service/SyncLogRetentionService.php` | Daily prune (rows older than 30 days; preserves active `webhook_claim` rows) |
| `Cron/PruneSyncLog.php` | Cron entry point — wraps the retention service |

### Event types (constants on `Model\SyncLog`)

| Constant | Value | Direction | Notes |
|----------|-------|-----------|-------|
| `EVENT_WEBHOOK_CLAIM`         | `webhook_claim`         | inbound  | Sentinel row inserted by `claimEventId()`. Upgraded in place to the real outcome on success. |
| `EVENT_WEBHOOK_RECEIVED`      | `webhook_received`      | inbound  | Used for transient/unexpected processing failures (with `event_id = NULL` so retries can re-claim). |
| `EVENT_WEBHOOK_REJECTED`      | `webhook_rejected`      | inbound  | Signature or body parse failure. Payload truncated to 256 B before persistence. |
| `EVENT_WEBHOOK_IGNORED`       | `webhook_ignored`       | inbound  | Carried an order reference that resolved to no local order. 200 returned, `success = false`, reason recorded in the payload. |
| `EVENT_WEBHOOK_UNKNOWN_TOPIC` | `webhook_unknown_topic` | inbound  | Topic resolved but isn't one of the known routes (`fulfillment/created`, `tracking/updated`, `order/updated`). 200 returned, `success = false` so operators can grep. |
| `EVENT_FULFILLMENT_RECEIVED`  | `fulfillment_received`  | inbound  | |
| `EVENT_TRACKING_UPDATED`      | `tracking_updated`      | inbound  | |
| `EVENT_ORDER_UPDATED_INBOUND` | `order_updated_inbound` | inbound  | `order/updated` webhook acknowledged. No local mutation yet — reserved for future field-mapping work. |
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
        $weight = $weight * self::LBS_TO_KG; // 0.45359237
    }
    // One decimal, matching what Bob Go persists server-side. Sending more
    // precision than the server keeps means the value we send and the value it
    // stores differ, which matters the moment anything compares them.
    return round($weight, 1);
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
   2. Read `order_reference` **and `email`**. Both are required. Refuse to hit Bob Go
      with the raw value — resolve it against this store's data first:
      - Order number **plus matching `customer_email`** → one precise row; take a
        tracking number off that order's shipments.
      - Otherwise treat the input as a tracking number and walk **that customer's
        own** Bob Go orders, newest first, capped at 50.
      - No match → log + render the empty page (no 404 leak, no Bob Go call).
   3. Only on a local match does the controller call
      `GET /v2/tracking?tracking_reference={resolved}` and register the first result
      as `shipment_data`.

**Why the email is mandatory.** An order number is not a secret: Magento hands every
store the same sequence starting at `000000001`, so accepting one alone turned this
page into a way to read any customer's shipment status and checkpoint locations by
counting upwards. This matches how Magento's own guest order lookup works. Up to
1.1.0 the order number alone was enough.

The tracking-number fallback also had two bugs worth recording, both fixed in 1.2.0:
it applied **no sort order at all**, so `setPageSize(100)` walked the store's
*oldest* hundred Bob Go orders — past a hundred it could never match anything — and
it lazy-loaded a shipment collection per order, so a form-key-only POST cost 100+
queries. Scoping to one customer's orders fixes both.

Even so, the standalone page should stay disabled until a merchant explicitly needs
it. The default tracking surface is the shipment popup in customer account → orders,
which goes through Magento's normal access controls.

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
| **Scope** | **Global** (`etc/events.xml`) — a root `events.xml` is not frontend-only, so this also runs in adminhtml, cron, the webhook endpoint and REST |

**Behavior:** it queues, and nothing else. No API call is made here — see
[Order Push](#8-order-push) for why, and for the job that drains the queue.

1. Returns unless `isOrderPushEnabled()` and `isConfigured()`.
2. Returns if `InboundGuard` says this save was itself driven by an inbound Bob Go
   webhook — no point sending Bob Go's own change back.
3. Returns if `OrderSyncPolicy::shouldPush()` is false (cheap gate so the queue
   doesn't fill with orders the job would only discard; the job re-checks anyway).
4. Otherwise inserts one row into `bobgo_order_sync_queue`, idempotently.

Everything is wrapped in `try/catch (\Throwable)`: order saving is never blocked by
Bob Go.

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

Two mixins:

```javascript
'Magento_Checkout/js/action/set-shipping-information': {
    'BobGroup_BobGo/js/action/set-shipping-information-mixin': true
},
'Magento_Checkout/js/view/shipping-information': {
    'BobGroup_BobGo/js/view/shipping-information-mixin': true
}
```

### set-shipping-information-mixin.js

Copies the suburb from `shippingAddress.custom_attributes.suburb` (where the layout
processor binds it) into `shippingAddress.extension_attributes.suburb` (where the
server-side `ToOrderAddress` conversion looks for it). Without this step the suburb
is rendered on the form but never reaches the server.

Tolerates the three `custom_attributes` shapes Magento builds emit:

1. Object map — `{ suburb: 'Sandton' }`
2. Object map of objects — `{ suburb: { value: 'Sandton' } }`
3. List of `{attribute_code, value}` entries

A `customAttributes` (camelCase) variant is also checked.

### shipping-information-mixin.js

`_formatRates()` deliberately leaves the carrier title empty so checkout shows only
the service name. Magento composes the review-step label as
`carrier_title - method_title`, which with an empty carrier title renders a leading
` - `. This mixin drops the separator when either half is empty.

### shipping-rates-validation-rules.js

Declares `postcode`, `country_id`, `city` and `suburb` as required.

The **useful** effect is not validation: Magento derives its list of *observable*
fields from the registered rules, so naming `suburb` here is what makes editing the
suburb re-trigger rate collection.

### shipping-rates-validator.js — inert, and worth knowing why

It checks `address['suburb']`, but `validateFields()` flattens the form data with
the `shippingAddress.` prefix stripped, so the key is actually
`custom_attributes.suburb`. The lookup can never succeed.

It also can never *block* anything: Magento aggregates carrier validators with
`validators.some(...)`, pre-seeded with its own `defaultValidator`, so one
always-false validator changes nothing. Harmless, and left in place because the
rules registration next to it does real work — but it is not doing what its name
suggests.

> `view/frontend/web/js/model/set-shipping-information.js` was removed in 1.2.0. It
> was superseded by the action mixin and had no consumer.

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
| `bobgo_status_synced` | VARCHAR(32) | Yes | Last order status forwarded to Bob Go (`cancelled` / `completed`), so a transition isn't re-sent |

### Table: `sales_order_item` (added columns)

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| `bobgo_order_item_id` | VARCHAR(255) | Yes | Bob Go's internal line item id, returned from POST `/v2/orders` |

### Table: `sales_shipment` (added columns)

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| `bobgo_fulfillment_id` | VARCHAR(128) | Yes | Bob Go fulfilment id. Stamped after creating a shipment so duplicate fulfilment webhooks past the `event_id` retention window can still be deduped. |

### Table: `bobgo_order_sync_queue` (new in 1.2.0)

The outbox between `OrderSaveObserver` and `Cron\PushOrders`. See
[Order Push](#8-order-push) for why it is a table rather than the message queue or a
flag on `sales_order`.

| Column | Type | Nullable | Description |
|--------|------|----------|-------------|
| `entity_id` | INT UNSIGNED (identity) | No | Primary key |
| `order_id` | INT UNSIGNED | No | Magento `sales_order.entity_id` |
| `attempts` | SMALLINT UNSIGNED | No | Failed attempts so far; drives the backoff, given up at 10 |
| `next_attempt_at` | TIMESTAMP | Yes | Do not attempt before this time (`NULL` = immediately) |
| `created_at` | TIMESTAMP | No | Default `CURRENT_TIMESTAMP` |

**Constraints:** UNIQUE on `order_id`, which is what makes enqueueing idempotent —
an order saved ten times in one request is pushed once. Indexed on
`next_attempt_at`.

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
        <argument name="displayOptions"    xsi:type="object">BobGroup\BobGo\Service\DisplayOptionsMapper</argument>
    </arguments>
</type>
```

`scopeConfig` reads `general/locale/weight_unit` for the LBS→KG conversion, and the
dimension attribute codes, at payload-build time.

**Sync-log grid data source:**

```xml
<type name="Magento\Framework\View\Element\UiComponent\DataProvider\CollectionFactory">
    <arguments>
        <argument name="collections" xsi:type="array">
            <item name="bobgo_sync_log_listing_data_source" xsi:type="string">
                BobGroup\BobGo\Model\ResourceModel\SyncLog\Grid\Collection
            </item>
        </argument>
    </arguments>
</type>
```

The listing's data provider resolves its collection by name from this map, keyed on
the data-source name declared in `bobgo_sync_log_listing.xml`.

**Failed-sync admin notice:**

```xml
<type name="Magento\Framework\Notification\MessageList">
    <arguments>
        <argument name="messages" xsi:type="array">
            <item name="bobgo_failed_order_sync" xsi:type="string">
                BobGroup\BobGo\Model\AdminNotification\FailedSyncMessage
            </item>
        </argument>
    </arguments>
</type>
```

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
        <argument name="connectionHealth" xsi:type="object">BobGroup\BobGo\Service\ConnectionHealth</argument>
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
        <argument name="rateCache" xsi:type="object">BobGroup\BobGo\Service\RateCache</argument>
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
| 2 | `connection_status` | Connection | Label | Read-only. Whether the credentials actually work — see below |
| 3 | `api_key` | API Key | Obscure | Encrypted via `Backend\Encrypted` |
| 4 | `active` | Enable Bob Go rates at checkout | Yes/No | Triggers RAC connectivity test |
| 5 | `additional_info` | Show additional rate information | Yes/No | Shows delivery timeframe |
| 6 | `max_rates` | Maximum rates to show | Text | Blank = 20 |
| 7 | `enable_order_push` | Enable order push | Yes/No | |
| 8 | `enable_fulfillment_sync` | Enable fulfillment sync | Yes/No | Manages webhook subscriptions + drives reconciliation |
| 9 | `webhook_secret` | Webhook Signing Secret | Obscure | Encrypted; **required** — without it inbound webhooks 403 |
| 10 | `notify_customer_on_shipment` | Notify customer on shipment | Yes/No | |
| 11 | `send_display_options` | Send product options to Bob Go | Yes/No | Default on |
| 12 | `display_options_blocklist` | Product options not to send | Textarea | Depends on the toggle above |
| 13–15 | `dimension_attribute_*` | Length / Width / Height attribute code | Text | Magento has no native dimension attributes |
| 16 | `origin` *(group)* | Collection address (optional) | Group | 7 fields; each falls back to Store Information when blank |
| 17 | `suburb_label` | Suburb field label | Text | Blank = "Suburb" |
| 18 | `suburb_tooltip` | Suburb field help text | Text | Blank = "Required for shipping accuracy" |
| 20 | `enable_track_order` | Enable Track my order | Yes/No | **Hidden** (`showInDefault=0`) |

**Ordering is deliberate at one point:** the webhook secret sits immediately under
the fulfilment sync toggle. In 1.1.0 it sat below unrelated settings, which is
exactly what let a merchant enable sync before pasting the secret — every delivery
in that window was 403'd and, before the P0 fix, permanently un-retryable.

### Connection status

`Block/Adminhtml/System/Config/ConnectionStatus.php` renders
`Service/ConnectionHealth.php`'s state. That state is written through from **real
traffic** in `BobGoApiClient`, not from a Test button:

| Observed | State |
|----------|-------|
| any 2xx | `valid` |
| 401 | `invalid` |
| 404 / 5xx / timeout / transport failure (status 0) | **inconclusive — the last state is left alone** |

The inconclusive rule is the one that matters. A 404 means the key is fine but the
channel isn't enrolled; a timeout means nothing was learned. Treating either as
"invalid" would have the config page cry wolf on every blip. Only transitions are
written, so this costs nothing on the hot path.

### Sync log page

**Sales > Bob Go Sync Log**, a UI-component grid over `bobgo_sync_log`
(`view/adminhtml/ui_component/bobgo_sync_log_listing.xml`). On its own ACL resource
`BobGroup_BobGo::sync_log`, so support staff can be given the log without
order-editing rights.

Columns are the questions support actually asks: which order, which direction, what
the API said, and whether this delivery was a duplicate. The payload column is
shown but not filterable — a redacted JSON blob is useful to read and pointless to
search.

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
   → "Bob Go - X - Y" becomes just "Y", for bobgo_* methods only
        │
        ▼
   Order saved (sales_order_save_after)
        │
        ▼
   OrderSaveObserver → one row in bobgo_order_sync_queue     [no API call here]

2. PUSHED  (Cron\PushOrders, within a minute)
   claim → reload → policy re-check → emulate the order's store
        │
        ▼
   POST /v2/orders  → bobgo_order_id, bobgo_order_ref, bobgo_sync_hash,
                      per-item bobgo_order_item_id
   (failure → queue row deferred with backoff, bobgo_sync_status = failed,
    admin banner counts it)

3. UPDATED
   Any subsequent save → queued again → PATCH /v2/orders, but only if the
   canonicalised payload hash changed

4. FULFILLED
   Webhook POST /bobgo/webhook/receive (fulfillment/created)
        │
        ▼
   HMAC verify → parse → topic → OrderResolver → claimEventId
        │
        ▼
   FulfilmentSyncService::syncOrder()
   → GET /v2/order-fulfillments (authoritative)
   → full-replace bobgo_shipments, create the Magento shipment,
     stamp bobgo_fulfillment_id
   (InboundGuard suppresses the outbound echo this save would otherwise queue)

5. TRACKING UPDATE
   Webhook (tracking/updated) → same refresh, plus the checkpoint text added to
   order history. No longer depends on fulfillment/created having landed first.

6. CANCELLED ON BOB GO
   Webhook (order/updated, status=cancelled) → cancel the Magento order → then
   re-baseline bobgo_sync_hash, because cancelling zeroes total_due and so flips
   the derived payment_status, which would otherwise echo straight back out.

7. COMPLETED / CANCELLED IN MAGENTO
   Queued as usual → Cron\PushOrders → PATCH /v2/orders {id, status}
   → bobgo_status_synced records it so the transition isn't re-sent

8. SAFETY NET  (hourly)
   Cron\Reconcile → paged batch → the SAME FulfilmentSyncService::syncOrder(),
   so anything missed at step 4 or 5 is created within the hour.
   Also runs the daily webhook-subscription health check.
```

---

## 21. Error Handling and Logging

### Logging Strategy

All components log to Magento's standard logger (`Psr\Log\LoggerInterface`), which writes to `var/log/system.log` by default.

| Component | Log Prefix | Level | Context |
|-----------|-----------|-------|---------|
| BobGoApiClient | `Bob Go API error` / `Bob Go API transport failure` | ERROR | endpoint, status_code, response snippet (512 B), masked api_key |
| OrderPushService | `Bob Go: Order pushed/updated/failed` | INFO/ERROR | order_id, increment_id, bobgo_order_id |
| OrderSyncQueue | `Bob Go: failed to queue/dequeue/defer` | ERROR | order_id, error |
| Cron\PushOrders | `Bob Go: order push job failed` | ERROR | order_id, error |
| FulfilmentSyncService | `Bob Go fulfilment sync:` | INFO/WARNING/ERROR | order_id, fulfillment_id, tracking_number |
| OrderResolver | `Bob Go webhook: refusing to…` | WARNING/ERROR | the refusal and why |
| ReconciliationService | `Bob Go reconciliation:` | INFO/WARNING/ERROR | count, page, order_id |
| WebhookSubscriptionService | `Bob Go: webhook subscriptions…` | INFO/WARNING/ERROR | delivery_url, topics, ids |
| ConnectionHealth | `Bob Go: connection state changed` | INFO | state (transitions only) |
| ConfigChangeObserver | `Bob Go connectivity/RAC/webhook` | ERROR | error message |
| Controller\Webhook\Receive | `Bob Go webhook` | INFO/WARNING/ERROR | topic, event_id, order_id |
| OrderSaveObserver | `Bob Go: OrderSaveObserver failed` | ERROR | error message |
| BobGo (carrier) | `Bob Go: rate collection failed, hiding carrier` | ERROR | exception class, error |

Note the spelling split: the newer fulfilment-state code uses British
`fulfilment` in its own log prefixes, while the class names and Bob Go's own API
fields keep the American `fulfillment`. Grep for `Bob Go` rather than either
spelling.

### Error Recovery Patterns

1. **Non-blocking observers** - `OrderSaveObserver` wraps everything in try/catch. A Bob Go API failure will never prevent an order from being saved.
2. **Idempotent fulfillments** - tracking-number check, fulfillment_id check, and a refusal-to-ship when the payload has items but none mapped (prevents an "unknown SKU" payload from blowing out into a full shipment).
3. **Graceful API failures** - Every failure mode leaves `BobGoApiClient` as a `BobGoApiException`, including transport failures (connect/read timeout, DNS, TLS), which Magento's `Curl` client raises as a bare `\Exception` from `Curl::doError()`. On top of that `BobGo::collectRates()` catches `\Throwable` and returns `false`, hiding the carrier. Both layers are needed: `Shipping::collectCarrierRates()` calls `collectRates()` with **no** try/catch of its own, so anything escaping would 500 the checkout shipping step and the cart estimator for every customer. Timeouts are tight (8 s for rates, 5 s connect, 15 s elsewhere) so a Bob Go outage can't hang checkout.
4. **Webhook retry semantics** - `TransientWebhookException` from processing → controller releases the dedup claim, writes a failure log row with `event_id = NULL`, returns 500. Bob Go retries. Any other `\Throwable` → claim row stays, retries 200 at the dedup gate (operator must clear to replay). Every non-success row is written with `event_id = NULL` so a retry can always re-claim the slot.
7. **Order push failure detection** - A 2xx from `POST /v2/orders` that carries no usable (positive, numeric) order id is recorded as a **failure**, not a success, and the sync hash is deliberately not stored. Marking it synced would orphan the order: reconciliation only looks at orders with a `bobgo_order_id`, webhooks can't resolve to it, and the dirty check would suppress every future PATCH.
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

When `carriers/bobgo/enable_track_order` is on, the standalone tracking page at `/bobgo/tracking/index` is triply gated:

1. POST + valid `form_key` (CSRF).
2. **A matching `customer_email`** — an order number alone is guessable, because
   Magento's increment_id sequence is identical across stores.
3. The reference must resolve to an order or shipment belonging to that customer —
   otherwise no Bob Go call is made.

Without gate 3 the endpoint would be a tracking-reference oracle against the
merchant's Bob Go account; without gate 2 it would be an order-enumeration oracle
against the merchant's own customers. The field is hidden from the admin UI
(`showInDefault="0"`) and should remain that way until a merchant explicitly opts
in. See §13.

### Store Scope on Background Paths

Cron has no store context and the webhook endpoint resolves whichever store its
single delivery URL maps to. `Service/StoreScope.php` emulates the **order's** store
around the work, so the API key, environment and channel identifier all come from
the right place. Without it, a multi-store order would be pushed into another
store's Bob Go channel using another store's credentials.

### Webhook Deregistration on Uninstall

`Setup/Uninstall.php` deregisters the subscriptions. Leaving them behind means Bob
Go keeps POSTing to a URL that no longer resolves, failing every delivery and
burning the account-wide three-day window that disables subscriptions — including
ones the merchant sets up later. Removing the module cannot remove them for us,
because they live on Bob Go.

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

**Status:** 305 tests / 550 assertions passing. Run `composer check` for both gates. PHPStan: 0 errors at level 2. `composer check` runs both (the `stan` script passes `--memory-limit=1G`; the default 128M crashes the analyser).

### Test Files

| Test File | Tests |
|-----------|-------|
| `Api/BobGoApiClientTest.php` | HTTP client: GET/POST/PATCH/DELETE, error handling, transport failures wrapped as `BobGoApiException`, auth + channel-id headers |
| `Block/System/Config/Form/Field/VersionTest.php` | Version display block |
| `Controller/Webhook/ReceiveTest.php` | Webhook controller: response policy (no-reference silent 200, unresolved 200 + log, unknown topic 200), every non-success row logs `event_id=NULL`, body topic/event_id precedence, transient failure releases the claim, duplicate claim short-circuits |
| `Helper/DataTest.php` | Helper functions, debug logging |
| `Model/Carrier/AdditionalInfoTest.php` | Request body parsing (suburb attribute_code matching, associative-map shape, company, phone) |
| `Model/Carrier/BobGoTest.php` | Rate collection, validation, weight conversion, formatting, fail-soft when anything throws |
| `Model/Config/ApiConfigTest.php` | Configuration getters, environment URLs, feature flags |
| `Model/Source/FreemethodTest.php` | Free method source model |
| `Model/Source/GenericTest.php` | Generic source model base |
| `Observer/ConfigChangeObserverTest.php` | Config change reactions, connectivity tests |
| `Observer/ModifyShippingDescriptionTest.php` | Carrier filter + null safety on the description-rewrite observer |
| `Observer/OrderSaveObserverTest.php` | Order push/update triggering |
| `Plugin/Quote/ToOrderAddressPluginTest.php` | Suburb carries quote → order address (extension attr, custom attr, raw data fallback) |
| `Service/FulfillmentServiceTest.php` | Shipment creation, tracking updates, fulfillment-id + tracking-number idempotency |
| `Service/OrderMapperTest.php` | Order-to-payload mapping, status mapping, LBS→KG conversion, suburb resolution |
| `Service/OrderPushServiceTest.php` | Order POST/PATCH, sync-hash dirty-check, sync-log writes, 2xx-without-order-id treated as failure |
| `Service/OrderResolverTest.php` | Resolution ladder: corroboration, terminal channel_ref_id, refusal to relink, ambiguous matches, topic-specific `id` meaning, filter tripwire |
| `Service/FulfilmentSyncServiceTest.php` | The refresh path: blob persistence and no-op-when-unchanged, shipment creation without a webhook, cancelled fulfilments excluded, dedup by id and by tracking number, courier backfill, item-scope safety |
| `Service/OrderSyncPolicyTest.php` | Which orders are pushed, and which status transitions are forwarded |
| `Service/OrderSyncQueueTest.php` | Outbox: idempotent enqueue, swallowed DB errors, backoff, giving up |
| `Service/RateCacheTest.php` | TTL by address precision, negative caching, in-request memo, degrading to a miss |
| `Service/StoreScopeTest.php` | Emulates the order's store and restores it even when the callback throws |
| `Service/DisplayOptionsMapperTest.php` | Variant / custom / bundle option mapping, no key merging, NUL stripping, caps, blocklist |
| `Service/ConnectionHealthTest.php` | Write-through state, and inconclusive statuses leaving a known-good state alone |
| `Service/InboundGuardTest.php` | Suppression of the outbound echo, and clearing the mark when a handler throws |
| `Cron/PushOrdersTest.php` | The retry contract: release on success, defer on failure, drop what no longer qualifies |
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

### Current

1. **South Africa only** — `processAdditionalValidation()` rejects all non-ZA
   destinations. Supporting other countries means changing that validation.

2. **Tracking page hidden and experimental** — `enable_track_order` is
   `showInDefault="0"`. The controller is now triply gated (form key, matching
   customer email, local order/shipment match), but it should stay off until a
   merchant explicitly asks for it. See §13.

3. **Single carrier instance** — one Bob Go configuration per store; multi-store
   setups share the carrier code `bobgo`. Per-store *credentials* do work
   correctly now (see §22, Store Scope) — this is about the carrier code, not the
   config.

4. **`AdditionalInfo` is not DI-constructed** — created with `new` in the `BobGo`
   constructor, which makes it awkward to mock. Tests substitute the public
   property instead.

5. **`Magento\Framework\Registry` is still used** by `TrackingBlock` and
   `Controller\Tracking\Index` — deprecated since 2.3. Both belong to the disabled
   tracking page; migrate to view models if that feature is ever turned on.

6. **`bobgo_order_ref` field-name guesswork** — `applySuccess()` tries
   `response['reference']` then `response['order_ref']`. If Bob Go's key is
   neither, the column stays null forever. → Appendix C, Q2.

7. **Throwable-on-webhook keeps the dedup claim** — any exception other than
   `TransientWebhookException` leaves the claim row in place, so Bob Go's retries
   are answered 200 at the dedup gate. Intentional (don't loop on crash bugs), but
   an operator has to clear the row to allow a replay after fixing the cause.

8. **Rung 4 of the resolution ladder is still enabled** — a match on `increment_id`
   alone is accepted when the order has no stored Bob Go link, logged at warning
   level. It can be dropped once `channel_ref_id` is confirmed present on every
   inbound payload. → Appendix C, Q4.

9. **A fulfilment cancelled *after* we shipped it is not reflected** — Magento
   shipments cannot be un-shipped. The cancelled status does appear in
   `bobgo_shipments`, so it is visible in the admin panel, but no order comment or
   notice is raised.

10. **The fulfilments response item shape is unverified** — `FulfilmentSyncService`
    tolerates four possible keys for a fulfilment's line items. When none is
    present it refuses to guess the scope unless Bob Go reports exactly one live
    fulfilment for the order. **This is the first thing to confirm against a live
    sandbox.** → Appendix C, Q9.

11. **Rate payload sends zero dimensions** — the order payload reads dimensions
    from merchant-nominated product attributes, but the rate payload still sends
    `length_cm`/`width_cm`/`height_cm` as 0, so Bob Go cannot volumetric-price at
    checkout. Wiring them in needs a product-collection load to avoid an N+1 per
    cart line.

12. **No suburb field on admin order create** — `sales_order_create` uses a
    different form stack from the storefront checkout, so the LayoutProcessor
    plugin doesn't reach it. A phone order still syncs; `local_area` falls back to
    the city.

13. **No GraphQL / headless support for the suburb field** — needs a schema
    extension and a resolver. Not built: there is no known consumer.

### Resolved in 1.2.0

Kept here because the reasoning is load-bearing and the failure modes are worth
recognising if they ever reappear.

| Was | Now |
|-----|-----|
| Order push ran inline on `sales_order_save_after` | Queued to `bobgo_order_sync_queue`, drained by `Cron\PushOrders` with backoff (§8) |
| No rate caching | In-request memo + TTL by address precision + negative entries (`Service/RateCache`) |
| Reconciliation refreshed the shipments blob but never created shipments | Shares `FulfilmentSyncService::syncOrder()` with the webhook handlers, so a lost webhook self-heals within the hour (§9) |
| Reconciliation re-scanned the same first 100 orders forever | Paged by a cursor in the `flag` table (§10a) |
| Tracking-number lookup scanned the store's *oldest* 100 Bob Go orders, unsorted | Scoped to the requesting customer's own orders, newest first (§13) |
| Per-store config resolved from the default store on cron and webhook paths | `Service/StoreScope` emulates the order's store (§22) |
| A 2xx with no order id was recorded as a success | Recorded as a failure, with no hash written, so it stays retryable (§8) |
| Rejected webhooks occupied the dedup slot permanently | Every non-success row logs `event_id = NULL` (§10) |
| A Bob Go timeout escaped as a bare `\Exception` and 500'd checkout | Wrapped into `BobGoApiException` in the client, plus a `\Throwable` guard in `collectRates()` (§21) |

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
3. **Units:** the API is sent **kilograms** (`weight_kg` on rates,
   `unit_weight_kg` on orders — rounded to 2 and 1 decimals respectively). Grams
   appear only as an intermediate inside `BobGo::getItemWeight()`; see §12.

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
| `Service\OrderPushService` | POST/PATCH orders to Bob Go (sync-hash dirty-check); returns `bool`. A 2xx with no usable order id is recorded as a failure |
| `Service\OrderResolution` | Outcome of webhook order resolution — drives the HTTP status |
| `Service\OrderResolver` | Resolves an inbound payload to a local order via a strict ladder; refuses to guess |
| `Service\FulfilmentSyncService` | Re-fetches `GET /v2/order-fulfillments` and reconciles: full-replaces the shipments blob and creates any missing Magento shipment |
| `Service\OrderSyncPolicy` | Which orders are pushed, and which status transitions are forwarded |
| `Service\OrderSyncQueue` | Outbox table between the save observer and `Cron\PushOrders` |
| `Service\RateCache` | Rates cache: in-request memo, TTL split by address precision, short negative entry |
| `Cron\PushOrders` | Every minute — drains the order-push outbox, then forwards terminal statuses |
| `Service\ConnectionHealth` | Whether the stored credentials work, folded from observed HTTP statuses |
| `Service\DisplayOptionsMapper` | Order item `product_options` → Bob Go `display_options` |
| `Service\InboundGuard` | Suppresses the outbound push for saves Bob Go itself caused |
| `Service\StoreScope` | Emulates the order's store so per-store config resolves correctly |
| `Block\Adminhtml\System\Config\ConnectionStatus` | Connection row on the config screen |
| `Controller\Adminhtml\SyncLog\Index` | Sync log grid page |
| `Model\AdminNotification\FailedSyncMessage` | Admin banner counting failed order syncs |
| `Model\ResourceModel\SyncLog\Grid\Collection` | SearchResult collection backing the grid |
| `Setup\Uninstall` | Deregisters webhooks and removes config on module:uninstall |
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

---

## Appendix A — Endpoint reference

Consolidated from what this extension calls plus the WooCommerce integration's
verified endpoint list. Rows marked **unused** are documented because they are the
obvious next reach and their shape is non-obvious.

| Dir | Method | Path | Purpose |
|-----|--------|------|---------|
| → | GET | `/v2/webhooks` | Test connection; list subscriptions; health check |
| → | POST | `/v2/webhooks` | Register subscriptions (bulk, `webhook_subscriptions[]`) |
| → | DELETE | `/v2/webhooks` | Deregister (bulk, `{"ids": [...]}`) |
| → | POST | `/v2/rates-at-checkout` | Live rates |
| → | POST | `/v2/orders` | Create order → `{id, order_items[]}`. **Accepts no `status` field** |
| → | PATCH | `/v2/orders` | Update order, and the only way to send `status`. `id` goes **in the body** |
| → | GET | `/v2/order-fulfillments?order_id={bobgo_id}` | Authoritative fulfilment state |
| → | GET | `/v2/tracking?tracking_reference=…` | Shopper-facing tracking |
| → | GET | `/v2/orders?id=X` | **unused.** Fetch one order. Not a path-style route — `/v2/orders/{id}` is unregistered — and **account-scoped**, not channel-scoped |
| → | GET | `/v2/orders?tracking_reference=…` | **unused.** Deterministic shipment → order resolution, channel-scoped |
| → | GET | `/v2/orders?channel_order_number=…` | **unused.** Resolve by order number (ILIKE exact; some channels store a leading `#`) |
| ← | POST | `{store}/bobgo/webhook/receive` | `fulfillment/created`, `tracking/updated`, `order/updated` |

There is **no `channel_ref_id` filter** on `GET /v2/orders`. List lookups are
channel-scoped server-side by the API key's claims; the by-id branch is
account-scoped only.

## Appendix B — Address shape

`company, street_address, local_area, city, zone, code, country`

Identical for `collection_address`, `delivery_address`, `billing_address` and
`shipping_address`. `local_area` = suburb, `zone` = province, `code` = postal code.

## Appendix C — Open questions for Bob Go

Carried forward from the review and the WooCommerce port. Worth re-raising because
this extension hits all of them.

| # | Question | Why it matters here |
|---|----------|---------------------|
| Q1 | What exactly does `bobgo-channel-identifier` expect — full canonical URL, or host with the scheme stripped? | Two shipped integrations disagree: WooCommerce sends the URL, we strip the scheme (§6) |
| Q2 | Which response key carries the immutable order reference? | `applySuccess()` guesses `reference` then `order_ref`; if neither, `bobgo_order_ref` stays null forever |
| Q3 | Does `PATCH /v2/orders` echo enough to confirm a status change applied? | Otherwise a follow-up `GET /v2/orders?id=` is needed to be certain |
| Q4 | Is `channel_ref_id` present on **every** inbound webhook payload, on every topic? | If yes, rung 4 of the resolution ladder (order number alone) can be dropped — see Known Limitations #13 |
| Q5 | **Channel-scoped webhook delivery, or a `channel_id` in payloads.** | The root fix. Eliminates the cross-channel mis-link class and most ignored-event volume. More urgent for Magento than WooCommerce, because default `increment_id` sequences are identical across stores |
| Q6 | Should 4xx count toward the three-day disable window? | "Malformed input" and "not my order" are very different signals |
| Q7 | Should order-less / foreign-channel events reach channel endpoints at all? | Most of our inbound traffic may be events we can only acknowledge |
| Q8 | Does `PATCH` support un-cancelling? | Blocks any reopen story |
| Q9 | **Which key holds a fulfilment's line items in `GET /v2/order-fulfillments`?** | `FulfilmentSyncService` tolerates four; when none is present it refuses to guess the scope unless there is exactly one live fulfilment. First thing to confirm against a sandbox |

---

*Last updated: 2026-07-30*
*Extension version: 1.1.0*
