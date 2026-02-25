# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Bob Go Shipping Extension for Magento 2 (`BobGroup_BobGo`). Provides shipping rate calculation, order webhooks, and shipment tracking for South African e-commerce via the Bob Go API.

- **Namespace:** `BobGroup\BobGo`
- **PHP:** ^7.4 || ^8.0 || ^8.2
- **Version source of truth:** `package.json` (synced to `composer.json` and `etc/module.xml`)

## Build & Version Commands

```bash
# Build distribution zip (requires jq, perl)
./make-zip.sh

# Install node dependencies
npm install

# Sync version from package.json to composer.json and etc/module.xml
npm run update-version-files
```

Version is auto-incremented on commit via Husky pre-commit hook (`.husky/pre-commit`), which runs `npm version patch`, syncs version files, and stages them.

## Testing

Unit tests use PHPUnit and live under `Test/Unit/`.

```bash
# Run all unit tests
vendor/bin/phpunit Test/Unit/

# Run a single test file
vendor/bin/phpunit Test/Unit/Model/Carrier/BobGoTest.php

# Run a specific test method
vendor/bin/phpunit --filter testCollectRates Test/Unit/Model/Carrier/BobGoTest.php
```

Note: Tests require Magento framework dependencies. They are mock-based and do not need a running Magento instance.

## Architecture

### Core Carrier (`Model/Carrier/BobGo.php`)
The main shipping carrier class (~1,120 lines). Extends `AbstractCarrierOnline`, implements `CarrierInterface`. Carrier code: `bobgo`. Handles:
- `collectRates()` — Builds payload with `collection_address`/`delivery_address`/`items`, calls Bob Go `rates-at-checkout` API, returns Magento rate result objects
- `triggerRatesTest()` — Admin connectivity test for rates
- Rate request uses Bob Go API v2 format: `street_address`, `local_area`, `zone`, `country`, `code` (not the old `address1`/`suburb`/`province`/`country_code`/`postal_code` format)

### API Configuration (`Model/Config/ApiConfig.php`)
Centralized config: reads environment, API key (decrypted via `EncryptorInterface`), base URL, feature flags. Base URLs:
- **Sandbox:** `https://api.sandbox.bobgo.co.za/v2/`
- **Production:** `https://api.bobgo.co.za/v2/`

### Observers (`Observer/`)
- **ConfigChangeObserver** — Listens to `admin_system_config_changed_section_carriers`, runs connectivity tests when settings are toggled. Uses `ReinitableConfigInterface::reinit()` to read freshly saved config values.
- **OrderSaveObserver** — Listens to `sales_order_save_after`, pushes order data to Bob Go
- **ModifyShippingDescription** — Listens to `sales_order_place_before`

### Plugins (`Plugin/`)
- **LayoutProcessorPlugin** — Injects a custom "suburb" field into the checkout shipping address form
- **AddWeightUnitToOrderPlugin** — Converts weights from lbs to kg (×0.45359237) on order save

### Suburb Field
A custom extension attribute (`suburb`) on `Magento\Quote\Api\Data\AddressInterface` (defined in `etc/extension_attributes.xml`). Required for South African shipping rate accuracy. Added to checkout via `LayoutProcessorPlugin` and included in rate request payloads.

### Weight Handling
Items are converted to kg (`weight_kg`) for the API. Internally converts via grams first, then divides by 1000. Supports KGS and LBS store weight units. Max 500kg per item validation.

### Webhook Security
Webhooks use HMAC-SHA256 signatures sent in the `x-m-webhook-signature` header. The key is stored in Magento's encrypted admin config (`carriers/bobgo/webhook_key`).

## Key Configuration Paths

- Carrier settings: `carriers/bobgo/*`
- Admin UI config: `etc/adminhtml/system.xml`
- Default config values: `etc/config.xml`
- Module dependencies: `etc/module.xml`
- DI/plugins: `etc/di.xml`
- Events: `etc/events.xml`, `etc/adminhtml/events.xml`

## Remote Test Server

- **SSH:** `bitnami@ip-10-107-3-85` (Bitnami Magento AMI on AWS)
- **Magento root:** `/opt/bitnami/magento`
- **Extension path:** `/opt/bitnami/magento/app/code/BobGroup/BobGo/`
- **Generated code:** `/opt/bitnami/magento/generated/code/`
- **Generated metadata:** `/opt/bitnami/magento/generated/metadata/`
- **Cache dir:** `/opt/bitnami/magento/var/cache/`
- **Exception log:** `/opt/bitnami/magento/var/log/exception.log`
- **Permissions:** `var/` and `generated/` often need `sudo chmod -R 777` after compile

### Deployment workflow

```bash
# Rsync extension to server (from local Mac)
rsync -avz --exclude='node_modules' --exclude='.git' --exclude='vendor' --exclude='Test' \
  /Users/jacoroux/Documents/Projects/bobgo-magento-extension/ \
  bitnami@ip-10-107-3-85:/opt/bitnami/magento/app/code/BobGroup/BobGo/

# On server: recompile DI (needed after di.xml, events.xml, or constructor changes)
sudo rm -rf generated/code/* generated/metadata/*
php bin/magento setup:di:compile && sudo php bin/magento cache:flush

# On server: flush cache only (sufficient for most PHP logic changes)
sudo php bin/magento cache:flush

# On server: restart web server
sudo /opt/bitnami/ctlscript.sh restart apache

# IMPORTANT: Clear PHP opcache from web context after code changes
# Apache restart alone does NOT reliably clear opcache.
# Create a temp script, hit it from browser, then delete:
echo '<?php opcache_reset(); echo "cleared"; ?>' | sudo tee /opt/bitnami/magento/pub/opcache_reset.php
# Visit https://<domain>/opcache_reset.php in browser
sudo rm /opt/bitnami/magento/pub/opcache_reset.php
```

## Magento DI Gotchas

These are hard-won lessons from debugging the extension on the test server:

1. **Constructor param naming:** Never use common names like `$request` for custom constructor params in classes extending Magento core. Magento inherits DI argument mappings by **name** from parent classes, causing type mismatches. Use unique names (e.g., `$httpRequest`).
2. **Encrypted config values:** `scopeConfig->getValue()` returns the **raw encrypted** value for fields with `Backend\Encrypted`. You must use `EncryptorInterface::decrypt()` to get the plaintext.
3. **Stale config in observers:** During config save, the in-memory ScopeConfig cache is stale. Inject `ReinitableConfigInterface` and call `reinit()` before reading config in save observers.
4. **PHP opcache:** On the Bitnami server, restarting Apache does NOT reliably clear opcache. Must create a temp PHP script in `pub/` and hit it from the browser to call `opcache_reset()`.
5. **Permissions:** After `di:compile`, `var/` and `generated/` directories often need `sudo chmod -R 777` on the Bitnami instance.

## Branches

- **`main`** — Stable/production branch
- **`dev`** — Main development branch (default working branch)
