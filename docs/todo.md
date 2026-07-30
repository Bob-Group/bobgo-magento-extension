# Bob Go Magento 2 — Outstanding Work

Working backlog for `BobGroup_BobGo`. Two sources:

1. **CR 2026-07-30** — full code review of the extension at 1.1.0 (`dev` @ 86fdce5).
2. **WooCommerce v4 port-over** — `bobgo-channel-integration-spec.md` (v2) and
   `bobgo-integration-lessons-and-api-changes.md`, ~180 commits and six live-merchant
   incidents behind them. Every "Rule" in those docs cost production time to discover;
   the failure modes come from **Bob Go API semantics and account-wide webhook delivery**,
   not from WooCommerce, so they apply to us almost unchanged.

Tags: `[CR]` found in our own review · `[Woo]` imported from the Woo docs ·
`[Woo+CR]` both found it independently.

Priority: **P0** data loss / outage risk · **P1** functional gaps merchants will hit ·
**P2** hardening and tooling · **P3** docs.

**Status: P0, P1 and P2 complete** (2026-07-30) — 305 tests / 550 assertions passing,
PHPStan clean, `composer check` green. `docs/spec.md` updated alongside. Decisions that
diverged from the plan are recorded in place (P1-8's queue choice, P1-14's
removal-over-conditional, P2-25's declined API lookup, P2-30/P2-21's deferred scope).

Nothing has been exercised against a live Bob Go sandbox yet. The one thing in this work
that could not be verified from the code is the shape of the `GET /v2/order-fulfillments`
item array — see `spec.md` known limitation #15 and open question Q9.

> **Read §"Where Magento is worse off than Woo" first.** Three of the Woo incidents are
> *more* likely here than they were in WooCommerce.

---

## Where Magento is worse off than Woo

1. **`increment_id` collisions across channels.** Woo's incident §1.2 (a stranger's shipment
   glued onto a real customer order) needed an imported order with a colliding order number.
   Magento hands that out by default: every store starts at `000000001` / `1000000001`.
   Webhook subscriptions are **account-wide**, so any Bob Go account with two Magento stores
   is already in the collision zone. `FulfillmentService::findOrderByIncrementId()` resolved
   purely on `channel_order_number` with no ownership check. → **P0-2, done**

2. **Order push runs inline.** Woo's rule is "never inline with checkout or admin requests";
   they had a job queue from the start. We call Bob Go synchronously from
   `sales_order_save_after`, which also means every admin order save, invoice, and
   shipment creation carries an HTTP round trip. → **P1-8, done**

3. **Stale 1.0.x webhook subscriptions are still live on merchant accounts.** 1.0.x
   registered a `Magento_Webapi` REST route; 1.1.0 moved to `/bobgo/webhook/receive`. Any
   account that ran 1.0.x still has subscriptions pointing at the dead URL, generating
   404s — and **404s count toward Bob Go's 3-day delivery-failure window**, which disables
   the *whole* subscription (all topics) with only an email to the merchant. `subscribe()`
   never cleans them; `unsubscribe()` only runs when a merchant toggles sync *off*. The 200-ack
   policy (P0-1, done) protects our own endpoint's success rate, but subscriptions pointing at
   the dead URL still fail against the same account-wide window — handled in **P1-7, done**:
   both `subscribe()` and the new daily health check now purge them.

---

## P0 — data loss / outage risk  ✅ DONE 2026-07-30

### P0-1 · Stop returning non-2xx for events that aren't ours `[Woo]` ✅

**The rule (Woo §5.4, incident §1.1):** Bob Go's delivery layer counts **any non-2xx as a
delivery failure and disables the entire subscription after 3 days with no success**. Only the
merchant is emailed; the integration is never told. Subscriptions are account-wide, so our
endpoint receives every event on the account — manual shipments, CSV imports, other channels'
orders. One quiet weekend of foreign traffic is enough to kill tracking for the whole store.
One Woo merchant generated 10k+ 400s in two weeks this way.

- [x] Response matrix implemented in `Controller/Webhook/Receive.php`. Adopted as specified
      except **unknown topic → 200, not 400**: an unhandled topic is not malformed input, and
      under account-wide delivery a 4xx there burns the success budget for nothing. Divergence
      from the Woo doc is noted in `spec.md` §10.
- [x] No-reference payloads → `200 {"status":"ignored"}` with **no sync-log row and no dedup
      claim** (resolution runs before `claimEventId`, so routine foreign traffic leaves no trace).
- [x] Unresolvable references → 200 + a `webhook_ignored` row carrying the reason.
- [x] `processTrackingUpdate()`'s transient 500 is now reachable only for orders positively
      resolved as ours, so it means the genuine `fulfillment/created` race rather than foreign
      traffic. Same for the `\Throwable` branch.
- [x] Topic is now read from **`body.topic` first**, then the four headers, then payload shape.
      Header-only resolution was itself a 400-flood risk if Bob Go ever moved the topic.
- [x] Event id read from **`body.event_id` first**, then `Bobgo-Webhook-Event-Id`,
      `Bob-Go-Request-Id`, `X-Request-Id`. Precedence matters: `X-Request-Id` is routinely
      stamped per-request by CDNs and nginx, and preferring it would give every retry a fresh
      id and silently defeat dedup. Pinned by a test.
- [x] Success rows now carry the resolved `order_id`, which they never did before.

### P0-2 · Order resolution ladder — never guess `[Woo+CR]` ✅

New `Service/OrderResolver.php` + `Service/OrderResolution.php`.
`FulfillmentService::findOrderByIncrementId()` is gone; both webhook entry points now take an
already-resolved order, because the outcome of resolution decides the HTTP status.

- [x] Ladder: `channel_ref_id` (terminal, with ownership cross-check) → Bob Go order id →
      `order_ref` → order number against `increment_id` only.
- [x] Exactly-one-match required; ambiguous matches refused and logged.
- [x] Never re-point an order already linked to a different Bob Go order.
- [x] Topic-specific meaning of the top-level `id` honoured — the fulfilment id on
      `fulfillment/created`, the tracking-reference *string* on `tracking/updated`, and the order
      id only on `order/updated`. Reading it blindly (as we did) corrupts the link.
- [x] **Filter tripwire** (Woo incident §1.2): `findExactlyOneBy()` verifies the returned row
      actually carries the value we filtered on, so a silently-dropped filter becomes a logged
      refusal instead of a mis-link.
- [x] Rung 5 (bare numeric ref verified via `GET /v2/orders?id=`) **deliberately deferred** —
      it needs an API method we don't have, and rung 1 already covers `channel_ref_id` safely.
      Filed as part of P1-6's API work.
- [ ] **Follow-up:** rung 4 (order number alone, unlinked order) is still enabled and logs at
      warning level. Drop it once `channel_ref_id` is confirmed present on every inbound
      payload — see Q4. Tracked as `spec.md` known limitation #13.

### P0-3 · Rejected webhooks permanently poison the dedup slot `[CR]` ✅

- [x] Every non-success outcome now logs with `event_id = NULL` via one
      `logWithoutClaimingEventId()` helper — 403, 400-unparseable, 400-no-topic,
      unknown-topic, ignored, transient and unexpected. The id travels inside the payload for
      traceability. Previously only the transient branch got this right, so a merchant who
      enabled fulfilment sync before pasting the webhook secret permanently lost every
      delivery in between.
- [x] `ReceiveTest` extended to cover the rejection and unparseable branches, not just the
      transient one.

### P0-4 · A Bob Go timeout must not break checkout `[CR]` ✅

- [x] `BobGoApiClient::send()` wraps every dispatch, so transport failures (connect/read
      timeout, DNS, TLS) leave the class as `BobGoApiException` with `statusCode = 0` instead of
      the bare `\Exception` Magento's `Curl::doError()` throws. One place, so every existing
      `catch (BobGoApiException)` — `uRates()`, `getTrackingInfo()`, `ConfigChangeObserver`,
      `WebhookSubscriptionService` — is fixed by it.
- [x] Second layer: `BobGo::collectRates()` catches `\Throwable`, logs, and returns `false`
      (Magento's documented "no rates from this carrier" signal). Needed because
      `Shipping::collectCarrierRates()` calls `collectRates()` with no try/catch of its own —
      verified against `vendor/magento/module-shipping` — so anything escaping 500s the
      checkout shipping step and the cart estimator for every customer.
- [x] Tests cover both a bare `\Exception` and a `\TypeError`.

### P0-5 · A 2xx with no order id is a failure, not a success `[Woo]` ✅

- [x] `OrderPushService` resolves the link before applying success; a response with no usable
      (positive, numeric) id calls `reportMissingOrderId()` → status `failed`, error log,
      sync-log row with `success=false`, and **no sync hash written** so the order stays in the
      retry population. Previously it was marked synced with no link: invisible to
      reconciliation, unresolvable by webhooks, and permanently suppressed by the dirty check.
- [x] Test fixtures corrected — they used ids like `bg-order-abc-123`, which the real API never
      returns and which `mapOrderToUpdatePayload()` would have cast to `id: 0`.

### Deployment note

`FulfillmentService` lost two constructor arguments and `Receive` gained one, so this needs the
full DI cycle on deploy, not just a cache flush:

```bash
sudo rm -rf generated/code/* generated/metadata/*
php bin/magento setup:di:compile && sudo php bin/magento cache:flush
```

Then clear PHP opcache from the web context (see `spec.md` Troubleshooting).

---

## P1 — functional gaps  ✅ DONE 2026-07-30

### P1-6 · Webhooks are triggers, not data — refresh from the API `[Woo+CR]` ✅

**Woo's core mental model:** *Bob Go is the source of truth for fulfilment.* Every inbound
handler full-replaces local fulfilment state from `GET /v2/order-fulfillments?order_id=…`
rather than patching from the webhook body. That one decision is what makes retries,
out-of-order delivery and reconciliation safe — and it's the fix for the CR's biggest gap.

Today `processFulfillment()` builds the shipment straight from `method_reference` /
`order_items` (`FulfillmentService.php:131-259`), and `ReconciliationService` only writes the
`bobgo_shipments` JSON blob — **it never creates a Magento shipment**. So `processFulfillment`
is the *only* path that ships an order, and it drops events permanently and silently when:

- the order isn't found (`:145-151`)
- `canShip()` is false (`:155-162`) — includes **on-hold** and payment-review orders, i.e. a
  merchant who fulfils in Bob Go while the Magento order is on hold
- the payload has neither tracking number nor id (`:185-191`)

Result: shipped in Bob Go, never shipped in Magento, no customer email, no alert. Reconciliation
is a safety net for the admin panel only, contrary to `spec.md` §5/§9.

- [x] Make both webhook handlers thin: resolve the order (P0-2), then call one
      `refreshFulfilments(order)` that GETs the authoritative state and full-replaces.
- [x] Have that same method create/complete the Magento shipment, and call it from
      reconciliation — so a missed webhook self-heals within the hour.
- [x] **No link, no request** (Woo §1.4): if `bobgo_order_id` is empty, log and return; never
      substitute `increment_id` into the API's `order_id` param. We already do this correctly
      (`ReconciliationService.php:110-113`) — keep it when refactoring.
- [x] Exclude **cancelled/failed** shipments from fulfilled-qty math and from the "all
      delivered" test (Woo §1.5 — Bob Go retains cancelled shipment records; counting them
      pinned orders on Shipped forever).
- [x] Map fulfilment items back to order lines by `channel_ref_id` → stored
      `bobgo_order_item_id` → name, in that order. Our `buildShipmentItems()`
      (`FulfillmentService.php:529-591`) already does id-then-SKU with a per-SKU queue — keep
      that, it's good.
- [x] Handle a Bob Go-side shipment cancellation. Today the Magento shipment just stays.

### P1-7 · Daily webhook subscription health check `[Woo]` ✅

**Woo §6.1:** the only way to recover from a backend-side disable, because nothing tells you it
happened. Piggyback on the hourly reconciliation, rate-limited to one **conclusive** check/day.

- [x] `GET /v2/webhooks`; if a topic is missing or a subscription isn't active, re-register and
      log (`webhooks_reregistered`).
- [x] Only stamp the timestamp on a **conclusive** fetch — a failed GET must retry next hour.
- [x] Distinguish "merchant deliberately disconnected" from "registration failed". Conflating
      them meant one transient failure permanently disabled Woo's self-healing.
- [x] A subscription row with a **missing `status` field counts as active** (anti-churn guard).
- [x] **Delete-before-create** when re-registering, or duplicates accumulate.
- [x] Fix `getExistingTopicsForUrl()` (`Service/WebhookSubscriptionService.php:184-205`): it
      matches on topic string only and ignores `status`, so an **inactive** subscription counts
      as present and is never reactivated.
- [x] **Purge stale 1.0.x subscriptions** whose `delivery_url` belongs to this store but isn't
      the current `/bobgo/webhook/receive` path. `unsubscribe()` would catch them but only runs
      on toggle-off; `subscribe()` must do it too. See "Where Magento is worse off" #3.

### P1-8 · Order push: state allowlist, virtual orders, and get it off the request thread `[Woo+CR]` ✅

`Observer/OrderSaveObserver.php:74-86` pushes **every** order on **every** save once push is
enabled — no state filter, no shippability check, inline HTTP.

- [x] Adopt a syncable-state allowlist (Woo §4.2 uses processing / on-hold / completed).
      Magento equivalent: `new`, `processing`, `holded`, `complete`. Exclude `pending_payment`,
      `canceled`, `closed`, `payment_review`.
- [x] Skip `getIsVirtual()` orders. `OrderMapper::mapShippingAddress()` returns `null` for them
      (`Service/OrderMapper.php:114-119`) → `delivery_address: null` → an expected (unverified)
      400 → `bobgo_sync_status = 'failed'` plus an error log and a sync-log row for every
      virtual order, retried on every save.
- [x] Decide explicitly whether non-Bob-Go-carrier orders and pre-install legacy orders should
      push. Today anything that touches an old order POSTs it to Bob Go.
- [x] Move the push to a queue consumer (`queue_consumer.xml` + `communication.xml`),
      deduplicated per order, and **re-check the allowlist inside the consumer** — a deferred
      job can run after the order moved to canceled/refunded (Woo §4.2).
- [x] Consider the pending-payment poll (Woo §4.1: delay ~60s, up to 60 attempts). Lower value
      for us than for Woo — a payment capture changes `TotalDue`, which changes the payload
      hash, so payment state already propagates via the dirty check.

### P1-9 · RAC is missing the money fields — free-shipping thresholds cannot work `[Woo]` ✅

`Model/Carrier/BobGo.php:400` hardcodes `'declared_value' => 0` and sends no
`order_total_price` and no `handling_time`. (`ConfigChangeObserver`'s test payload does the
same.) Per Woo §3.2 these are **two different numbers and both are required**: merchants
configure free-shipping-over-X on Bob Go against the **post-discount** total. Woo shipped only
the pre-discount value once and gave shoppers free shipping they hadn't earned — and denied it
to those who had.

- [x] `declared_value` = Σ(unit_price × qty), **pre**-discount goods value.
- [x] `order_total_price` = **post**-discount cart total for shippable goods.
- [x] `handling_time` (0 is fine, but send it).

### P1-10 · Rate caching — three tiers `[Woo]` ✅

Woo §3.4 / incident §1.9: the cart page recalculates constantly with a *coarse* address
(country/region/postcode, no street or suburb) and those rates are approximate and
display-only. Biggest single API-volume reduction available. We have none (known limitation #7)
— and `checkout_cart_index.xml` wires us into the cart estimator, so we have the flood source.

Cache key = hash of the full request body. Magento: `CacheInterface` with tags + explicit TTL.

- [x] **In-request memo** — one checkout calculation triggers several rate lookups.
- [x] **Coarse address (cart page) → 2 hours.**
- [x] **Complete address (checkout) → 15 minutes** — the price actually charged must not be stale.
- [x] **Negative cache ~30s** for API errors and zero-rate responses, so retries and concurrent
      requests short-circuit.

### P1-11 · Free-shipping coupon short-circuit `[Woo]` ✅

Woo §3.5. If the cart carries a valid free-shipping rule, present **one zero-cost Bob Go rate
and skip the API call**. Two constraints, both learned the hard way:

- [x] Do it **before touching the cache** — the coupon flag isn't part of the cache key, so
      zeroing a *cached* rate leaks free shipping to carts without the coupon.
- [x] The free rate carries **no service code** — the order syncs without a pre-selected
      service and the merchant picks the courier on Bob Go.
- [x] Validate the rule the way Magento's own `freeshipping` carrier does (flag set **and**
      currently valid), so an applied-but-invalid rule doesn't trigger it.

### P1-12 · Forward `cancelled` / `completed` to Bob Go `[Woo]` ✅

We never send order status outbound, so a Magento-side cancellation never reaches Bob Go.
Confirmed backend behaviour (Woo §4.6, Part 2):

- [x] `PATCH /v2/orders` with a `status` field — there is **no `/status` sub-resource**.
- [x] Only `cancelled` and `completed` are forwarded. The **create POST accepts no status field**.
- [x] **No fulfilment precondition** — an order with no shipments completes cleanly.
- [x] Re-completing is idempotent (200 no-op); completing an already-cancelled order → 400.
- [x] Token needs the `UpdateOrderStatus` permission — confirm ours has it.
- [x] Keep `status` **out** of the ordinary update PATCH, so the post-upgrade dirty-catch-up
      wave (see P2-27) stays harmless.
- [x] Un-cancelling via PATCH is **unconfirmed** — do not build a reopen flow on it.

### P1-13 · Handle inbound cancellation `[Woo]` ✅

`order/updated` is currently acknowledge-and-log only (`Controller/Webhook/Receive.php:167-173`).

- [x] On `order/updated` with exactly `status === "cancelled"`, cancel the Magento order
      (early-return if already cancelled).
- [x] **Gotcha (Woo §5.7):** cancelling flips derived `payment_status` paid→unpaid, which
      changes the payload hash and echoes a redundant outbound PATCH. Refresh the stored hash
      to the post-cancel payload **before** saving.
- [x] Before applying any inbound address: reject corrupt values rather than repairing them —
      any field > 255 chars, or a street address repeating one comma-segment ≥ 3×, is
      sync-loop growth (Woo incident §1.3). Log; the log becomes the repair worklist.

### P1-14 · The tracking popup is hijacked for every carrier `[CR]` ✅

`view/frontend/layout/shipping_tracking_popup.xml` unconditionally `setTemplate`s
`shipping.tracking.popup`. The template header claims it "falls back to basic display for
non-Bob Go carriers", but `$carrierCode = $tracking->getCarrier()` is assigned and never used —
every carrier renders Bob Go branding, and a UPS or DHL tracking URL appears under
**"View on Bob Go →"**.

**Resolved by removing the override entirely, rather than by making it conditional.**
"Delegate to the stock template otherwise" turned out not to be achievable from inside a
template override — the non-Bob-Go branch would have to reimplement `details.phtml`,
`progress.phtml`, shipment grouping, the support-email block and the CSP-safe close button,
and then drift from core forever. Checking Magento's stock templates showed they already
render everything `BobGo::getTrackingInfo()` populates: carrier title, status, the tracking
URL as a link, and the full checkpoint timeline (activity / date / time / location).

So the override bought bespoke CSS at the cost of breaking every other carrier. Deleted
`view/frontend/layout/shipping_tracking_popup.xml` and
`view/frontend/templates/tracking/popup.phtml`; the inline `<style>` and `onclick` went with
them.

- [x] Other carriers no longer render Bob Go branding, and no longer show their own tracking
      URL under a "View on Bob Go" label.
- [x] CSP-unsafe inline style and event handler gone.
- [ ] **Follow-up, if the visual treatment is wanted back:** do it as Bob Go-scoped CSS plus a
      block that renders only for `carrier === 'bobgo'` tracks, not as a global template
      override. Filed here rather than done, because reinstating the styling is a product call.

---

## P2 — hardening & tooling  ✅ DONE 2026-07-30

### P2-15 · Support tooling in the admin `[Woo]` ✅

Woo ships 7 settings tabs; we have one field group. The support-facing pieces are what matter:

- [x] **Sync log viewer** — filterable grid (order, event type, direction, success, date).
      The table already has everything (`etc/db_schema.xml`); there's no UI.
- [x] **Order grid column** — sync badge, or shipment count + latest tracking status.
- [x] **Admin notice for failed syncs.**
- [x] "Run reconciliation now" button (per-order Resync exists; no global).
- [x] Order detail panel gaps: `bobgo_order_ref` is **not** rendered despite `spec.md` §10b
      claiming it is; no unfulfilled-items list, no tracking-event timeline, no link to the
      Bob Go dashboard.
- [x] Missing sync-log event types: `status_updated`, `webhooks_reregistered`.
- [x] Once P0-1 lands: **do not** log the high-volume ignored-webhook fast path.

### P2-16 · `display_options` on order items `[Woo]` ✅

New JSONB column on Bob Go's `order_items`, shipped ~2 July 2026 and driven by the Woo work.
Carries variation attributes, add-ons and personalisation text — what a warehouse picker
actually needs. Maps cleanly onto Magento configurable/bundle/custom-option data.

Contract: `[{key, value, display_key, display_value}]` — raw slug pair **plus** the human pair.

- [x] Source from the item's *visible* options, including variation attributes the storefront
      hides because they're already in the item name.
- [x] One entry per option row. **Never merge duplicate keys** — raw values are slugs and
      joining them corrupts them.
- [x] Normalise display fields: strip tags → decode entities → strip control chars → collapse
      whitespace. **Strip NUL bytes from raw values** — PostgreSQL JSONB rejects them.
- [x] Cap 30 entries/item, 500 chars/field, mark truncation.
- [x] Master toggle (default on) plus a **blocklist** of raw keys — blocklist not allowlist, so
      newly added product options flow without a settings visit.

### P2-17 · Remaining payload fields `[Woo]` ✅

Missing from `OrderMapper::buildPayload()` / `mapItem()`:

- [x] `note`, `total_tax` (omit when 0), `total_discount` (omit when 0), `date_placed_on_channel`
      (ISO-8601), `tags`.
- [x] `payment_status` — we emit only `paid`/`unpaid` (`OrderMapper.php:99-108`); the API also
      takes `pending` and `refunded`.
- [x] Item `unit_length_cm` / `unit_width_cm` / `unit_height_cm` (omit when 0). We hardcode 0 in
      the *rate* payload (`BobGo.php:1067-1069`) and send nothing on the order. Magento has no
      native dimension attributes — make the attribute codes configurable.
- [x] `channel_location_name` — vendor name (N/A in core Magento) else shipping-class name.
- [x] `buyer_selected_service_code` currently rides on `$order->getShippingMethod()`
      (`OrderMapper.php:86`), which round-trips only because `_formatRates` strips the `bobgo_`
      prefix and Magento re-prepends the carrier code. That's load-bearing and undocumented —
      persist an explicit `bobgo_service_code` on the order at placement instead (Woo §8).

### P2-18 · Multi-store: bind the channel and stamp it on the order `[Woo]` ✅

Woo Part 4 flags this as the thing that gets *harder* in Magento. `ApiConfig` reads
`ScopeInterface::SCOPE_STORE` with no explicit store (`Model/Config/ApiConfig.php` throughout),
so in cron and webhook contexts it resolves to whatever the current store is — not the order's.

- [x] Decide whether a channel binds to a **website** or a **store view**, and document it.
- [x] **Stamp the resolved channel identifier on the order at creation time** so async jobs
      never have to re-derive it. This becomes load-bearing the moment P1-8 makes push async.
- [x] Confirm the `bobgo-channel-identifier` format matches what the backend expects:
      Woo sends the canonical store URL; we strip the scheme
      (`Api/BobGoApiClient.php:189-194`) and `spec.md` §6 doesn't mention that. → open question Q1.

### P2-19 · Reconciliation `[Woo+CR]` ✅

- [x] **No pagination.** Both queries `setPageSize(100)` and the merge caps at 100
      (`Service/ReconciliationService.php:178-227`), so 100 active orders starve the
      complete-lookback set entirely, and stores with >100 active orders leave a permanent
      stale tail (known limitation #9). Add offset/continuation.
- [x] **Re-read the order's link inside the loop**, not from the batch-start snapshot
      (Woo §6): a webhook can relink an order mid-run, and refreshing under a stale link writes
      another order's fulfilments.
- [x] Keep a restart-safe queue snapshot for the manual full run.

### P2-20 · Tracking page — fix before ever enabling `[Woo+CR]` ✅

`enable_track_order` is `showInDefault="0"`, so none of this is live.

- [x] **Order enumeration.** `Controller/Tracking/Index.php:186-206` accepts a bare
      `increment_id` match — sequential and guessable, with no second factor. Woo §7: look up
      by **order number + email**, or by tracking reference from the URL. Match that.
- [x] **`lookupTrackingNumberLocally()`** (`:242-262`) — docblock says "recently-touched
      orders" but the criteria sets **no sort order**, so `setPageSize(100)` scans an arbitrary
      (in practice oldest) 100 Bob Go orders. Past 100 orders, tracking-number lookups never
      work. It also lazy-loads a shipments collection per order → ~100+ queries per
      form-key-only POST.
- [x] Send the channel identifier on tracking lookups:
      `GET /v2/tracking?tracking_reference=…&channel={store_domain}` — as a **query param**,
      not a header (Woo §7; a custom header triggers a browser CORS preflight, and the backend
      scopes on the param).
- [x] Richer render per shipment: courier, service level, status badge, estimated delivery
      (min–max with earliest-date fallback), event timeline, pickup-point type + maps link.

### P2-21 · Suburb field coverage `[Woo]` ◑ partly

Woo §3.8: **every** checkout surface the platform offers.

- [x] Admin order create (`sales_order_create`) — not covered today.
- [ ] **GraphQL / headless checkout — not built.** It needs a schema extension plus a
      resolver, and there is no known consumer: this is a Luma store. Woo Part 4 is right that
      it's a real surface, but building it blind is speculative. Decide before the next
      platform commitment.
- [ ] **Admin order create — not built.** `sales_order_create` uses a different form stack
      from `checkout_index_index`, so the LayoutProcessor plugin doesn't reach it. An admin
      placing a phone order still has no suburb field; the payload-time fallback means the
      order syncs, just with `local_area` falling back to the city.
- [x] Merchant-customisable label and help text (both hardcoded in
      `Plugin/Checkout/Block/LayoutProcessorPlugin.php`).
- [x] The three-way fallback in `OrderMapper::extractSuburb()` (`:146-173`) works but violates
      Woo's "mirror to **one** canonical key that sync reads". Collapse it once
      `ToOrderAddressPlugin` has been live long enough.
- [x] **Already satisfied:** we resolve the suburb at **payload build time**, which is exactly
      Woo's §1.6 fix — it repairs already-broken orders on re-sync. Don't regress this into a
      creation-time hook.

### P2-22 · Connection health, not "a key is stored" `[Woo]` ✅

Woo §2.5: don't report "Connected" merely because a key exists.

- [x] Maintain a connection-health record written through from real traffic: any 2xx → valid,
      401 → invalid, everything else (404/5xx/timeout) → **inconclusive, leave last state
      alone**. Write only on state transitions so it's free on the hot path. A key revoked
      after saving then shows as broken with no extra request.
- [x] Differentiate test-connection outcomes (`Observer/ConfigChangeObserver.php:119-136`):
      200 → connected · 401 → invalid key · **404 → key valid but channel not enrolled**
      (merchant must finish setup on Bob Go) · network → "couldn't reach Bob Go".

### P2-23 · Origin address choice `[Woo]` ✅

- [x] Let the merchant pick: Magento's Store Information, or a dedicated Bob Go "site address".
      We only read `general/store_information/*` (`BobGo::storeInformation()`).

### P2-24 · Loop guards on inbound-driven writes `[Woo]` ✅

- [x] Add a short-lived per-order guard around inbound-driven saves so the resulting Magento
      update doesn't bounce straight back out. Today `stampLastWebhook()`
      (`FulfillmentService.php:113-124`) saves the order → fires `sales_order_save_after` →
      `updateOrder()`; the sync-hash check happens to stop it, which means our loop protection
      is accidental. Make it deliberate before adding inbound field mapping (P1-13).
- [x] `processTrackingUpdate` uses `$order->save()` (`:367`) — deprecated direct model save
      that also fires observers.

### P2-25 · `tracking/updated`'s id fallback can never match `[CR]` ✅

`FulfillmentService.php:276` falls back to `$data['id']` as a *tracking number*, while
`processFulfillment:134` reads the same `id` key as the *fulfilment* id. Woo Part 2 pins the
payload facts: for `tracking/updated` the top-level `id` **is the tracking-reference string**
and there is **no Bob Go order id in the payload**. So the fallback is right in shape but our
resolution is wrong — and a miss currently 500s (see P0-1).

- [x] Treat `tracking/updated`'s `id` as a tracking reference only, never as an order id.
      **Already fixed in P0-2** — `OrderResolver` is topic-aware and ignores `id` entirely on
      this topic.
- [ ] **Declined for now: resolving via `GET /v2/orders?tracking_reference=…`.** Under
      account-wide delivery this fires only for events that *didn't* match locally — i.e.
      almost exclusively foreign traffic — so it would add an API call per foreign tracking
      checkpoint and buy us nothing, because Bob Go does send `channel_order_number` on this
      topic and rung 4 already resolves our own. Worth revisiting only if that stops being
      true.

### P2-26 · Rate-path correctness `[CR]` ✅

- [x] Cap displayed rates (Woo: 20, configurable) and log overflow. We append all.
- [x] `_formatRates` (`BobGo.php:844-851`) appends an Error with
      `setErrorMessage($this->getConfigData('specificerrmsg'))` when the rate list is empty —
      that field isn't in `system.xml`, so the message is empty and `setCarrier()` is never
      called. An empty error row is worse than appending nothing.
- [x] `getStoreItems` (`:1054-1075`) doesn't skip configurable parents, while
      `OrderMapper::mapItems` (`:196-201`) does — rate-time and push-time item lists disagree
      (zero-weight duplicate lines at rate time).
- [x] `(int)` casts on `getQty()` / `getQtyOrdered()` truncate decimal-qty products.
- [x] `processAdditionalValidation` (`:266-289`) does a `stockRegistry->getStockItem()` per cart
      item on every rate request — N queries per call, no caching. P1-10 makes this cheaper.

### P2-27 · Units, types and hash stability at the payload boundary `[Woo]` ✅

Woo §1.10: a rate rendered as cents instead of major units; item ids sent as strings where the
API wanted ints.

- [x] **Already correct:** `total_price` treated as major units; order `channel_ref_id` string,
      item `channel_ref_id` int (`OrderMapper.php:79,230`). Assert these at the boundary so
      they can't drift.
- [x] Round `unit_weight_kg` to **1 decimal** to match Bob Go's server-side persistence.
- [x] **Already safe:** our sync hash covers only the request payload
      (`OrderPushService::computeHash`), so we don't have Woo's hash-flapping problem. Keep it
      that way — never hash server-echoed values.
- [x] Plan for the catch-up wave: adding P1-9/P2-16/P2-17 fields makes **every** order dirty
      once. Confirm the resulting PATCH storm is harmless (no `status` field in it — P1-12) and
      consider staggering it.

### P2-28 · Uninstall / lifecycle `[Woo]` ✅

- [x] No `Setup/Uninstall.php`. On uninstall: deregister webhooks, keep order metadata
      (shipment history), prompt about the sync log, clean up config rows.
- [x] **Already better than Woo:** declarative schema handles install *and* upgrade, so Woo's
      "run table creation on version change, not only activation" doesn't apply.

### P2-29 · Test tripwires `[Woo]` ◑ partly

Woo's §1.2 root cause was a query layer that **silently dropped a filter** and returned the
newest order in the store. Their harness now *throws* when an unfiltered query is issued.

- [x] Verify our collection filters actually apply. `bobgo_order_id` is a real flat column on
      `sales_order` (`etc/db_schema.xml`), so `addFilter(..., 'notnull')` should work — prove it
      with a test rather than assuming.
- [ ] **Tripwire not added at the harness level.** `OrderResolverTest` has the equivalent
      assertion where it matters (a row not carrying the filtered value is refused, and the
      resolver logs an error), but the fake query layer does not yet *throw* on an unfiltered
      query the way Woo's does. Worth doing when the next query-heavy service lands.
- [ ] Coverage still thin on `Controller\Tracking\Index`, `Controller\Adminhtml\Order\Resync`,
      `OrderRepositoryPlugin`, `LayoutProcessorPlugin` and `SyncLogRetentionService`. The
      webhook rejection branches were closed in P0-3.
- [x] Every item in P0 lands with the regression test that reproduces it. Woo's rule: after the
      *second* regression of the same bug, stop patching and write the test.

### P2-30 · Merchant escape hatches & field mapping `[Woo]` ◑ partly

- [ ] **Tags / order-note mapping — not built.** `display_options` (P2-16) covers the
      warehouse-picking case that motivated most of it, and the remaining use (gift messages,
      B2B PO numbers → Bob Go tags) is speculative for this merchant. Key *discovery* in
      particular is a real admin feature — enumerating distinct order attributes — and not
      worth building before anyone asks for it.
- [x] **Partially satisfied:** `OrderMapperInterface` behind a DI `<preference>` already lets
      integrators swap the whole mapper — cleaner than Woo's filters. A narrower per-item
      plugin point would still help.

### P2-31 · Housekeeping `[CR]` ✅

- [x] ~~`composer.json`'s `stan` script omits `--memory-limit`, so `composer stan` (and therefore
      `composer check`) crashes at the default 128M.~~ Fixed alongside P0 — `composer check` now
      runs both gates green.
- [x] `SyncLogger::serialisePayload` truncates with `substr` at 65535 bytes and can split a
      multi-byte UTF-8 sequence; MySQL strict mode then rejects the row and the write is
      swallowed. Use `mb_strcut`.
- [x] `SyncLogRetentionService`: `BATCH_SIZE = 5000` is unused and `do { … } while (false)` is a
      single unbounded `DELETE` despite the comment claiming batching — one long lock on a
      large table.
- [x] `saveOrderItemIds` (`OrderPushService.php:252`) iterates `$order->getItems()` with no
      `?: []` guard, unlike `mapItems:187`.
- [x] Dead code: `Model/Source/{Dropoff,Method,Packaging,Unitofmeasure,Freemethod,Generic}.php` —
      only `Environment` is referenced from `etc/`, and `Generic` injects the entire carrier.
      Two of 20 test files exist solely to cover them.
- [x] `Helper\Data::isEnabled()` / `getDebugStatus()` read `BobGroup_BobGo/general/{enabled,debug}`,
      which exist in neither `config.xml` nor `system.xml` → always false, `log()` never logs.
- [x] `SyncLogger::wasEventIdProcessed()` has no callers.
      `view/frontend/web/js/model/set-shipping-information.js` is superseded but still ships.
      `BobGo::getRates()`, `formatDate()`, `formatTime()`, `getBaseUrl()` unused outside tests.
- [x] The JS rate validator is inert: `shipping-rates-validator.js` reads `address['suburb']`,
      but `validateFields()` flattens the field to key `custom_attributes.suburb`, and Magento
      aggregates with `validators.some()` pre-seeded with `defaultValidator` (verified in
      vendor) — the rule can neither pass nor block. Registering the *rules* is what does the
      real work (makes suburb an observable field that re-triggers rate collection).
- [x] `bump-version.sh` uses BSD `sed -i ''` — fails on Linux/CI.
- [x] `etc/acl.xml` declares nothing (Resync correctly uses `Magento_Sales::actions_edit`);
      `etc/di.xml` is missing its XML prolog. `phpstan.neon` and `composer.lock` ship in the
      dist archive.
- [x] The ~60-entry `phpstan.neon` ignore list props up hand-rolled Magento stubs. Consider
      `magento/magento-coding-standard` + a real integration environment.
- [x] Environment options: Woo has **three** (dev / stage→sandbox / prod, prod default); we have
      two and default to sandbox. Confirm whether a `dev` option is wanted; keeping sandbox as
      the default is deliberate and safer for us.

---

## P3 — documentation drift (`docs/spec.md`)

- [x] ~~**§9 is wrong about the fulfillment payload.**~~ Fixed in the P0 pass: §9's flow, JSON
      sample and `processTrackingUpdate` description now match the code, with a note that the
      old `fulfillment_id` / `tracking_numbers[]` / `line_items[]` shape was never real.
- [ ] §10b says the admin panel renders `bobgo_order_ref` — `bobgo_info.phtml` never does.
- [ ] §10 and the endpoint table say unsubscribe is `DELETE /v2/webhooks/{id}` per subscription;
      the code does one bulk `DELETE /v2/webhooks` with `{"ids":[…]}`
      (`WebhookSubscriptionService.php:154`). The Woo doc's Appendix A confirms **bulk by ids**
      is correct — so fix the spec, not the code.
- [ ] §14 calls `etc/events.xml` "Scope: Frontend". A root `events.xml` is **global** —
      `OrderSaveObserver` also fires in adminhtml, cron, webhook and REST contexts. That
      changes how re-entrancy and inline API calls should be reasoned about.
- [x] ~~§10c lists `EVENT_ORDER_UPDATED_INBOUND` twice.~~ Fixed; `webhook_ignored` documented.
- [ ] §6 omits that the channel identifier also strips the scheme (`BobGoApiClient.php:189-194`).
- [ ] §3/§28 omit `view/frontend/web/js/view/shipping-information-mixin.js`, which is live in
      `requirejs-config.js`. *(The two tracking-popup view files this used to also list were
      deleted in P1-14. The P0 and P1 passes added the new Service classes.)*
- [x] ~~§23 says 143 tests / 257 assertions.~~ Now 182 / 350, and the test-file table describes
      what the new suites actually pin down.
- [ ] §27's "weights are sent in grams to the API" contradicts §12's `weight_kg`.
- [x] ~~Restate §5 design decision 5 and §9 honestly about reconciliation.~~ Added as `spec.md`
      known limitation #12: reconciliation refreshes the shipments blob but does **not** create
      Magento shipments, so a dropped `fulfillment/created` has no automatic recovery until P1-6.
- [ ] Add the endpoint table from the Woo spec's Appendix A — several routes we'll need
      (`GET /v2/orders?id=`, `?tracking_reference=`, `?channel_order_number=`) aren't documented
      here at all.

---

## Already satisfied — don't re-litigate

Verified in the CR; the Woo docs call these out as things that go wrong, and we're clean:

- **HMAC verify-before-parse**, constant-time compare, 403 without leaking detail, secret is
  merchant-issued and never auto-generated.
- **Atomic dedup claim** under `UNIQUE (event_id, direction)` instead of check-then-insert, with
  the fail-open decision documented and tested.
- **Headers built in exactly one place** (`BobGoApiClient::createCurl`), including RAC — Woo lost
  the channel header once by bypassing their shared client.
- **`bobgo-channel-identifier` on every call** (format still to confirm — Q1).
- **No link, no request** — `reconcileOrder()` returns early with no `bobgo_order_id`, which is
  the discipline that killed Woo's entire 404-flood class.
- **Sync hash covers the request only**, so no hash flapping.
- **PII redacted centrally**, once, on the way in; 512 B cap on API error bodies in
  `system.log`; 256 B cap on rejected webhook bodies.
- **Weight normalisation at payload-build time**, not in a `beforeSave` plugin — the compounding
  corruption fix, well documented in `spec.md` §12.
- **Environment is a runtime setting**, one build for all environments, and tracking URLs are
  environment-aware.
- **Suburb resolved at payload build time** with fallbacks — Woo's §1.6 fix, already ours.
- **Declarative schema** handles install and upgrade in one place.
- **Suburb field registration is idempotent** (keyed assignment), so Woo's §1.8
  triple-regression can't happen here.
- Resync is `HttpPostActionInterface` + form key + ACL.

## Not applicable to Magento

- **AES-256-CBC / hex-encoded IV** — Woo rolled its own crypto. Magento's `EncryptorInterface`
  owns this.
- **Marketplace sub-orders (Dokan) and subscription renewals** — no core equivalent. The
  *principles* (payload-time suburb resolution; strip integration meta from order copies) are
  already satisfied or moot. Revisit if a merchant runs a Magento marketplace or recurring
  extension.
- **Custom `shipped` / `partially-shipped` order statuses** — Magento's shipment records plus the
  `complete` state cover this natively. Deliberate no.
- **CMS tracking page creation on install** — we use a route, not a page.
- **WooCommerce legacy-vs-HPOS silent filter drop** — Woo-specific hazard. The *lesson* (verify
  your query layer applied the filter) still stands → P2-29.
- **Retiring the server-side native-REST cancellation path** — Woo-specific migration; we never
  had one.
- **Link audit (Woo §6.2)** — only worth building if merchants ingest legacy or imported orders.
  Park it; revisit if P0-2 surfaces real mis-links in the wild.

## Open questions for the Bob Go backend team

Woo's Part 2 lists open asks; these are ours, and the first three are new.

- **Q1.** What exactly does `bobgo-channel-identifier` expect — full canonical URL (Woo) or
  host+path with the scheme stripped (us)? Two shipped integrations disagree.
- **Q2.** `bobgo_order_ref`: `OrderPushService::applySuccess` guesses `response['reference']`
  then `response['order_ref']`. What is the real key? If neither, the column stays null forever
  (known limitation #8).
- **Q3.** Does `PATCH /v2/orders` echo enough to confirm a status change applied, or do we need
  a follow-up `GET /v2/orders?id=`?
- **Q4.** **Channel-scoped webhook delivery, or a `channel_id` in payloads.** The root fix —
  eliminates the cross-channel mis-link class and most ignored-event volume. Worth re-raising
  from the Magento side because default `increment_id` collisions make us more exposed.
- **Q5.** Should 4xx count toward the 3-day disable window? Malformed input and "not my order"
  are very different signals.
- **Q6.** Should order-less / foreign-channel events reach channel endpoints at all?
- **Q7.** Does PATCH support un-cancelling? Blocks any reopen story.
- **Q8.** Make `GET /v2/orders?id=X` official and documented (it is not a path-style route and
  is account-scoped, not channel-scoped).

---

*Created 2026-07-30 from the 1.1.0 code review plus the WooCommerce v4 spec and lessons docs.*
