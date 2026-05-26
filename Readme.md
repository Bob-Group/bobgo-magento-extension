# Bob Go Shipping Extension for Magento 2

Real-time shipping rates, automatic order push, signed-webhook fulfillment sync, and shipment tracking for South African e-commerce — powered by [Bob Go](https://www.bobgo.co.za).

![Version](https://img.shields.io/badge/version-1.1.0-blue)
![PHP](https://img.shields.io/badge/php-7.4%20|%208.0%20|%208.2-8892BF)
![Magento](https://img.shields.io/badge/magento-2.3%2B-f46f25)
![License](https://img.shields.io/badge/license-GPL--3.0--or--later-green)

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
  - [Composer (Recommended)](#composer-recommended)
  - [Manual Installation](#manual-installation)
  - [Verify Installation](#verify-installation)
- [Configuration](#configuration)
  - [Getting an API Key](#getting-an-api-key)
  - [Admin Settings](#admin-settings)
  - [Configuration Reference](#configuration-reference)
  - [Store Suburb](#store-suburb)
- [How It Works](#how-it-works)
  - [Rates at Checkout](#rates-at-checkout)
  - [Order Push](#order-push)
  - [Webhook Fulfillment Sync](#webhook-fulfillment-sync)
  - [Reconciliation (Safety Net)](#reconciliation-safety-net)
  - [Sync Log](#sync-log)
  - [Admin Order Panel](#admin-order-panel)
  - [Shipment Tracking](#shipment-tracking)
- [Security](#security)
  - [HMAC Webhook Verification](#hmac-webhook-verification)
  - [Encrypted Credentials](#encrypted-credentials)
  - [CSRF and Admin Hardening](#csrf-and-admin-hardening)
  - [PII Redaction](#pii-redaction)
- [Architecture](#architecture)
  - [Directory Structure](#directory-structure)
  - [Database Additions](#database-additions)
  - [Key Design Decisions](#key-design-decisions)
- [Troubleshooting](#troubleshooting)
- [Contributing](#contributing)
- [Support](#support)
- [License](#license)

## Features

- **Rates at Checkout** — Display live shipping rates from Bob Go directly in the Magento checkout, with optional delivery timeframes and service descriptions
- **Automatic Order Push** — Orders are POSTed to Bob Go on first save and PATCHed on subsequent saves, with payload-hash dirty checking so unchanged orders never re-hit the API
- **Signed Webhook Fulfillment Sync** — Bob Go pushes `fulfillment/created` and `tracking/updated` events to the store; every payload is HMAC-SHA256 verified before any processing happens
- **Hourly Reconciliation Cron** — Safety net that re-fetches authoritative fulfilment state from Bob Go for active and recently-completed orders, closing the gap if a webhook is lost
- **Sync Log** — Dedicated `bobgo_sync_log` table records every inbound and outbound event with direction, payload, HTTP status, success flag and `event_id` for audit and debugging
- **Admin Order Panel** — Bob Go sync status, last-synced / last-webhook timestamps, shipment list, and a Resync button on the order detail page
- **Custom Suburb Field** — Adds a suburb / local area field to the checkout address form, used as `local_area` on both rate and order payloads for accurate South African shipping
- **Automatic Weight Conversion** — Item weights are normalised to kilograms at payload-build time without mutating the stored order row
- **Admin Connectivity Testing** — Saving the carrier config automatically tests API connectivity and rates-at-checkout, surfacing results as admin messages
- **Automatic Webhook Lifecycle** — Webhook subscriptions are created when fulfillment sync is enabled and cleaned up when it is disabled

## Requirements

| Requirement | Version |
|---|---|
| PHP | ^7.4 \|\| ^8.0 \|\| ^8.2 |
| Magento | 2.3+ |
| Bob Go account | [Sign up at bobgo.co.za](https://www.bobgo.co.za) |
| Bob Go API key | Obtained from your Bob Go dashboard |
| Bob Go webhook signing secret | Required if you enable Fulfillment Sync (see [Admin Settings](#admin-settings)) |

## Installation

### Composer (Recommended)

This package is not published on Packagist. Add the GitHub repository as a VCS source first:

```bash
composer config repositories.bobgo vcs https://github.com/nicholasgousis/bobgo-magento-extension.git
composer require bobgo/bobgo-magento-extension
```

Then run the standard Magento setup commands:

```bash
php bin/magento module:enable BobGroup_BobGo
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento cache:flush
```

### Manual Installation

1. Download the latest release ZIP from the [GitHub releases page](https://github.com/nicholasgousis/bobgo-magento-extension/releases)
2. Extract the contents to `app/code/BobGroup/BobGo/` in your Magento root
3. Run the setup commands:

```bash
php bin/magento module:enable BobGroup_BobGo
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento cache:flush
```

### Verify Installation

```bash
php bin/magento module:status BobGroup_BobGo
```

You should see the module listed under "List of enabled modules".

## Configuration

### Getting an API Key

1. Sign up or log in at [bobgo.co.za](https://www.bobgo.co.za)
2. Navigate to your account settings to generate an API key
3. If you plan to enable fulfillment sync, also generate a **webhook signing secret** — Bob Go uses it to sign every webhook delivery and the extension uses it to verify them

### Admin Settings

Navigate to **Stores > Configuration > Sales > Shipping Methods > Bob Go** in the Magento admin panel.

### Configuration Reference

All settings live under `carriers/bobgo/*`.

| Setting | Type | Default | Description |
|---|---|---|---|
| **Environment** | Select | Sandbox | `Sandbox` (testing) or `Production` (live). Sandbox uses `api.sandbox.bobgo.co.za`, production uses `api.bobgo.co.za`. |
| **API Key** | Encrypted text | — | Your Bob Go API key. Stored encrypted with Magento's `EncryptorInterface`. |
| **Webhook Signing Secret** | Encrypted text | — | Merchant-issued HMAC secret used to verify inbound webhooks. **Required** for fulfillment sync — without it every webhook is rejected with a 403. |
| **Enable Bob Go rates at checkout** | Yes/No | No | Customers see live shipping rates at checkout. |
| **Show additional rate information** | Yes/No | No | Displays delivery timeframes and additional service level descriptions alongside rates. |
| **Enable order push** | Yes/No | No | Automatically pushes orders to Bob Go for fulfillment when they are placed or updated. |
| **Enable fulfillment sync** | Yes/No | No | Syncs fulfillments and tracking updates from Bob Go back to Magento via webhooks. Also drives the hourly reconciliation cron. |
| **Notify customer on shipment** | Yes/No | Yes | Sends an email notification to the customer when a shipment is created from a Bob Go fulfillment. |

Additional configuration:

| Path | Description |
|---|---|
| `general/store_information/suburb` | Origin suburb used as `local_area` on rate requests (see below) |

### Store Suburb

To improve rate accuracy, set your store's suburb under **Stores > Configuration > General > General > Store Information > Suburb**. The value is included as the collection address `local_area` on every rate request.

For the destination side, customers fill in a suburb field that the extension injects into the checkout shipping address form. That value flows through to the order's shipping address custom attribute and is used as `local_area` on the order push payload.

## How It Works

### Rates at Checkout

1. The customer enters their shipping address at checkout
2. The extension builds a rate request: collection address (your store), delivery address (customer), cart items with weights normalised to kilograms
3. POST to `rates-at-checkout` on the v2 API using the `street_address` / `local_area` / `zone` / `country` / `code` address shape
4. Available service levels are returned with prices and (optionally) delivery timeframes, and rendered as shipping options

### Order Push

1. The `OrderSaveObserver` listens on `sales_order_save_after`
2. New orders (no `bobgo_order_id` yet) are POSTed to `/v2/orders`. The returned Bob Go order id is saved on the order
3. Existing orders are PATCHed — but only if the canonicalised payload hash differs from the last successful sync (sync-hash dirty checking). Unchanged saves never hit the API
4. Errors are caught and logged. Order saving is never blocked by an API failure
5. A re-entrancy guard prevents the observer from looping on its own nested save
6. The Magento `entity_id` is sent as `channel_ref_id` (an immutable idempotency key) and `increment_id` as `channel_order_number` (the human-readable order number)

### Webhook Fulfillment Sync

When fulfillment sync is enabled, the extension subscribes to two topics on Bob Go: `fulfillment/created` and `tracking/updated`. Delivery URL is `{your-store-url}/bobgo/webhook/receive`.

Inbound flow for every webhook:

1. **HMAC verification** — the raw body is verified against the `Bobgo-Webhook-Signature` header (HMAC-SHA256, base64, constant-time compare). Failed verification → 403, body never inspected
2. **JSON parse + topic resolution** — topic comes from the header, with a payload-shape fallback for legacy events
3. **`event_id` dedup** — replayed deliveries return 200 without reprocessing
4. **Route** — `fulfillment/created` creates a Magento shipment with items and tracking; `tracking/updated` adds tracking to the matching shipment and records a status comment
5. Every outcome — accepted, rejected, duplicate, processing error — is written to `bobgo_sync_log`

Fulfillment shipments are deduped by tracking number **and** by the Bob Go `fulfillment_id` (stored on the shipment row). A payload with neither identifier is refused rather than risking a duplicate.

### Reconciliation (Safety Net)

Hourly cron (`Cron\Reconcile` → `ReconciliationService::run`):

1. Loads up to 100 orders that have a `bobgo_order_id` and are in `new` / `processing` / `holded` (always reconciled) or `complete` within the last 14 days (lookback window for late tracking updates)
2. For each, GETs `order-fulfillments?order_id=...` from Bob Go
3. If the returned shipment set differs from what we have stored, it full-replaces `sales_order.bobgo_shipments` (Bob Go is the source of truth) and updates `bobgo_last_synced`
4. Quiet runs are no-ops

Webhooks remain the primary fulfillment signal. The reconciler closes the gap when one is lost or delayed.

### Sync Log

Every inbound and outbound event passes through `SyncLogger` and lands in `bobgo_sync_log`:

| Column | Purpose |
|---|---|
| `direction` | `inbound` / `outbound` |
| `event_type` | `webhook_received`, `webhook_rejected`, `webhook_unknown_topic`, `fulfillment_received`, `tracking_updated`, `order_created`, `order_updated_outbound`, `reconciliation_fetched` |
| `event_id` | Provider-issued id (used for inbound dedup) |
| `payload` | JSON request/response body, capped at 64 KB and with PII fields (`customer_email`, `customer_phone`, etc.) redacted |
| `http_status` | Observed/returned HTTP status |
| `success` | Outcome flag |
| `retry_count` | Reserved for future use |
| `order_id` | Linked Magento `entity_id` when available |
| `created_at` | Timestamp |

Indexed on `event_id`, `order_id`, `created_at` for fast lookups.

### Admin Order Panel

The admin order detail page gains a Bob Go panel showing:

- Bob Go order id
- Sync status (`pending` / `success` / `failed`)
- Last synced / last webhook timestamps
- Bob Go shipments — tracking numbers, courier, service level, status (full-replaced by reconciliation)
- A POST-only **Resync with Bob Go** button that re-pushes the order and refetches authoritative fulfilment state

### Shipment Tracking

The extension overrides Magento's default tracking popup with a custom view that displays:

- Live tracking status from the Bob Go API
- Shipment event timeline
- Tracking URLs for direct carrier tracking

When customers click "Track" on their order, they see real-time tracking information fetched from Bob Go.

## Security

### HMAC Webhook Verification

Every inbound webhook body is HMAC-SHA256 signed by Bob Go using the merchant-issued webhook signing secret. The extension recomputes the signature over the same raw byte sequence and compares in constant time. Any of these fail closed with a 403, and the body is never inspected:

- Missing or empty `Bobgo-Webhook-Signature` header
- Webhook signing secret not configured in admin
- Signature mismatch

Rejected request bodies are written to the sync log, truncated to 256 bytes so an unauthenticated attacker can't bloat the table with sustained traffic.

### Encrypted Credentials

The API key and the webhook signing secret are stored using Magento's `Backend\Encrypted` backend model. Accessors in `Model/Config/ApiConfig` decrypt on read via `EncryptorInterface::decrypt()`. API keys are masked in error logs (only the last four characters are shown).

### CSRF and Admin Hardening

The admin **Resync** action is POST-only (`HttpPostActionInterface`), so Magento enforces form-key verification automatically. The button on the order panel is rendered as a `<form method="post">` with a hidden form key — accidental or malicious GET requests to the resync URL return a redirect, never a state change.

The public webhook endpoint exits Magento's CSRF flow (signature verification is the auth mechanism).

### PII Redaction

`SyncLogger` redacts the following keys to `***` before persisting payloads to `bobgo_sync_log`: `customer_email`, `customer_phone`, `customer_name`, `customer_surname`, `telephone`, `phone`, `email`. The canonical record of these values is the order itself; the log keeps shape and identifiers, not raw PII.

## Architecture

### Directory Structure

```
BobGroup/BobGo/
├── Api/
│   ├── BobGoApiClient.php          # HTTP client (Bearer auth + channel-identifier)
│   ├── BobGoApiException.php       # Custom exception with status + body
│   └── OrderMapperInterface.php    # Order → API payload contract
├── Block/
│   ├── Adminhtml/Order/View/
│   │   └── BobGoInfo.php           # Admin order detail panel block
│   ├── System/Config/Form/Field/
│   │   └── Version.php             # Version display in admin config
│   ├── TrackingBlock.php           # Tracking page block
│   └── TrackOrderLink.php          # Customer-account tracking link (gated)
├── Controller/
│   ├── Adminhtml/Order/
│   │   └── Resync.php              # POST-only admin resync action
│   ├── Tracking/Index.php          # Customer-facing tracking page
│   └── Webhook/Receive.php         # HMAC-verified webhook receiver
├── Cron/
│   └── Reconcile.php               # Hourly cron entry — wraps ReconciliationService
├── Helper/Data.php                 # Module helper (version, debug logging)
├── Model/
│   ├── Carrier/
│   │   ├── BobGo.php               # Carrier (rates, tracking)
│   │   └── AdditionalInfo.php      # Pulls suburb/company/phone from request body
│   ├── Config/ApiConfig.php        # Centralised config + encrypted-field access
│   ├── ResourceModel/
│   │   ├── SyncLog.php             # bobgo_sync_log resource model
│   │   └── SyncLog/Collection.php  # Collection class
│   ├── Source/                     # Admin select sources (Environment, etc.)
│   └── SyncLog.php                 # bobgo_sync_log model + event/direction constants
├── Observer/
│   ├── ConfigChangeObserver.php    # Tests API on config save; manages webhook subscriptions
│   ├── ModifyShippingDescription.php # Cleans bobgo carrier description on placement
│   └── OrderSaveObserver.php       # Triggers order push on save
├── Plugin/
│   ├── Checkout/Block/
│   │   └── LayoutProcessorPlugin.php  # Injects suburb field into checkout
│   └── OrderRepositoryPlugin.php      # Loads/saves bobgo_order_id extension attribute
├── Service/
│   ├── FulfillmentService.php         # Shipment creation from webhooks
│   ├── OrderMapper.php                # Order → API payload (weight + suburb handling)
│   ├── OrderPushService.php           # POST/PATCH orders with sync-hash dirty check
│   ├── ReconciliationService.php      # Hourly safety-net refetch
│   ├── SyncLogger.php                 # Single writer for bobgo_sync_log + PII redaction
│   ├── WebhookSignatureVerifier.php   # HMAC-SHA256 verification
│   └── WebhookSubscriptionService.php # Webhook subscription lifecycle
├── etc/
│   ├── acl.xml                        # ACL placeholder
│   ├── adminhtml/                     # Admin events, routes, system config
│   ├── frontend/                      # Frontend DI + routes
│   ├── config.xml                     # Default config values
│   ├── crontab.xml                    # Hourly reconciliation schedule
│   ├── db_schema.xml                  # Declarative schema (see below)
│   ├── db_schema_whitelist.json       # Whitelist for declarative schema
│   ├── di.xml                         # DI: preferences, plugins, arguments
│   ├── events.xml                     # Frontend events
│   ├── extension_attributes.xml       # suburb (quote address), bobgo_order_id (order)
│   └── module.xml                     # Module definition + sequence deps
├── view/
│   ├── adminhtml/                     # Admin order panel layout + template
│   └── frontend/                      # Checkout JS, tracking templates, layouts
├── composer.json
├── phpstan.neon                       # PHPStan level 2 config
├── phpunit.xml.dist
├── bump-version.sh                    # Version bump helper
└── registration.php                   # class_exists-guarded Magento registration
```

### Database Additions

Declarative schema (`etc/db_schema.xml`):

| Table | Column | Type | Purpose |
|---|---|---|---|
| `sales_order` | `bobgo_order_id` | `varchar(255)` | Bob Go order id (returned on POST) |
| `sales_order` | `bobgo_order_ref` | `varchar(255)` | Immutable Bob Go reference (when provided) |
| `sales_order` | `bobgo_sync_status` | `varchar(32)` | `pending` \| `success` \| `failed` |
| `sales_order` | `bobgo_sync_hash` | `varchar(64)` | MD5 of last-synced payload (dirty check) |
| `sales_order` | `bobgo_last_synced` | `timestamp` | Last successful outbound sync |
| `sales_order` | `bobgo_last_webhook` | `timestamp` | Last inbound webhook for this order |
| `sales_order` | `bobgo_shipments` | `text` | JSON shipments array (authoritative, full-replaced by reconciliation) |
| `sales_order_item` | `bobgo_order_item_id` | `varchar(255)` | Bob Go line-item id |
| `sales_shipment` | `bobgo_fulfillment_id` | `varchar(128)` | Bob Go fulfilment id (idempotency) |

And one new table:

| Table | Purpose |
|---|---|
| `bobgo_sync_log` | One row per inbound or outbound event. Columns: `entity_id`, `order_id`, `event_type`, `direction`, `event_id`, `payload`, `http_status`, `success`, `retry_count`, `created_at`. Indexed on `event_id`, `order_id`, `created_at`. |

### Key Design Decisions

- **Centralised API client** — All Bob Go API calls go through `BobGoApiClient` (Bearer auth, channel-identifier header, 30 s timeout, structured exceptions)
- **Webhook verification before processing** — `Receive` calls `WebhookSignatureVerifier` first; nothing else looks at the body until it passes
- **`event_id` dedup** — Retried webhook deliveries 200 quickly without re-running side effects
- **Sync-hash dirty checking** — `OrderPushService::updateOrder()` skips PATCHes when nothing has actually changed
- **Weight conversion at payload-build time** — LBS → KG happens in `OrderMapper`, not as a `beforeSave` mutation, so the underlying order row is never corrupted by repeated saves
- **Reconciliation as safety net** — Webhooks remain primary; the hourly cron only repaints state when something differs
- **Single sync log writer** — `SyncLogger` is the only path that writes `bobgo_sync_log`, with built-in PII redaction and length capping
- **Non-blocking order push** — API errors during order push are caught and logged; they never prevent the order from being saved in Magento
- **Extension attributes** — `suburb` is a quote address extension attribute (also readable as a custom attribute on the order address); `bobgo_order_id` is exposed on the order interface for REST consumers
- **POST-only admin Resync** — Implements `HttpPostActionInterface` so Magento enforces form-key verification automatically

## Troubleshooting

### No shipping rates at checkout

1. Verify **Stores > Configuration > Sales > Shipping Methods > Bob Go > Enable Bob Go rates at checkout** is set to **Yes**
2. Confirm the API key is correct and matches the selected environment (sandbox key for sandbox, production key for production)
3. The shipping address must include a valid South African postal code and country `ZA`
4. Check `var/log/exception.log` and `var/log/system.log` for "Bob Go rates API error"

### Orders not appearing in Bob Go

1. Verify **Enable order push** is set to **Yes**
2. Check that the API key is valid for the selected environment
3. Inspect `bobgo_sync_log` filtered by `direction = 'outbound'` and `success = 0` for failures
4. Orders are never blocked by API errors — failed pushes are flagged with `bobgo_sync_status = 'failed'` on the order row

### Webhooks rejected with 403

The most common cause is a mismatched signing secret. Check:

1. The **Webhook Signing Secret** field is populated in admin (it's stored encrypted, so you can't see the value once saved — re-paste it to be sure)
2. The secret matches what you registered the webhook with on the Bob Go side
3. Filter `bobgo_sync_log` on `event_type = 'webhook_rejected'` to see the captured request snippet and reason

### Fulfillment sync not working

1. **Enable fulfillment sync** is set to **Yes** and a **Webhook Signing Secret** is set
2. Your store's base URL is publicly reachable from the internet (Bob Go must reach `{your-store-url}/bobgo/webhook/receive`)
3. Webhook subscriptions were created — check the admin success message after saving config, or `bobgo_sync_log` for outbound `webhooks` POSTs
4. The hourly reconciliation cron is running (it's the safety net if a webhook is lost)

### API key errors after restore / migration

API keys and webhook secrets are stored encrypted with the store's `crypt/key`. If you've migrated databases between environments with different keys, the encrypted values will be unreadable — re-enter them in admin.

### Weight calculation issues

The extension normalises weights to kilograms when building the outbound payload. If your Magento store uses LBS, conversion is automatic (`weight × 0.45359237`). The stored Magento weight is never mutated. Item weights above 500 kg are flagged as likely data errors and the carrier returns "not available."

### DI compilation errors after upgrade

```bash
rm -rf generated/code/* generated/metadata/*
php bin/magento setup:di:compile
php bin/magento cache:flush
```

If you upgraded from a version that included the legacy `AddWeightUnitToOrderPlugin`, you may also need to clear `var/cache` and any compiled caches that referenced it.

## Contributing

1. Fork the repository
2. Create a feature branch from `dev`
3. Make your changes
4. Run quality checks: `composer check` (runs `composer stan` then `composer test`)
5. Submit a pull request to `dev`

**Branch model:**
- `main` — stable / production releases
- `dev` — active development (target your PRs here)

## Support

- **Website:** [bobgo.co.za](https://www.bobgo.co.za)
- **Email:** support@bobgo.co.za
- **Issues:** [GitHub Issues](https://github.com/nicholasgousis/bobgo-magento-extension/issues)

## License

This project is licensed under the [GPL-3.0-or-later](LICENSE) license.
