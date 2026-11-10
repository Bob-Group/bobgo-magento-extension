# Bob Go shipping extension for Magento 2

Live Bob Go shipping rates at checkout, orders sent to Bob Go automatically, and Bob Go shipments and tracking brought back into Magento. Built for South African stores using [Bob Go](https://www.bobgo.co.za).

![Version](https://img.shields.io/badge/version-1.2.0-blue)
![PHP](https://img.shields.io/badge/php-7.4%20%7C%208.1--8.4-8892BF)
![Magento](https://img.shields.io/badge/magento-2.4-f46f25)
![License](https://img.shields.io/badge/license-GPL--3.0--or--later-green)

**Upgrading from 1.0.x?** Read [Upgrading from 1.0.x](#upgrading-from-10x) before you install. Checkout rates and order notifications keep working through the upgrade, but there are a few things to know.

## Contents

- [What it does](#what-it-does)
- [Requirements](#requirements)
- [Installing](#installing)
- [Upgrading from 1.0.x](#upgrading-from-10x)
- [Connecting to Bob Go](#connecting-to-bob-go)
- [Settings](#settings)
- [How it works](#how-it-works)
- [Security](#security)
- [Troubleshooting](#troubleshooting)
- [For developers](#for-developers)
- [Support](#support)

## What it does

- **Rates at checkout.** Customers see your Bob Go rates, as configured under Rates at checkout in Bob Go, with optional delivery times.
- **Orders to Bob Go.** Each order is queued when it is saved and sent by a background job about a minute later, so checkout never waits for Bob Go. Changes are sent as updates, and failures are retried.
- **Shipments back to Magento.** When you fulfil an order in Bob Go, a signed webhook tells Magento, which creates the shipment with its tracking number. An hourly job catches anything a webhook missed.
- **Cancellations both ways.** Cancelling or completing an order in Magento is passed to Bob Go, and cancelling it in Bob Go cancels it in Magento.
- **Tracking for customers.** Magento's tracking popup shows live Bob Go status and checkpoints for Bob Go shipments.
- **A suburb field at checkout**, sent to Bob Go as the delivery area, because South African couriers price and route on it.
- **Something to look at when things go wrong.** A Bob Go panel on each order, a sync log under **Sales > Operations > Bob Go Sync Log**, a banner when orders fail to sync, and a **Connection** row in the settings that shows whether Bob Go accepts your key.

## Requirements

| | |
|---|---|
| Magento | 2.4. Tested on 2.4.6; 2.3 is untested. |
| PHP | 7.4, or 8.1 to 8.4. Every pull request is tested on 7.4, 8.1, 8.3 and 8.4. |
| Bob Go account | [bobgo.co.za](https://www.bobgo.co.za) |
| Bob Go API key | For order sync, shipment sync and tracking. See [Connecting to Bob Go](#connecting-to-bob-go). |
| Cron | Magento cron must be running. Orders are sent by a cron job. |
| A public store URL | Only for shipment sync: Bob Go has to reach `https://your-store/bobgo/webhook/receive`. |

## Installing

Upgrading from 1.0.x? Follow [Upgrading from 1.0.x](#upgrading-from-10x) instead.

**With Composer.** The package is not on Packagist, so add this repository first:

```bash
composer config repositories.bobgo vcs https://github.com/Bob-Group/bobgo-magento-extension.git
composer require bobgo/bobgo-magento-extension:^1.2
```

**From a zip.** Download the zip for the latest version from the [releases page](https://github.com/Bob-Group/bobgo-magento-extension/releases) and extract it into `app/code/BobGroup/BobGo/` in your Magento root.

Either way, then run:

```bash
php bin/magento module:enable BobGroup_BobGo
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento cache:flush
```

`php bin/magento module:status BobGroup_BobGo` should list the module as enabled. Then [connect it to Bob Go](#connecting-to-bob-go).

## Upgrading from 1.0.x

1.0.x had no API key: it fetched rates and told Bob Go about orders without one. 1.2.0 still does both until you add a key, so upgrading does not take Bob Go off your checkout. You can add the key whenever you're ready.

1. **Remove the old code first.** 1.2.0 deletes files that 1.0.x had, so copying over the top leaves them behind.
   - Installed from a zip: delete `app/code/BobGroup/BobGo/`, then extract the new zip there.
   - Moving to Composer: delete `app/code/BobGroup/BobGo/` before `composer require`. If both copies exist, Magento refuses to start because the module is registered twice.
2. **Run the upgrade.** `setup:upgrade` adds the new columns and tables, and several classes changed, so recompile rather than only flushing the cache:

   ```bash
   php bin/magento setup:upgrade
   rm -rf generated/code/* generated/metadata/*
   php bin/magento setup:di:compile
   php bin/magento cache:flush
   ```

3. **Check checkout.** Bob Go rates should still appear for a South African address.
4. **When you're ready, [connect with an API key](#connecting-to-bob-go).** That turns on what 1.0.x didn't have: shipments and tracking coming back from Bob Go, order updates, and the sync log.

What to expect after upgrading:

- **Rates may change if your store weighs in kilograms.** 1.0.x mistook kilograms for pounds and sent every weight at 0.45 of its real value. 1.2.0 sends the real weight, so quotes for kg stores will usually go up.
- **Order notifications carry on the 1.0.x way** (if you had **Enable webhooks** and a webhook key set), until order push is on with an API key. Then they stop by themselves, so Bob Go never hears about an order twice. Those two 1.0.x settings no longer appear in the admin, but their saved values are still used.
- **Older orders can be sent again.** Once order push is on, an order from before the switch is sent to Bob Go the next time it is saved, for example when you invoice or ship it. If Bob Go already has it from 1.0.x, it may refuse it as a duplicate, and the order shows as failed in the sync log. Switching on order push at a quiet time keeps that to a few orders.
- **Your store's address in Bob Go matters more.** 1.0.x identified your store by its host without `www.`. An API key is tied to a channel, and Bob Go matches the channel's address exactly. If your Bob Go channel is `shop.co.za` but Magento runs at `www.shop.co.za`, set **Channel identifier** to `shop.co.za`.

## Connecting to Bob Go

1. **Create the key in Bob Go.** Choose **Add sales channel**, then **Generate Magento API key**. For the store URL, enter your store's address exactly as Magento has it (**Stores > Configuration > General > Web > Base URLs**), for example `www.shop.co.za`. Bob Go identifies your store by it.
2. **Copy the API key and the secret.** Bob Go shows them once. The secret is your webhook signing secret, which Bob Go uses to sign every webhook it sends you. It is also under **Settings > Webhook subscriptions**.
3. **Enter them in Magento** under **Stores > Configuration > Sales > Delivery Methods > Bob Go** (**Shipping Methods** in older Magento versions):
   - **Environment**: Production, or Sandbox for a Bob Go sandbox key.
   - **API Key** and **Webhook Signing Secret**.
   - **Channel identifier**: leave empty unless the address on your Bob Go channel differs from your store URL.
   - Switch on what you want: **Enable Bob Go rates at checkout**, **Enable order push**, **Enable fulfillment sync**.
4. **Save.** A new key or environment is tested straight away, and so are rates when you switch them on; the result shows at the top of the page. Switching on fulfilment sync registers the webhooks with Bob Go. The **Connection** row says **Connected** once Bob Go has accepted the key.
5. **Set your store's address**, which rates are calculated from, under **Stores > Configuration > General > Store Information**, including the **Suburb**. If you ship from somewhere else, fill in **Collection address** in the Bob Go settings instead.

## Settings

All under **Stores > Configuration > Sales > Delivery Methods > Bob Go**. Most can be set per website.

| Setting | Default | What it does |
|---|---|---|
| Environment | Sandbox | Which Bob Go to talk to. Keys don't work across environments. |
| API Key | | Your Bob Go API key. Stored encrypted. |
| Channel identifier | Store URL | The address your Bob Go channel was set up with, without `https://`. Only needed when it differs from the store URL; Bob Go matches it exactly, `www.` included. |
| Webhook Signing Secret | | Needed for fulfilment sync. Every webhook without a valid signature is refused. Stored encrypted. |
| Enable Bob Go rates at checkout | No | Show Bob Go rates to customers. |
| Show additional rate information | No | Add delivery times and service descriptions to each rate. |
| Maximum rates to show | 20 | The most Bob Go rates shown at checkout. |
| Enable order push | No | Send orders to Bob Go. |
| Enable fulfillment sync | No | Create Magento shipments from Bob Go fulfilments, by webhook and the hourly check. |
| Notify customer on shipment | Yes | Email the customer when a shipment is created from Bob Go. |
| Send product options to Bob Go | Yes | Put the variant, custom options and personalisation the customer chose on the Bob Go picking list. |
| Product options not to send | | Option names to leave out, one per line or comma-separated. |
| Length / Width / Height attribute code | | Product attributes holding dimensions in cm, if you have them. Magento has none by default. |
| Collection address | | Where you ship from, if it isn't your Store Information address. Each field falls back on its own. |
| Suburb field label / help text | Suburb | Wording of the checkout suburb field. |

## How it works

### Rates at checkout

When a customer enters a delivery address, the extension asks Bob Go for rates, using your store address as the collection address and the cart's items. If Bob Go is slow or down, it gives up after 8 seconds and doesn't show Bob Go rates. It never breaks checkout.

Rates are cached: two hours for the cart page's rough estimates, 15 minutes at checkout, and 30 seconds after a failure, so one Bob Go outage doesn't slow down every cart. When a cart rule already gives free shipping, one free rate is shown and Bob Go isn't asked.

With no API key saved, rates come from the route 1.0.x used, which needs a store already set up as a Magento channel in Bob Go under the same host.

### Sending orders

Saving an order adds it to a queue (`bobgo_order_sync_queue`). A cron job runs every minute, creates the order in Bob Go the first time and updates it after that, but only when something Bob Go cares about has changed. A failed attempt is retried after 1, 5, 15 and 60 minutes, then hourly, up to 10 attempts. A failed order shows as failed in its Bob Go panel, and the admin shows a banner.

An order is created in Bob Go once it is new, processing, on hold or complete, so one waiting for payment goes once it's paid. Virtual orders are never sent, and nor are orders cancelled before they reached Bob Go. Cancelling or completing an order already in Bob Go is passed on.

### Shipments coming back

Bob Go sends three webhooks: `fulfillment/created`, `tracking/updated` and `order/updated`. Each one is handled like this:

1. The signature is checked before anything else. A missing or wrong signature gets a 403.
2. The webhook is matched to a Magento order, preferring the order id the extension sent to Bob Go. Bob Go sends every event on your account, including other channels', so anything that isn't clearly yours is acknowledged and left alone.
3. A repeat of a delivery already handled is skipped.
4. The extension then asks Bob Go for the order's current fulfilments and creates any Magento shipment that's missing, with the tracking number and courier. It doesn't trust the webhook's own data.

Some fulfilments are not turned into shipments:

- a fulfilment Bob Go reports as failed or cancelled, such as a booking the courier refused;
- lines you already shipped by hand in Magento. The rest of the fulfilment still ships.

Only one sync of an order runs at a time, so two webhooks arriving together can't create the same shipment twice. When something goes wrong that a retry can fix, the webhook answers 500 and Bob Go tries again 15 minutes later.

`order/updated` also cancels the Magento order when the order was cancelled in Bob Go.

### The hourly check

Every hour, a cron job runs the same sync for every order linked to Bob Go that is new, processing or on hold, plus completed orders from the last 14 days. A shipment whose webhook never arrived is created within the hour. Once a day it also checks that the webhook subscriptions are still active and repairs them if Bob Go has switched them off.

### Tracking for customers

For Bob Go shipments, Magento's own tracking popup shows the live status and checkpoints from Bob Go.

There is also a standalone tracking page at `/bobgo/tracking/index`, off by default. Customers enter an order number or tracking number together with the order's email address. Nothing is looked up unless both match an order in your store. To switch it on:

```bash
php bin/magento config:set carriers/bobgo/enable_track_order 1
```

### In the admin

- **On each order:** a Bob Go panel with the Bob Go order id, sync status, when it last synced and last had a webhook, Bob Go's shipments for it, and a **Resync with Bob Go** button.
- **Sales > Operations > Bob Go Sync Log:** every request to and from Bob Go, with its status and payload. Customer names, contact details and addresses are masked. Rows are kept for 30 days. Access is a separate permission, so support staff can see it without order editing rights.
- **A banner** when orders have failed to sync.

## Security

- Webhooks are verified with HMAC-SHA256 over the raw body before anything reads it. A request that fails is refused, and its body is not stored.
- The API key and the webhook signing secret are stored encrypted. Logs show only the API key's last four characters.
- Everything shown in the sync log is shown as plain text, and customer details in it are masked.
- **Resync with Bob Go** only works as a form post with Magento's form key.
- The standalone tracking page only looks something up when the order number or tracking number and the email both match an order in your store.

## Troubleshooting

### No Bob Go rates at checkout

- Check that **Enable Bob Go rates at checkout** is on.
- Check the API key is for the selected **Environment**. A sandbox key doesn't work in production.
- Check the delivery address is in South Africa with a valid postal code.
- Check a cart rule isn't giving free shipping. If it is, only one free rate shows.
- With no API key, rates use the 1.0.x route. That only works for a store already set up as a Magento channel in Bob Go under the same host.
- Look in `var/log/system.log` and `var/log/exception.log` for "Bob Go".

### Orders not reaching Bob Go

- Check **Enable order push** is on and an API key is saved, for the order's website.
- Check Magento cron is running. Orders are sent by the `bobgo_push_orders` job.
- In the sync log, look for outbound rows that failed. Bob Go's reply is stored with them.
- Upgraded from 1.0.x and not using a key yet? Orders reach Bob Go the 1.0.x way. Look for "1.0.x order notification" in `var/log/system.log`.

### Connection says the key was rejected

- If the key is right, check **Channel identifier**. A channel's key only works when the identifier matches the address on the Bob Go channel exactly, `www.` included.
- After moving the database to another server with a different encryption key, the stored key can't be read. Enter it again.

### Webhooks refused with 403

The signing secret doesn't match. Paste the **Webhook Signing Secret** in again; once saved, it's hidden. Then check it's the secret from **Settings > Webhook subscriptions** for the same Bob Go account. In the sync log, refused webhooks show as `webhook_rejected`.

### Shipments not appearing in Magento

- Check **Enable fulfillment sync** is on and a signing secret is set.
- Check Bob Go can reach `https://your-store/bobgo/webhook/receive` from the internet.
- Check Magento cron is running. The hourly `bobgo_reconcile_fulfillments` job creates anything a webhook missed.
- A fulfilment that failed or was cancelled in Bob Go is not turned into a shipment, on purpose.

### Errors after upgrading

Several classes changed in 1.2.0, so recompile:

```bash
php bin/magento setup:upgrade
rm -rf generated/code/* generated/metadata/*
php bin/magento setup:di:compile
php bin/magento cache:flush
```

### Weights look wrong

Weights are converted to kilograms when sent to Bob Go; your products are not changed. An item over 500 kg is treated as a data error, and Bob Go rates are not shown for that cart.

## For developers

How it is built, every payload and every design decision: [docs/spec.md](https://github.com/Bob-Group/bobgo-magento-extension/blob/main/docs/spec.md).

**What it adds to the database:**

| Table | Added |
|---|---|
| `sales_order` | `bobgo_order_id`, `bobgo_order_ref`, `bobgo_sync_status`, `bobgo_sync_hash`, `bobgo_last_synced`, `bobgo_last_webhook`, `bobgo_shipments`, `bobgo_status_synced` |
| `sales_order_item` | `bobgo_order_item_id` |
| `sales_shipment` | `bobgo_fulfillment_id` |
| `quote_address`, `sales_order_address` | `suburb` |
| `bobgo_order_sync_queue` | New: orders waiting to be sent. |
| `bobgo_sync_log` | New: one row per request to or from Bob Go. |

**Cron jobs:** `bobgo_push_orders` (every minute), `bobgo_reconcile_fulfillments` (hourly), `bobgo_prune_sync_log` (daily).

**Contributing:**

1. Branch from `dev`.
2. Run `composer check` (PHPStan, then the unit tests).
3. Open a pull request into `dev`. CI runs the tests on PHP 7.4, 8.1, 8.3 and 8.4, plus PHPStan and a dependency audit.

`main` is what gets released; `dev` is where work happens.

## Support

- Email: support@bobgo.co.za
- Issues: [GitHub issues](https://github.com/Bob-Group/bobgo-magento-extension/issues)
- Bob Go: [bobgo.co.za](https://www.bobgo.co.za)

Licensed under [GPL-3.0-or-later](LICENSE).
