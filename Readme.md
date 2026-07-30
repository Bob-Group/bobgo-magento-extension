# Bob Go shipping extension for Magento 2

Real-time shipping rates, automatic order push, signed-webhook fulfillment sync, and shipment tracking for South African e-commerce — powered by [Bob Go](https://www.bobgo.co.za).

![Version](https://img.shields.io/badge/version-1.2.0-blue)
![PHP](https://img.shields.io/badge/php-7.4%20|%208.0%20|%208.2-8892BF)
![Magento](https://img.shields.io/badge/magento-2.3%2B-f46f25)
![License](https://img.shields.io/badge/license-GPL--3.0--or--later-green)

## Table of contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
  - [Composer (recommended)](#composer-recommended)
  - [Manual installation](#manual-installation)
  - [Verify installation](#verify-installation)
- [Configuration](#configuration)
  - [Getting an API key](#getting-an-api-key)
  - [Admin settings](#admin-settings)
  - [Configuration reference](#configuration-reference)
  - [Store suburb](#store-suburb)
- [How it works](#how-it-works)
  - [Rates at checkout](#rates-at-checkout)
  - [Order push](#order-push)
  - [Webhook fulfillment sync](#webhook-fulfillment-sync)
  - [Reconciliation (safety net)](#reconciliation-safety-net)
  - [Sync log](#sync-log)
  - [Admin order panel](#admin-order-panel)
  - [Shipment tracking](#shipment-tracking)
- [Security](#security)
  - [HMAC webhook verification](#hmac-webhook-verification)
  - [Encrypted credentials](#encrypted-credentials)
  - [CSRF and admin hardening](#csrf-and-admin-hardening)
  - [PII redaction](#pii-redaction)
- [Architecture](#architecture)
  - [Directory structure](#directory-structure)
  - [Database additions](#database-additions)
  - [Key design decisions](#key-design-decisions)
- [Troubleshooting](#troubleshooting)
- [Contributing](#contributing)
- [Support](#support)
- [License](#license)

## Features

- **Rates at checkout** — Display live shipping rates from Bob Go directly in the Magento checkout, with optional delivery timeframes and service descriptions
- **Automatic order push** — Orders are queued on save and sent by a background job a minute later, so checkout never waits on the API. POSTed on first sync, PATCHed afterwards, with payload-hash dirty checking so unchanged orders never re-hit the API, and automatic retry with backoff when something fails
- **Signed webhook fulfillment sync** — Bob Go pushes `fulfillment/created`, `tracking/updated`, and `order/updated` events to the store; every payload is HMAC-SHA256 verified before any processing happens
- **Hourly reconciliation cron** — A real safety net: it runs the same code path as the webhooks, so a fulfilment whose webhook was lost gets its Magento shipment created within the hour
- **Order status forwarding** — Cancelling or completing an order in Magento is forwarded to Bob Go, and a cancellation on Bob Go cancels the Magento order
- **Rate caching** — Cart-page estimates are cached for 2 hours and checkout rates for 15 minutes, with a short negative cache, so the cart's constant recalculation stops hammering the API
- **Free-shipping shortcut** — When a cart rule already grants free shipping, one zero-cost rate is shown and the API call is skipped entirely
- **Product options on the picking list** — The variant, custom options and personalisation text the customer chose are forwarded to Bob Go as `display_options`
- **Sync log** — Dedicated `bobgo_sync_log` table records every inbound and outbound event with direction, payload, HTTP status, success flag and `event_id` for audit and debugging
- **Admin order panel** — Bob Go sync status, last-synced / last-webhook timestamps, shipment list, and a resync button on the order detail page
- **Sync log viewer** — A filterable admin grid over every inbound and outbound event, under **Sales > Bob Go Sync Log**, on its own ACL resource so support staff don't need order-editing rights
- **Connection health** — The config screen shows whether the credentials actually work, folded from real API traffic rather than from a test button, plus an admin banner when orders fail to sync
- **Custom suburb field** — Adds a suburb / local area field to the checkout address form, used as `local_area` on both rate and order payloads for accurate South African shipping
- **Automatic weight conversion** — Item weights are normalised to kilograms at payload-build time without mutating the stored order row
- **Admin connectivity testing** — Saving the carrier config automatically tests API connectivity and rates-at-checkout, surfacing results as admin messages
- **Automatic webhook lifecycle** — Subscriptions are created when fulfillment sync is enabled, cleaned up when it is disabled and when the module is uninstalled, and repaired by a daily health check if Bob Go ever disables them

## Requirements

| Requirement | Version |
|---|---|
| PHP | ^7.4 \|\| ^8.0 \|\| ^8.2 |
| Magento | 2.3+ |
| Bob Go account | [Sign up at bobgo.co.za](https://www.bobgo.co.za) |
| Bob Go API key | Obtained from your Bob Go dashboard |
| Bob Go webhook signing secret | Required if you enable fulfillment sync (see [admin settings](#admin-settings)) |

## Installation

### Composer (recommended)

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

### Manual installation

1. Download the latest release ZIP from the [GitHub releases page](https://github.com/nicholasgousis/bobgo-magento-extension/releases)
2. Extract the contents to `app/code/BobGroup/BobGo/` in your Magento root
3. Run the setup commands:

```bash
php bin/magento module:enable BobGroup_BobGo
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento cache:flush
```

### Verify installation

```bash
php bin/magento module:status BobGroup_BobGo
```

You should see the module listed under "List of enabled modules".

## Configuration

### Getting an API key

1. Sign up or log in at [bobgo.co.za](https://www.bobgo.co.za)
2. Navigate to your account settings to generate an API key
3. If you plan to enable fulfillment sync, also generate a **webhook signing secret** — Bob Go uses it to sign every webhook delivery and the extension uses it to verify them

### Admin settings

Navigate to **Stores > Configuration > Sales > Shipping Methods > Bob Go** in the Magento admin panel.

### Configuration reference

All settings live under `carriers/bobgo/*`.

| Setting | Type | Default | Description |
|---|---|---|---|
| **Environment** | Select | Sandbox | `Sandbox` (testing) or `Production` (live). Sandbox uses `api.sandbox.bobgo.co.za`, production uses `api.bobgo.co.za`. |
| **API key** | Encrypted text | — | Your Bob Go API key. Stored encrypted with Magento's `EncryptorInterface`. |
| **Webhook signing secret** | Encrypted text | — | Merchant-issued HMAC secret used to verify inbound webhooks. **Required** for fulfillment sync — without it every webhook is rejected with a 403. |
| **Enable Bob Go rates at checkout** | Yes/No | No | Customers see live shipping rates at checkout. |
| **Show additional rate information** | Yes/No | No | Displays delivery timeframes and additional service level descriptions alongside rates. |
| **Enable order push** | Yes/No | No | Automatically pushes orders to Bob Go for fulfillment when they are placed or updated. |
| **Enable fulfillment sync** | Yes/No | No | Syncs fulfillments and tracking updates from Bob Go back to Magento via webhooks. Also drives the hourly reconciliation cron. |
| **Notify customer on shipment** | Yes/No | Yes | Sends an email notification to the customer when a shipment is created from a Bob Go fulfillment. |
| **Maximum rates to show** | Number | 20 | Ceiling on how many Bob Go rates appear at checkout. |
| **Send product options to Bob Go** | Yes/No | Yes | Forwards the variant, custom options and personalisation text the customer chose, so they appear on the Bob Go picking list. |
| **Product options not to send** | Textarea | — | Comma- or newline-separated option keys to withhold. A blocklist, so newly added product options flow without editing this. |
| **Length / Width / Height attribute code** | Text | — | Product attributes holding dimensions in cm. Magento has no native ones, so leave blank unless you've created them. |
| **Collection address** | Group | — | Optional. Where goods actually ship from, if that isn't the address under Store Information. Each field falls back individually when left blank. |
| **Suburb field label / help text** | Text | — | Overrides the checkout labels. Defaults to "Suburb" and "Required for shipping accuracy". |

The **Connection** row above the API key is read-only. It shows whether Bob Go has
actually accepted the stored credentials, updated from real traffic — a key revoked
on the Bob Go side will show as rejected here without you having to press anything.

Additional configuration:

| Path | Description |
|---|---|
| `general/store_information/suburb` | Origin suburb used as `local_area` on rate requests, unless a collection address is set (see below) |

### Store suburb

To improve rate accuracy, set your store's suburb under **Stores > Configuration > General > General > Store Information > Suburb**. The value is included as the collection address `local_area` on every rate request.

For the destination side, customers fill in a suburb field that the extension injects into the checkout shipping address form. That value flows through to the order's shipping address custom attribute and is used as `local_area` on the order push payload.

## How it works

### Rates at checkout

1. The customer enters their shipping address at checkout
2. The extension builds a rate request: collection address (your store), delivery address (customer), cart items with weights normalised to kilograms
3. POST to `rates-at-checkout` on the v2 API using the `street_address` / `local_area` / `zone` / `country` / `code` address shape
4. Available service levels are returned with prices and (optionally) delivery timeframes, and rendered as shipping options

### Order push

Order push is **asynchronous**. Nothing talks to Bob Go while a customer or an admin
is waiting.

1. `OrderSaveObserver` listens on `sales_order_save_after` and writes one row to the
   `bobgo_order_sync_queue` outbox. That's all it does — no API call.
2. `Cron\PushOrders` runs every minute, takes what's due, and sends it. New orders
   (no `bobgo_order_id` yet) are POSTed to `/v2/orders`; existing ones are PATCHed,
   but only if the canonicalised payload hash differs from the last successful sync,
   so unchanged saves never hit the API.
3. A failure leaves the row queued with a backoff (1 min, 5, 15, 60), so a
   transient API problem resolves itself. After 10 attempts it gives up and logs.
4. Cancelling or completing an order in Magento is forwarded separately, as a PATCH
   carrying just the id and status.
5. The Magento `entity_id` is sent as `channel_ref_id` (an immutable idempotency
   key) and `increment_id` as `channel_order_number`.

Not every order is pushed. Virtual and downloadable orders never are — they have no
shipping address, so Bob Go can't do anything with them. Orders awaiting payment
aren't either, until the payment lands, so abandoned card attempts don't fill your
Bob Go account with orders that will never ship.

**Why the queue instead of doing it inline?** `sales_order_save_after` fires during
checkout, on every admin order save, on invoice creation and on shipment creation.
Doing the API call there meant all of those waited for Bob Go, and a failure had no
retry — the order sat wrong until somebody re-saved it. If you want to see what's
pending, it's one query against `bobgo_order_sync_queue`.

### Webhook fulfillment sync

When fulfillment sync is enabled, the extension subscribes to three topics on Bob Go: `fulfillment/created`, `tracking/updated`, and `order/updated`. Delivery URL is `{your-store-url}/bobgo/webhook/receive`.

Inbound flow for every webhook:

1. **HMAC verification** — the raw body is verified against the `Bobgo-Webhook-Signature` header (HMAC-SHA256, base64, constant-time compare). Failed verification → 403, body never inspected. Rejected bodies are truncated to 256 bytes before persistence.
2. **Fulfillment-sync gate** — even with a valid signature, the request is short-circuited to 200 (no processing) if the merchant has disabled fulfillment sync. Stale subscriptions can no longer mutate orders after a disable.
3. **JSON parse + topic resolution** — topic comes from the header, with a payload-shape fallback for legacy events
4. **Race-safe `event_id` claim** — a `webhook_claim` row is INSERTed under the `UNIQUE (event_id, direction)` constraint; concurrent deliveries of the same event lose the race and 200 cleanly without reprocessing. NULL `event_id`s are not deduped (the constraint allows multiple NULLs).
5. **Order resolution** — the payload is matched to a local order through a strict
   ladder (see below). If it can't be, the delivery is acknowledged with 200 and
   left alone.
6. **Route** — each handler is thin: it records the webhook, then re-fetches the
   authoritative fulfilment state from Bob Go and reconciles against that.
   `order/updated` additionally cancels the Magento order when Bob Go says the
   order was cancelled.
7. **Outcome** — on success the claim row is upgraded to `success=1`. On a
   *transient* failure the claim is released, a failure row is written with
   `event_id = NULL` (so the slot stays free for Bob Go's retry), and the response
   is 500 to trigger it. On an *unexpected* failure the claim row stays, so retries
   are 200'd at the dedup gate rather than re-running broken code; an operator can
   delete the row to allow a replay.

**Webhooks are triggers, not data.** No handler patches fulfilment state from the
webhook body — they all re-fetch `GET /v2/order-fulfillments` and reconcile against
what Bob Go says. That means a `tracking/updated` arriving before its
`fulfillment/created` is harmless (either one creates whatever is missing), and it
means reconciliation can create a shipment for a webhook that never arrived at all.

**Why the resolution ladder matters.** Bob Go webhook subscriptions are
account-wide, so this endpoint receives events for every order on your Bob Go
account — including other channels', CSV imports and standalone manual shipments.
Magento hands every store the same `increment_id` sequence starting at `000000001`,
so an order number alone is no evidence at all that an event belongs to this store.
Resolution therefore prefers `channel_ref_id` (the Magento entity id we sent
ourselves), requires exactly one match, refuses to re-point an order that is already
linked to a different Bob Go order, and never matches an order number against a
stored Bob Go id.

**And why "not ours" gets a 200.** Bob Go counts any non-2xx as a delivery failure
and disables the whole subscription after three days without a success — emailing
you, not the integration. So anything authentic but not actionable is acknowledged.
4xx is reserved for genuinely malformed or unauthenticated input.

Shipments are deduped by the Bob Go fulfilment id (stored on the shipment row) and
by tracking number. Line items are matched by the `bobgo_order_item_id` link first,
then by SKU, popping from a per-SKU queue so duplicate SKUs aren't collapsed. A
fulfilment whose scope can't be determined is refused rather than shipped as
"everything".

### Reconciliation (safety net)

Hourly cron (`Cron\Reconcile` → `ReconciliationService::run`):

1. Loads up to 100 orders that have a `bobgo_order_id` and are in `new` /
   `processing` / `holded` (always), or `complete` within the last 14 days (to catch
   late tracking checkpoints). Paged by a cursor, so successive runs work through
   the whole population rather than re-checking the same first hundred forever.
2. Runs each one through **the same code path the webhooks use** — refresh from
   `GET /v2/order-fulfillments`, full-replace `sales_order.bobgo_shipments`, and
   create any Magento shipment Bob Go knows about that we don't.
3. Writes only when something actually changed, so quiet runs are no-ops.
4. Once a day it also checks that the webhook subscriptions are still registered and
   active, and re-registers them if not.

That third point is the important one: because reconciliation shares the webhook's
code path, a delivery that never arrived — or arrived while the order was on hold —
is picked up here. Before 1.2.0 the webhook was the only thing that could create a
shipment, so a lost one meant an order shipped on Bob Go and never shipped in
Magento, with nothing to tell you.

The daily subscription check exists because nothing tells the integration when Bob
Go disables a subscription; only you get the email.

### Sync log

Every inbound and outbound event passes through `SyncLogger` and lands in `bobgo_sync_log`:

| Column | Purpose |
|---|---|
| `direction` | `inbound` / `outbound` |
| `event_type` | `webhook_claim`, `webhook_received`, `webhook_rejected`, `webhook_unknown_topic`, `fulfillment_received`, `tracking_updated`, `order_created`, `order_updated_outbound`, `reconciliation_fetched` |
| `event_id` | Provider-issued id (used for inbound dedup; deliberately `NULL` on transient-failure rows so the unique slot stays free for the retry) |
| `payload` | JSON request/response body, capped at 64 KB and with PII fields redacted (see [PII redaction](#pii-redaction)) |
| `http_status` | Observed/returned HTTP status |
| `success` | Outcome flag |
| `retry_count` | Reserved for future use |
| `order_id` | Linked Magento `entity_id` when available |
| `created_at` | Timestamp |

Indexed on `event_id`, `order_id`, `created_at`. The `(event_id, direction)` pair carries a **UNIQUE** constraint, which is what makes the webhook dedup race-safe — the second concurrent delivery hits a duplicate-key error rather than racing past the check.

A daily cron (`Cron\PruneSyncLog`, scheduled `15 3 * * *` UTC) deletes rows older than 30 days. Active `webhook_claim` rows are kept regardless so an in-flight delivery can't be pruned mid-flight.

### Admin order panel

The admin order detail page gains a Bob Go panel showing:

- Bob Go order id
- Sync status (`pending` / `success` / `failed`)
- Last synced / last webhook timestamps
- Bob Go shipments — tracking numbers, courier, service level, status (full-replaced by reconciliation)
- A POST-only **Resync with Bob Go** button that re-pushes the order and refetches authoritative fulfilment state

### Shipment tracking

There are two tracking surfaces in the extension:

1. **Tracking popup (always on)** — overrides Magento's default tracking popup with a custom view showing live status from the Bob Go API, shipment event timeline, and direct carrier tracking URLs. Reachable from the customer's order history once a shipment has been created.

2. **Standalone tracking page** — `/bobgo/tracking/index`. Currently **hidden by default** (controlled by the `carriers/bobgo/enable_track_order` config flag, which is itself hidden from the admin UI). When enabled, it accepts a customer-entered order number or tracking reference. The endpoint is hardened in three layers:
   - GET renders an empty form; only POST with a valid form_key performs a lookup.
   - The input must match either a local order's `increment_id` OR a tracking number already recorded on a local shipment. Misses 404 silently without hitting Bob Go — this prevents the endpoint from being used as a tracking-reference oracle against the merchant's Bob Go account.
   - Even with both gates passed, the call inherits the API client's 15 s timeout so a Bob Go outage can't hang the customer.

   The standalone page should remain disabled until merchants explicitly need it; the tracking popup covers the normal customer flow.

## Security

### HMAC webhook verification

Every inbound webhook body is HMAC-SHA256 signed by Bob Go using the merchant-issued webhook signing secret. The extension recomputes the signature over the same raw byte sequence and compares in constant time. Any of these fail closed with a 403, and the body is never inspected:

- Missing or empty `Bobgo-Webhook-Signature` header
- Webhook signing secret not configured in admin
- Signature mismatch

Rejected request bodies are written to the sync log, truncated to 256 bytes so an unauthenticated attacker can't bloat the table with sustained traffic.

### Encrypted credentials

The API key and the webhook signing secret are stored using Magento's `Backend\Encrypted` backend model. Accessors in `Model/Config/ApiConfig` decrypt on read via `EncryptorInterface::decrypt()`. API keys are masked in error logs (only the last four characters are shown).

### CSRF and admin hardening

The admin **resync** action is POST-only (`HttpPostActionInterface`), so Magento enforces form-key verification automatically. The button on the order panel is rendered as a `<form method="post">` with a hidden form key — accidental or malicious GET requests to the resync URL return a redirect, never a state change.

The public webhook endpoint exits Magento's CSRF flow (signature verification is the auth mechanism).

### PII redaction

`SyncLogger` redacts the following keys to `***` before persisting payloads to `bobgo_sync_log`:

- **Customer identifiers** — `customer_email`, `customer_phone`, `customer_name`, `customer_surname`, `telephone`, `phone`, `email`
- **Address fields** — `street_address`, `street`, `street1`, `street2`, `address_line_1`, `address_line_2`, `local_area`, `suburb`, `city`, `postcode`, `postal_code`, `zip`, `code` (Bob Go v2's postal-code key)

The canonical record of these values is the order itself; the log keeps shape and identifiers, not raw PII. The redaction recurses into nested arrays, so a `delivery_address` block inside a webhook payload is scrubbed wherever it appears.

## Architecture

### Directory structure

```
BobGroup/BobGo/
├── Api/
│   ├── BobGoApiClient.php          # HTTP client (Bearer auth + channel-identifier)
│   ├── BobGoApiException.php       # Custom exception with status + body
│   └── OrderMapperInterface.php    # Order → API payload contract
├── Block/
│   ├── Adminhtml/Order/View/
│   │   └── BobGoInfo.php           # Admin order detail panel block
│   ├── Adminhtml/System/Config/
│   │   └── ConnectionStatus.php    # Credential health row on the config screen
│   ├── System/Config/Form/Field/
│   │   └── Version.php             # Version display in admin config
│   ├── TrackingBlock.php           # Tracking page block
│   └── TrackOrderLink.php          # Customer-account tracking link (gated)
├── Controller/
│   ├── Adminhtml/Order/
│   │   └── Resync.php              # POST-only admin resync action
│   ├── Adminhtml/SyncLog/
│   │   └── Index.php               # Sync log grid page
│   ├── Tracking/Index.php          # Customer-facing tracking page (disabled by default)
│   └── Webhook/Receive.php         # HMAC-verified webhook receiver
├── Cron/
│   ├── PushOrders.php              # Every minute — drains the order-push outbox
│   ├── Reconcile.php               # Hourly — reconciliation + daily webhook health check
│   └── PruneSyncLog.php            # Daily — prunes bobgo_sync_log via SyncLogRetentionService
├── Helper/Data.php                 # Module helper (extension version)
├── Model/
│   ├── Carrier/
│   │   ├── BobGo.php               # Carrier (rates, tracking)
│   │   └── AdditionalInfo.php      # Pulls suburb/company/phone from request body
│   ├── Config/ApiConfig.php        # Centralised config + encrypted-field access
│   ├── ResourceModel/
│   │   ├── SyncLog.php             # bobgo_sync_log resource model
│   │   └── SyncLog/Collection.php  # Collection class
│   ├── AdminNotification/
│   │   └── FailedSyncMessage.php   # Admin banner counting failed order syncs
│   ├── ResourceModel/SyncLog/Grid/
│   │   └── Collection.php          # SearchResult collection backing the grid
│   ├── Source/Environment.php      # Sandbox/Production select
│   └── SyncLog.php                 # bobgo_sync_log model + event/direction constants
├── Observer/
│   ├── ConfigChangeObserver.php    # Tests API on config save; manages webhook subscriptions
│   ├── ModifyShippingDescription.php # Cleans bobgo carrier description on placement
│   └── OrderSaveObserver.php       # Queues an order push on save (no API call)
├── Plugin/
│   ├── Checkout/Block/
│   │   └── LayoutProcessorPlugin.php  # Injects suburb field into checkout
│   ├── Quote/
│   │   └── ToOrderAddressPlugin.php   # Copies quote-address suburb → order-address ext attr
│   └── OrderRepositoryPlugin.php      # Loads/saves bobgo_order_id extension attribute
├── Service/
│   ├── ConnectionHealth.php           # Credential state, folded from real API traffic
│   ├── DisplayOptionsMapper.php       # Product options → Bob Go display_options
│   ├── FulfilmentSyncService.php      # Authoritative refresh + shipment creation
│   ├── FulfillmentService.php         # Thin webhook handlers
│   ├── InboundGuard.php               # Stops inbound-driven saves echoing back out
│   ├── OrderMapper.php                # Order → API payload (weight + suburb handling)
│   ├── OrderPushService.php           # POST/PATCH orders with sync-hash dirty check
│   ├── OrderResolution.php            # Outcome of webhook order resolution
│   ├── OrderResolver.php              # Payload → local order, via a strict ladder
│   ├── OrderSyncPolicy.php            # Which orders and statuses reach Bob Go
│   ├── OrderSyncQueue.php             # Outbox between the observer and the cron
│   ├── RateCache.php                  # Rate cache (memo + TTL by address precision)
│   ├── ReconciliationService.php      # Hourly batch over the refresh path
│   ├── StoreScope.php                 # Runs work in the order's own store scope
│   ├── SyncLogger.php                 # Single writer for bobgo_sync_log + PII redaction + atomic claim/release
│   ├── SyncLogRetentionService.php    # 30-day prune logic for bobgo_sync_log
│   ├── TransientWebhookException.php  # Marker exception that routes webhook failures to a 500
│   ├── WebhookSignatureVerifier.php   # HMAC-SHA256 verification
│   └── WebhookSubscriptionService.php # Subscription lifecycle + daily health check
├── Setup/Uninstall.php                # Deregisters webhooks, removes config and flags
├── etc/
│   ├── acl.xml                        # BobGroup_BobGo::sync_log resource
│   ├── adminhtml/                     # Admin events, routes, menu, system config
│   ├── frontend/                      # Frontend DI + routes
│   ├── config.xml                     # Default config values
│   ├── crontab.xml                    # Push (1 min), reconcile (hourly), prune (daily)
│   ├── db_schema.xml                  # Declarative schema (see below)
│   ├── db_schema_whitelist.json       # Whitelist for declarative schema
│   ├── di.xml                         # DI: preferences, plugins, arguments
│   ├── events.xml                     # Global events (fires in admin and cron too)
│   ├── extension_attributes.xml       # suburb (quote + order address), bobgo_order_id (order)
│   └── module.xml                     # Module definition + sequence deps
├── view/
│   ├── adminhtml/                     # Order panel, sync-log grid ui_component
│   └── frontend/                      # Checkout JS, tracking templates, layouts
├── composer.json
├── phpstan.neon                       # PHPStan level 2 config
├── phpunit.xml.dist
├── bump-version.sh                    # Version bump helper
└── registration.php                   # class_exists-guarded Magento registration
```

### Database additions

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
| `sales_order` | `bobgo_status_synced` | `varchar(32)` | Last status forwarded to Bob Go, so a transition isn't re-sent |
| `sales_order_item` | `bobgo_order_item_id` | `varchar(255)` | Bob Go line-item id |
| `sales_shipment` | `bobgo_fulfillment_id` | `varchar(128)` | Bob Go fulfilment id (idempotency) |

And two new tables:

| Table | Purpose |
|---|---|
| `bobgo_order_sync_queue` | The order-push outbox. Columns: `entity_id`, `order_id`, `attempts`, `next_attempt_at`, `created_at`. **UNIQUE** on `order_id`, which makes enqueueing idempotent — an order saved ten times in one request is pushed once. |
| `bobgo_sync_log` | One row per inbound or outbound event. Columns: `entity_id`, `order_id`, `event_type`, `direction`, `event_id`, `payload`, `http_status`, `success`, `retry_count`, `created_at`. Indexed on `event_id`, `order_id`, `created_at`. **UNIQUE** on `(event_id, direction)` — load-bearing for race-safe webhook dedup. |

### Key design decisions

- **Centralised API client** — All Bob Go API calls go through `BobGoApiClient` (Bearer auth, channel-identifier header, structured exceptions). Per-endpoint timeouts: 8 s for `rates-at-checkout` (checkout-blocking), 15 s default for everything else, 5 s connect timeout. Error logs cap response bodies at 512 bytes so 4xx echoes don't recur PII into `system.log`.
- **Webhook verification before processing** — `Receive` calls `WebhookSignatureVerifier` first; nothing else looks at the body until it passes
- **Race-safe webhook dedup** — `claimEventId()` does an atomic INSERT under a UNIQUE constraint; concurrent deliveries can't both pass a "have I seen this?" check. Failure rows are written with `event_id = NULL` so the unique slot stays free for the retry.
- **Transient vs unexpected failures** — `TransientWebhookException` signals "Bob Go should retry" (claim released, 500 response). Any other `Throwable` is treated as "don't know if it's safe to retry, keep the claim, operator decides."
- **Sync-hash dirty checking** — `OrderPushService::updateOrder()` skips PATCHes when nothing has actually changed
- **Weight conversion at payload-build time** — LBS → KG happens in `OrderMapper`, not as a `beforeSave` mutation, so the underlying order row is never corrupted by repeated saves
- **Region code resolution** — origin store region is stored by Magento as a numeric `region_id`; the carrier resolves it to a province code (e.g. "GP", "WC") via `RegionFactory` before sending to Bob Go
- **Webhooks are triggers, not data** — every inbound signal re-fetches authoritative fulfilment state from Bob Go and reconciles against it, rather than patching local state from the payload. That makes duplicate and out-of-order deliveries safe, and it means reconciliation — running the same code path — can create a shipment for a webhook that never arrived.
- **Acknowledge, don't reject** — Bob Go subscriptions are account-wide, so this endpoint receives events for orders that aren't ours. Anything authentic gets a 200 even when we do nothing with it, because Bob Go disables the whole subscription after three days without a successful delivery and only tells the merchant.
- **Never guess which order** — resolution walks a strict ladder preferring the Magento entity id we sent ourselves, requires exactly one match, and refuses to re-point an order already linked elsewhere. Magento's `increment_id` sequence is identical across stores, so an order number alone proves nothing.
- **Asynchronous order push** — the save observer only queues; a cron sends. Keeps the API off checkout and admin requests, and gives retries with backoff for free. A table rather than Magento's message queue, so there are no consumer processes to keep alive and the pending set is one `SELECT`.
- **Per-store scope on background paths** — cron has no store context and the webhook endpoint resolves a single delivery URL, so work is wrapped in the order's own store scope. Without that, a multi-store order would be pushed with another store's credentials.
- **Reconciliation as safety net** — the hourly cron only writes when something differs, and is paged by a cursor so it works through the whole population instead of re-checking the same first hundred. Two scoped queries: active states (NEW/PROCESSING/HOLDED) always; COMPLETE only within a 14-day lookback.
- **Single sync log writer** — `SyncLogger` is the only path that writes `bobgo_sync_log`, with built-in PII redaction (customer + address fields) and length capping
- **Non-blocking order push** — API errors are caught, logged and recorded on the order; they never prevent it being saved. A 2xx that returns no usable Bob Go order id is treated as a *failure*, because recording it as synced would orphan the order: invisible to reconciliation, unresolvable by webhooks, and permanently suppressed by the dirty check.
- **Fail soft at checkout** — the carrier catches everything and simply doesn't appear. Magento calls `collectRates()` with no try/catch of its own, so anything escaping would 500 the checkout shipping step for every customer, whatever other carriers the store offers.
- **Suburb flows quote → order** — layout processor adds the field; checkout JS mixin copies its value into `extension_attributes`; `ToOrderAddressPlugin` carries the value across the quote→order address conversion; `OrderMapper` reads it back as `local_area` on outbound payloads.
- **Extension attributes** — `suburb` is declared on both `Quote\Api\Data\AddressInterface` and `Sales\Api\Data\OrderAddressInterface`; `bobgo_order_id` is exposed on the order interface for REST consumers
- **POST-only admin resync** — implements `HttpPostActionInterface` so Magento enforces form-key verification automatically

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

1. The **Webhook signing secret** field is populated in admin (it's stored encrypted, so you can't see the value once saved — re-paste it to be sure)
2. The secret matches what you registered the webhook with on the Bob Go side
3. Filter `bobgo_sync_log` on `event_type = 'webhook_rejected'` to see the captured request snippet and reason

### Fulfillment sync not working

1. **Enable fulfillment sync** is set to **Yes** and a **webhook signing secret** is set
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

Upgrading to 1.2.0 changes several constructors and adds a table and a column, so
run `setup:upgrade` **and** a full DI recompile, not just a cache flush:

```bash
php bin/magento setup:upgrade
rm -rf generated/code/* generated/metadata/*
php bin/magento setup:di:compile
php bin/magento cache:flush
```

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
- **Issues:** [GitHub issues](https://github.com/nicholasgousis/bobgo-magento-extension/issues)

## License

This project is licensed under the [GPL-3.0-or-later](LICENSE) license.
