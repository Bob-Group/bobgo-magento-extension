# Bob Go Shipping Extension for Magento 2

Real-time shipping rates, order management, and shipment tracking for South African e-commerce — powered by [Bob Go](https://www.bobgo.co.za).

![Version](https://img.shields.io/badge/version-1.0.62-blue)
![PHP](https://img.shields.io/badge/php-7.4%20|%208.0%20|%208.2-8892BF)
![Magento](https://img.shields.io/badge/magento-2.3%2B-f46f25)
![License](https://img.shields.io/badge/license-GPL--3.0--or--later-green)

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
  - [Composer (Recommended)](#composer-recommended)
  - [Manual Installation](#manual-installation)
- [Configuration](#configuration)
  - [Getting an API Key](#getting-an-api-key)
  - [Admin Settings](#admin-settings)
  - [Configuration Reference](#configuration-reference)
  - [Store Suburb](#store-suburb)
- [How It Works](#how-it-works)
  - [Rates at Checkout](#rates-at-checkout)
  - [Order Push](#order-push)
  - [Fulfillment Sync](#fulfillment-sync)
  - [Shipment Tracking](#shipment-tracking)
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
- **Automatic Order Push** — Orders are automatically sent to Bob Go for fulfillment when placed (POST for new orders, PATCH for updates)
- **Fulfillment Sync** — Receive real-time webhook notifications from Bob Go to automatically create shipments in Magento
- **Shipment Tracking** — Custom tracking popup with live status, event timeline, and tracking URLs from the Bob Go API
- **Custom Suburb Field** — Adds a suburb/local area field to the checkout address form for accurate South African shipping rates
- **Automatic Weight Conversion** — Converts item weights from LBS to KG when needed, ensuring correct rate calculations
- **Admin Connectivity Testing** — Automatic API connectivity checks when settings are toggled in the admin panel
- **Automatic Webhook Management** — Webhook subscriptions are created and cleaned up automatically when fulfillment sync is enabled or disabled

## Requirements

| Requirement | Version |
|---|---|
| PHP | ^7.4 \|\| ^8.0 \|\| ^8.2 |
| Magento | 2.3+ |
| Bob Go account | [Sign up at bobgo.co.za](https://www.bobgo.co.za) |
| Bob Go API key | Obtained from your Bob Go dashboard |

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
3. Copy the API key for use in the Magento admin configuration

### Admin Settings

Navigate to **Stores > Configuration > Sales > Shipping Methods > Bob Go** in the Magento admin panel.

### Configuration Reference

| Setting | Type | Default | Description |
|---|---|---|---|
| **Environment** | Select | Sandbox | Choose between `Sandbox` (testing) and `Production` (live). Sandbox uses `api.sandbox.bobgo.co.za`, production uses `api.bobgo.co.za`. |
| **API Key** | Encrypted text | — | Your Bob Go API key. Stored encrypted in the database. |
| **Enable Bob Go rates at checkout** | Yes/No | No | When enabled, customers see live shipping rates at checkout as configured on the Bob Go platform. |
| **Show additional rate information** | Yes/No | No | Displays delivery timeframes and additional service level descriptions alongside rates. |
| **Enable order push** | Yes/No | No | Automatically pushes orders to Bob Go for fulfillment when they are placed. |
| **Enable fulfillment sync** | Yes/No | No | Syncs fulfillments from Bob Go back to Magento to create shipments automatically via webhooks. |
| **Notify customer on shipment** | Yes/No | Yes | Sends an email notification to the customer when a shipment is created from a Bob Go fulfillment. |

### Store Suburb

To improve rate accuracy, you can set your store's suburb under **Stores > Configuration > General > General > Store Information > Suburb**. This value is included as the collection address local area when requesting shipping rates.

## How It Works

### Rates at Checkout

When a customer enters their shipping address at checkout:

1. The extension builds a rate request payload containing the collection address (your store), delivery address (customer), and cart items with weights in kilograms
2. The payload is sent to the Bob Go `rates-at-checkout` API endpoint using the v2 address format (`street_address`, `local_area`, `zone`, `country`, `code`)
3. Bob Go returns available shipping service levels with prices and optional delivery timeframes
4. The rates are displayed to the customer as shipping options

The custom suburb field in the checkout form provides the `local_area` value for the delivery address, which is important for accurate rate calculations in South Africa.

### Order Push

When order push is enabled and an order is saved:

1. The `OrderSaveObserver` listens to the `sales_order_save_after` event
2. For new orders (no `bobgo_order_id`), the order data is POSTed to Bob Go
3. For existing orders, the data is PATCHed to keep Bob Go in sync
4. The returned `bobgo_order_id` is saved to the order for future reference
5. Errors are caught and logged — order saving is never blocked by API failures

A re-entrancy guard prevents infinite loops when saving the `bobgo_order_id` back to the order triggers the observer again.

### Fulfillment Sync

When fulfillment sync is enabled:

1. The extension automatically subscribes to Bob Go webhook topics: `fulfillment/created` and `tracking/updated`
2. Bob Go sends webhook events to `{your-store-url}/bobgo/webhook/receive`
3. The webhook controller routes events by topic:
   - **`fulfillment/created`** — Creates a Magento shipment with tracking numbers and item quantities. Includes idempotency checks to prevent duplicate shipments.
   - **`tracking/updated`** — Adds tracking numbers to existing shipments and posts status updates as order comments.
4. When fulfillment sync is disabled, webhook subscriptions are automatically cleaned up

Webhook payloads are verified and the endpoint bypasses CSRF validation (as required for external webhook receivers).

### Shipment Tracking

The extension overrides Magento's default tracking popup with a custom view that displays:

- Live tracking status from the Bob Go API
- Shipment event timeline
- Tracking URLs for direct carrier tracking

When customers click "Track" on their order, they see real-time tracking information fetched from Bob Go rather than the default Magento tracking display.

## Architecture

### Directory Structure

```
BobGroup/BobGo/
├── Api/                          # API client and interfaces
│   ├── BobGoApiClient.php        # Centralized HTTP client for Bob Go API
│   ├── BobGoApiException.php     # API-specific exception class
│   └── OrderMapperInterface.php  # Order data mapping contract
├── Block/                        # View blocks
│   ├── System/Config/Form/Field/
│   │   └── Version.php           # Admin version display
│   ├── TrackingBlock.php         # Custom tracking popup block
│   └── TrackOrderLink.php        # Customer account tracking link
├── Controller/
│   ├── Tracking/Index.php        # Frontend tracking page
│   └── Webhook/Receive.php       # Webhook endpoint (POST /bobgo/webhook/receive)
├── etc/                          # Magento configuration
│   ├── adminhtml/
│   │   ├── events.xml            # Admin events (config change observer)
│   │   └── system.xml            # Admin configuration fields
│   ├── frontend/
│   │   ├── di.xml                # Frontend DI overrides
│   │   └── routes.xml            # Frontend route definitions
│   ├── config.xml                # Default configuration values
│   ├── db_schema.xml             # Database schema additions
│   ├── di.xml                    # Dependency injection configuration
│   ├── events.xml                # Frontend/global events
│   ├── extension_attributes.xml  # Suburb extension attribute
│   ├── module.xml                # Module declaration and dependencies
│   └── webapi.xml                # REST API endpoint definitions
├── Helper/Data.php               # General helper utilities
├── Model/
│   ├── Carrier/
│   │   ├── BobGo.php             # Main carrier class (rates, tracking)
│   │   └── AdditionalInfo.php    # Rate additional info model
│   ├── Config/ApiConfig.php      # Centralized API configuration
│   └── Source/                   # Admin select option sources
├── Observer/
│   ├── ConfigChangeObserver.php  # Handles admin config save events
│   ├── ModifyShippingDescription.php  # Pre-order shipping description
│   └── OrderSaveObserver.php     # Order push to Bob Go
├── Plugin/
│   ├── AddWeightUnitToOrderPlugin.php      # LBS to KG conversion
│   ├── Checkout/Block/LayoutProcessorPlugin.php  # Suburb field injection
│   └── OrderRepositoryPlugin.php           # Order repository extensions
├── Service/
│   ├── FulfillmentService.php           # Shipment creation from webhooks
│   ├── OrderMapper.php                  # Order data transformation
│   ├── OrderPushService.php             # Order push API calls
│   └── WebhookSubscriptionService.php   # Webhook lifecycle management
├── view/frontend/
│   ├── layout/                   # Layout XML files
│   ├── templates/tracking/       # Tracking popup and page templates
│   └── web/js/                   # Checkout JS (suburb field, rate validation)
├── composer.json
├── package.json
└── registration.php
```

### Database Additions

The extension adds two columns via declarative schema (`etc/db_schema.xml`):

| Table | Column | Type | Description |
|---|---|---|---|
| `sales_order` | `bobgo_order_id` | `varchar(255)`, nullable | Stores the Bob Go order ID after a successful order push |
| `sales_order_item` | `bobgo_order_item_id` | `varchar(255)`, nullable | Stores the Bob Go order item ID for line-item mapping |

### Key Design Decisions

- **Centralized API client** — All Bob Go API calls go through `BobGoApiClient`, which handles authentication, environment switching (sandbox/production), and error responses
- **Non-blocking order push** — API errors during order push are caught and logged; they never prevent the order from being saved in Magento
- **Re-entrancy guard** — `OrderSaveObserver` uses a `$processing` flag to prevent infinite loops when saving the `bobgo_order_id` back to the order
- **Declarative schema** — Database columns are added via `db_schema.xml` (Magento 2.3+ best practice) rather than install/upgrade scripts
- **Extension attributes** — The suburb field is defined as an extension attribute on the quote address interface, following Magento's preferred extensibility pattern

## Troubleshooting

### No shipping rates at checkout

1. Verify the extension is enabled: **Stores > Configuration > Sales > Shipping Methods > Bob Go > Enable Bob Go rates at checkout** must be **Yes**
2. Check your API key is correctly entered
3. Confirm the environment setting matches your API key (sandbox key for sandbox, production key for production)
4. The shipping address must include a valid South African postal code
5. Check `var/log/exception.log` and `var/log/system.log` for API errors

### Orders not appearing in Bob Go

1. Verify **Enable order push** is set to **Yes**
2. Check that the API key is valid and matches the selected environment
3. Review `var/log/system.log` for "Bob Go" entries related to order push errors
4. Orders with API errors are logged but not blocked — check logs for details

### Fulfillment sync not working

1. Verify **Enable fulfillment sync** is set to **Yes**
2. Ensure your store's base URL is publicly accessible (Bob Go must be able to reach `{your-store-url}/bobgo/webhook/receive`)
3. Check that webhook subscriptions were created successfully in the logs
4. Review `var/log/system.log` for webhook-related error messages

### API key errors

- API keys are stored encrypted. If you see authentication errors after migrating or restoring a database, re-enter the API key in the admin panel
- Sandbox and production API keys are different — ensure the correct environment is selected

### Weight calculation issues

- The extension converts all weights to kilograms for the API
- If your Magento store uses LBS as the weight unit, weights are automatically converted (multiplied by 0.45359237)
- Individual item weights above 500kg are flagged as likely errors

### DI compilation errors after upgrade

If you encounter dependency injection errors after upgrading:

```bash
rm -rf generated/code/* generated/metadata/*
php bin/magento setup:di:compile
php bin/magento cache:flush
```

## Contributing

1. Fork the repository
2. Create a feature branch from `dev`
3. Make your changes and ensure tests pass: `composer test`
4. Submit a pull request to `dev`

**Branch model:**
- `main` — Stable/production releases
- `dev` — Active development (target your PRs here)

## Support

- **Website:** [bobgo.co.za](https://www.bobgo.co.za)
- **Email:** support@bobgo.co.za
- **Issues:** [GitHub Issues](https://github.com/nicholasgousis/bobgo-magento-extension/issues)

## License

This project is licensed under the [GPL-3.0-or-later](LICENSE) license.
