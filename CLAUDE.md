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

## CI/CD

GitLab CI (`.gitlab-ci.yml`):
- **`dev` branch push:** Runs `make-zip.sh`, uploads to S3 (`magento-plugin.dev.bobgo.co.za`)
- **Git tag (from `prod` branch only):** Downloads archive, uploads to S3 production bucket with tagged and latest versions

## Architecture

### Core Carrier (`Model/Carrier/BobGo.php`)
The main shipping carrier class (~1,120 lines). Extends `AbstractCarrierOnline`, implements `CarrierInterface`. Carrier code: `bobgo`. Handles:
- `collectRates()` — Builds payload with origin/destination/items, calls Bob Go rates API, returns Magento rate result objects
- `triggerRatesTest()` / `triggerWebhookTest()` — Admin connectivity tests
- `encodeWebhookAndPostRequest()` — Sends HMAC-SHA256 signed webhooks

### API Endpoints (`Model/Carrier/UData.php`)
Static constants for Bob Go API URLs (rates, webhooks, tracking). Currently pointing to **dev** environment (`api.dev.bobgo.co.za`). These must be changed for production releases.

### Observers (`Observer/`)
- **ConfigChangeObserver** — Listens to `admin_system_config_changed_section_carriers`, runs connectivity tests when settings are toggled
- **OrderCreateWebhook** — Listens to `sales_order_save_after`, sends order data to Bob Go webhook
- **ModifyShippingDescription** — Listens to `sales_order_place_before`

### Plugins (`Plugin/`)
- **LayoutProcessorPlugin** — Injects a custom "suburb" field into the checkout shipping address form
- **AddWeightUnitToOrderPlugin** — Converts weights from lbs to kg (×0.45359237) on order save

### Suburb Field
A custom extension attribute (`suburb`) on `Magento\Quote\Api\Data\AddressInterface` (defined in `etc/extension_attributes.xml`). Required for South African shipping rate accuracy. Added to checkout via `LayoutProcessorPlugin` and included in rate request payloads.

### Weight Handling
Items are converted to grams for the API. Supports KGS and LBS store weight units. Max 500kg per item validation.

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

## Branches

- **`dev`** — Main development branch (CI deploys to dev S3)
- **`prod`** — Production branch (tagged releases deploy to prod S3)
