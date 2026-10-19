# Shipping ↔ Shopper alignment (design plan)

## Document control

| Attribute | Value |
|---|---|
| Document | Design plan for aligning Komerce Delivery shipping with Shopper 2.11's shipping model |
| Version | 0.1 |
| Status | **Draft** (design only; no behaviour change in this revision) |
| Human validation | **Not validated** |
| Evidence reviewed | 19 October 2026, against `staging` @ `742b472`, `shopper/*` v2.11.0 |
| Chain of Truth route | Brownfield change, artifact-only |
| Related | [`komerce-rajaongkir-goal-loop.md`](komerce-rajaongkir-goal-loop.md) (integration contract), `AGENTS.md` (capability detection and plan degradation) |

The goal is to keep OceanMall's customizations compatible with Shopper upgrades. Shopper should own the
generic concepts (driver contract, `OrderShipping`, order totals). The app should own only what Shopper
cannot express (multi-warehouse packages, Komerce order numbers, tariff snapshots).

Every phase below must keep the rules in `AGENTS.md`:

- Plan tier is detected at runtime by `KomerceCapabilities`, never configured.
- Every Delivery call is gated by `komerce_shipping_delivery_enabled()`. Production is on **Starter**: no
  Delivery API, Cost quota 100/day, manual AWB in cpanel, tracking via Cost `track/waybill`.
- Checkout quotes come from Delivery first, with Cost as the fallback. The tariff row selected at checkout
  is reused for Store Order without re-quoting.

---

## Summary of recommendations

| # | Topic | Recommendation |
|---|---|---|
| 0 | **Stale context in memoized drivers** (found while investigating) | **Fix first.** Drivers must resolve their scoped contexts at call time, not in the constructor. Reproduced locally; it affects Octane and `queue:work`. |
| 1 | Direct `ShippingDeliveryClient` calls | Move **fulfilment** (store order → pickup → detail → label), **cancel**, **reconcile/detail** and the **Store Order re-quote** behind `Shipping::driver('komerce')`. Do this through app-level capability interfaces, because Shopper's contract has no cancel/label/detail methods. Keep **destination search** and **carrier sync** app-level. Moving checkout `calculate` into `calculateRates()` is optional (Phase 4). |
| 2 | `order_shipments` vs `OrderShipping` | **Keep both.** `order_shipments` (+ lines) is the operational source of truth. `OrderShipping` is a **projection** with exactly one writer: `SyncOrderShippingFromShipments`. Add an explicit FK link, close the Starter manual-AWB bypass, and write tests for the projection. Do not converge onto Shopper's tables. |
| 3 | Shipping in `price_amount` | Shopper 2.11 has **one** supported hook: the `shopper.cart.pipelines.cart` config. Phase 5a: centralise the shipping total in one action. Phase 5b: append a pipeline stage after `Calculate`, so `CreateOrderFromCartAction` writes the final `price_amount` itself. Remove the post-create `update()`. |
| 4 | Migration | Six small phases, each test-first and shippable on its own (see §4). |

---

## 0. Findings that change the priority

### 0.1 Memoized Shopper drivers keep a stale scoped context (production risk)

**Facts**

- `Shopper\Shipping\ShippingManager` is a container **singleton** (`ShippingServiceProvider:19`).
  `driver()` memoizes every driver instance: `$this->drivers[$name] ??= $this->resolve($name)`.
- `AppServiceProvider::configureShopperShipping()` (`:195-196`) calls `Shipping::extend()` during **boot**,
  so the manager is resolved in the base application.
  - Octane clones the base application for each request, so the clones share the same manager object.
  - A `queue:work` process keeps one manager for its whole life.
- `RajaOngkirDriver` (`$quoteContext`) and `KomerceShippingDriver` (`$trackingContext`,
  `$fulfillmentContext`) receive **scoped** contexts in their constructors
  (`AppServiceProvider:57-59`, `$this->app->scoped(...)`).
- Laravel flushes scoped instances before every queued job (`QueueServiceProvider:263`,
  `forgetScopedInstances()`). Octane does the same for every request.

**Reproduction.** A script booted the app and resolved `Shipping::driver('rajaongkir')`. It then called
`app()->forgetScopedInstances()` and set a quote on the newly resolved `RajaOngkirQuoteContext`. Results:

```
same before flush: true
driver memoized: true
driver sees new quote: false
```

**Consequences.** These follow when a worker or request is not the first one in its process, i.e. under
Octane or `queue:work` as described in `README.md`. They do not show up under `php artisan serve`,
`queue:listen` (a fresh process per job) or PHPUnit (a fresh app per test).

| Path | Symptom |
|---|---|
| `FetchDeliveryRates::rajaOngkirRates()` → `RajaOngkirDriver::calculateRates()` | `hasQuote()` reads the old, cleared context and returns an **empty collection**. The Starter checkout shows **no couriers**. |
| `RefreshShipmentTracking` → `KomerceShippingDriver::track()` / `RajaOngkirDriver::track()` | The courier is `null`, so the driver throws "Courier … is required". Webhook-driven refreshes and `RefreshPendingShipmentTracking` fail. |
| `CreateRajaOngkirDeliveryForShipment::handle()` → `KomerceShippingDriver::createShipment()` | The driver throws "Order shipment context is required". All 5 retries run in the same process and fail the same way. |

The same pattern exists in `App\Payment\KomerceDriver` (`KomercePaymentLookupContext`, constructor-injected;
Shopper's `PaymentManager::driver()` memoizes the same way). That is outside this plan's scope; see §6.

**Fix (Phase 0).** Inside each driver method, resolve the context lazily, e.g. `resolve(RajaOngkirQuoteContext::class)`
or a private accessor. Drop the constructor injection. The clients (`ShippingCostClient`,
`ShippingDeliveryClient`) can stay injected **only if** they hold no per-request state. Check this, because
`UsesKomerceHttp` reports to `KomerceCapabilities`.

**Test first:** `tests/Unit/Shipping/DriverScopedContextTest.php`. For each driver:

1. Resolve the driver.
2. Call `app()->forgetScopedInstances()`.
3. Set the new context.
4. Assert that the driver sees it (`Http::fake` for the API).

This test must fail before the fix and pass after.

### 0.2 On Starter, cpanel manual AWB entry bypasses `order_shipments`

When Delivery is disabled, `App\Livewire\Shopper\SlideOvers\CreateShippingLabel::save()` (`:54`) calls
`parent::save()`. Shopper's `save()` creates an `OrderShipping` row **only**. Everything customer-facing
reads `order_shipments`, so the AWB the admin typed is invisible to:

- the account order page (`Account\OrderController:53`),
- the mobile API (`Api\V1\OrderController:89`),
- scheduled tracking (`RefreshPendingShipmentTracking:30`),
- and therefore Cost `track/waybill`.

No test covers this path. `ShopperShippingLabelRajaOngkirTest` only runs with Delivery enabled. This
contradicts the AGENTS.md promise of "manual AWB entry … with tracking via Cost `track/waybill`".
Phase 1 closes it.

---

## 1. Driver boundary: what goes through `Shipping::driver('komerce')`

### 1.1 Shopper's contract (v2.11)

`Shopper\Shipping\Contracts\ShippingDriver` contains:

- metadata: `code`, `name`, `logo`, `isConfigured`, `supports*`
- `calculateRates(Address, Address, Package[]): Collection<ShippingRate>`
- `createShipment(Address, Address, Package[], string $serviceCode): Shipment`
- `track(string): TrackingInfo`

All DTOs are `final readonly`. `ShippingRate` has no field for cashback, service fee or grand total.
`Shipment` has only the tracking number, carrier, service and label fields. There is **no**
cancel, label-print, order-detail or pickup method.

Shopper itself uses drivers only through `CarrierRateService` (`Carrier.driver` → `calculateRates`) and
the `Shipping` facade. Its cpanel `CreateShippingLabel` never calls `createShipment()`.

### 1.2 Inventory of Delivery calls today

| Call site | API | Through the driver? | Notes |
|---|---|---|---|
| `Jobs/CreateRajaOngkirDeliveryForShipment::handle()` `:72` | — | ✅ `createShipment()` | It sets `KomerceFulfillmentContext` first. |
| `KomerceShippingDriver::createShipment()` `:91` | — | ↩️ calls **back into the job**: `(new CreateRajaOngkirDeliveryForShipment($id))->execute($this->delivery)` | **Inverted dependency.** The driver depends on the job, and the job depends on the driver. All the fulfilment logic (~900 lines) lives in a queue job. |
| `…Job::execute()` `:151` | `storeOrder` | ❌ client passed in | |
| `…Job::execute()` `:183` | `requestPickup` | ❌ | |
| `…Job::execute()` `:195` | `detailOrder` (AWB fallback) | ❌ | |
| `…Job::printOfficialLabel()` `:790` | `printLabel` | ❌ | Duplicates `KomerceShippingDriver::printLabels()`. |
| `…Job::rateFromDeliveryCalculate()` `:889` | `calculate` (re-quote) | ❌ `resolve(ShippingDeliveryClient)` | Duplicates the bucket parsing in `FetchKomerceDeliveryRates::format()`, with different field handling and `cod=false`. |
| `…Job::execute()` `:123` → `ReconcileKomerceShipment` | `detailOrder` | ❌ | |
| `Actions/Shipping/ReconcileKomerceShipment::handle()` `:38` | `detailOrder` | ❌ | Also called by `IssueRajaOngkirFulfillment`. |
| `Actions/Shipping/CancelKomerceDelivery::cancelShipment()` `:51` | `cancelOrder` | ❌ | Called by the `OrderCancelled` listener and `CancelShopperOrderViaKomerce`. |
| `Actions/Shipping/PrintShipmentLabels::handle()` `:71` | `printLabel` | ✅ `printLabels()` (non-contract method; `instanceof KomerceShippingDriver`) | It type-checks the concrete class. |
| `Actions/Checkout/FetchKomerceDeliveryRates::handle()` `:75` | `calculate` (checkout) | ❌ | Cached for 10 min. Returns the rich rows that Store Order later needs. |
| `Actions/Shipping/RefreshShipmentTracking` `:50-56` | `track` | ✅ `track()` | It uses `KomerceTrackingContext` for the courier (see §0.1). |
| `Console/Commands/SyncKomerceCarriers` | `calculate` (Cost + Delivery probe) | ❌ by design | |
| `Shop/DestinationSearchController`, `Api/V1/DestinationController` | Cost destination search | ❌ by design | |

### 1.3 Proposal

**Principle.** Use `Shipping::driver('komerce')` for every call that a different carrier integration would
also need: create, cancel, label, detail/reconcile and quote. Keep app-level any call that exists only
because of Komerce's catalogue model (destination ids, courier list probing).

**Capability interfaces.** Shopper's contract lacks cancel, label and detail, so define small interfaces
in the app, `App\Shipping\Contracts\…`:

```php
interface CancelsShipments  { public function cancelShipment(string $providerOrderNo): void; }
interface PrintsLabels      { public function printLabels(array $providerOrderNos, string $page): array; }
interface ReadsShipmentDetail { public function shipmentDetail(string $providerOrderNo): ShipmentDetail; } // app DTO: orderNo, orderId, awb, rawStatus, raw
```

`KomerceShippingDriver` implements all three. Callers do this:

```php
$driver = Shipping::driver('komerce');
if (! $driver instanceof CancelsShipments) { /* degrade */ }
```

They no longer check `instanceof KomerceShippingDriver`. If a later Shopper version adds, say,
`cancelShipment()` to its own contract, only the driver changes.

**Fulfilment service.** Extract the body of `Job::execute()` and its helpers into
`App\Shipping\Komerce\KomerceDeliveryFulfillment`:

- `storeOrderPayload()`
- `deliveryRate()` / `rateFromDeliveryCalculate()`
- pickup
- label
- `metadataAfter*()`
- the validators

`KomerceShippingDriver::createShipment()` calls this service. The job becomes a thin queue wrapper:
uniqueness, retries, `failed()`, persisting `fulfillment_error`, and calling `Shipping::driver('komerce')->createShipment()`.
The circular job → driver → job call disappears.

**`createShipment()` input.** Shopper's signature cannot carry the `OrderShipment`. Two options:

- **(a, recommended)** Keep `KomerceFulfillmentContext` and resolve it lazily (§0.1). The job and
  `IssueRajaOngkirFulfillment` both set it. Also build real `Address` and `Package[]` arguments from the
  shipment, so that the payload is visible in the call and not only in the context.
- (b) Add an app-only `createShipmentFor(OrderShipment)` method. This is simpler, but it breaks the "only
  through the contract" goal for the most important call.

**Re-quote at Store Order.** Move `rateFromDeliveryCalculate()` into the driver's `calculateRates()`
(see Phase 4), or at least into one shared `KomerceDeliveryTariff` service used by both checkout and the
fulfilment service. The goal is one bucket parser and one set of rules for "is this an official tariff".

**What stays app-level, and why**

| Concern | Why it stays outside the driver |
|---|---|
| Destination search (`DestinationSearchController`, `Api\V1\DestinationController`, 30-day cache) | This is Komerce-specific address resolution (RajaOngkir destination ids). It has no Shopper equivalent, and the result is stored on the address, not used in a shipping call. |
| Carrier sync (`komerce:sync-carriers`) and capability probing (`komerce:capabilities`) | These manage the account and catalogue: which couriers the plan offers, and which plan tier applies. They write Shopper `Carrier` rows (Shopper's model), but the probing logic is provider administration, not a shipping operation. |
| Webhook parsing (`ApplyDeliveryWebhookStatus`) | This is inbound HTTP. It should keep calling `RefreshShipmentTracking`, which already goes through `track()`. |
| Presentation (`FetchKomerceDeliveryRates::format()`: logos, explainer, cpanel-disabled filter) | This is storefront formatting over rates. It stays even if the HTTP call moves into `calculateRates()`. |

---

## 2. Shipment data: `order_shipments` and `OrderShipping`

### 2.1 What each table can hold

| Need | `order_shipments` / `order_shipment_lines` | Shopper `sh_order_shipping` (+ `order_items.order_shipping_id`, `order_shipping_events`) |
|---|---|---|
| One package per warehouse (`inventory_id`) | ✅ | ❌ no inventory column, and `voucher` is the only JSON field |
| Split of **quantity** of one order item across warehouses | ✅ `lines.qty` | ❌ an `order_item` points to **one** `order_shipping_id` |
| Komerce `order_no` / `order_id`, tariff snapshot (`metadata.rate`), store/pickup/label responses | ✅ `metadata` | ❌ |
| Cost of each package | ✅ `cost` | ❌ (Shopper's shipping price is `CarrierOption.price` on the order) |
| Status timeline | `metadata.komerce.tracking_history` | ✅ `order_shipping_events` (a first-class Shopper concept) |
| Visible in the Shopper cpanel Shipments page or slide-overs | only through app overrides | ✅ natively |

### 2.2 Decision: keep both, with a single write path

Converging on Shopper's tables would lose the per-quantity warehouse split and every Komerce reference.
We would end up putting `metadata` back on a vendor table through a migration, which creates more
upgrade friction than it removes. So:

- **`order_shipments` is the source of truth.** Everything operational reads and writes it: checkout,
  allocation override, fulfilment, webhooks, tracking, customer and mobile views.
- **`OrderShipping` is a projection**, written **only** by `SyncOrderShippingFromShipments`. This
  includes the Starter manual-AWB path, which must first write `order_shipments` and then sync.
  Shopper features that read `OrderShipping` (the Shipments page, order badges, `shipping_status`) stay
  correct without our code reading it back.

### 2.3 Concrete changes (Phase 1)

1. **Explicit link.** Add a migration with a nullable `order_shipments.order_shipping_id` FK to
   `shopper_table('order_shipping')`, `nullOnDelete`. The FK lives on **our** table, so no vendor table is
   altered. In `syncShopperOrderShipping()`, upsert by this id instead of `['order_id', 'tracking_number']`.
   This fixes two problems:
   - A re-issued or corrected AWB currently leaves an orphan `OrderShipping` row.
   - `OrderShipments::orderShipmentFor()` uses fuzzy AWB matching and falls back to the "latest shipment",
     which picks the wrong package when an order has several warehouses.
2. **Starter manual AWB.** In `CreateShippingLabel::save()`, when Delivery is disabled, stop calling
   `parent::save()`. Instead:
   1. Write the AWB, carrier and status `labeled` onto the matching `OrderShipment`. If the order somehow
      has none, create it through `EnsureOrderShipments`.
   2. Call `SyncOrderShippingFromShipments`.
   3. Fire Shopper's `OrderShipmentCreated` with the projected row, so Shopper listeners still run.

   Keep Shopper's form and validation (`parent::mount()`/form schema). Only the persistence changes.
3. **Projection correctness** (each item gets its own test):
   - Map `picked_up` → `ShipmentStatus::PickedUp`, which Shopper has; today it maps to `InTransit`.
   - Map `cancelled` to an explicit state. Today it falls to `Pending` / `FulfillmentStatus::Processing`;
     items should get `FulfillmentStatus::Cancelled`.
   - Set `shipped_at` only once, the first time the status becomes active. Today every sync overwrites it
     with `now()`.
   - Carrier lookup: match by slug only, through `RajaOngkirCouriers` aliases. Remove the "any enabled
     carrier" fallback, which mislabels the courier; leave `carrier_id` null when unknown.
   - Write new tracking events into `order_shipping_events` (Shopper's timeline) next to the metadata
     history, deduplicated by `(status, occurred_at)`.
   - Item attachment: today, an order item split across two warehouses attaches only to the first
     shipment. Keep that, but document it and test it; it is a Shopper model limit.
4. **Optional:** project a `Pending` `OrderShipping` as soon as an `OrderShipment` exists (before the AWB),
   so the cpanel Shipments page shows packages waiting for pickup. **Check first** how Shopper's
   fulfilment UI treats items that already have an `order_shipping_id`; it may hide "create label".
   This needs a cpanel test before deciding.

### 2.4 Risks

| Risk | Mitigation |
|---|---|
| Multi-warehouse orders: items, quantities and shipments do not map 1:1 onto Shopper | `order_shipment_lines` stays authoritative. The projection documents that a split item attaches to its first shipment, and `SplitShipmentOrderTest` gets a sync assertion. |
| cpanel Shipments page (`App\Livewire\Shopper\Pages\OrderShipments`) resolves actions by fuzzy AWB match | Use the explicit FK (2.3.1). Add a test with two warehouses where each print or refresh action targets the right `OrderShipment`. |
| Two writers of `OrderShipping` (sync and Shopper's `CreateShippingLabel::save`) | Close it in 2.3.2. An architecture test asserts that only `SyncOrderShippingFromShipments` references `OrderShipping::query()->create/updateOrCreate` under `app/`. |
| Reporting: Shopper's dashboard (`StatCards`, `RevenueChart`) sums `orders.price_amount`, so **revenue includes shipping** | This is a product decision; record it. If revenue should exclude shipping, that is a dashboard override, not a data change. Shopper's own `total()` is `items->sum('total')`, so item-level reports stay pure. |
| Backfill of the new FK for existing orders | Match existing `order_shipping` rows by `(order_id, tracking_number)` with an idempotent command, and run it before switching the upsert key. |
| A Shopper upgrade changes `order_shipping` columns or enums | Add the upgrade checklist (§5) and pin the projection with a test of its shape. |

---

## 3. Order total: shipping in `price_amount`

### 3.1 Today

- `CreateOrder::handle()` calls Shopper's `CreateOrderFromCartAction::execute()`. That sets `price_amount`
  to the cart pipeline's `$context->total` (subtotal − discount + tax).
- Next, `storeShipments()` adds up the package costs, and `CreateOrder.php:77` runs
  `update(['price_amount' => $order->price_amount + $shippingPrice])` in the same DB transaction.
- `OverrideAllocation::updateOrderShippingTotal()` (`:550`) later applies a delta to `price_amount` when
  packages are re-allocated.
- Readers re-derive shipping in three different ways:
  - `App\Livewire\Shopper\OrderSummary` (`:23`): `CarrierOption.price`, else the sum of shipment costs,
    else `price_amount − subtotal`
  - `Api\V1\OrderController` (`:132`): an "adjustment" remainder
  - the payment actions: `price_amount`
- `shipping_option_id` is normally `null`, because Komerce rates are not `CarrierOption`s. Shopper's stock
  `OrderSummary` would therefore show shipping 0 with a total that includes it, which is why the app overrides it.

### 3.2 What Shopper 2.11 offers

- `CreateOrderFromCartAction` is `final readonly` and has no container binding, events before creation,
  or shipping parameter. `CartCompleted` fires **after** creation. `config/orders.php` covers only number
  generation.
- **The supported extension point is `shopper.cart.pipelines.cart`** (published at
  `config/shopper/cart.php`):
  `CalculateLines → ApplyDiscounts → CalculateTax → Calculate`. `CartPipelineRunner` runs this list for
  every `CartManager::calculate()`, including the call inside `CreateOrderFromCartAction`.
- `CartPipelineContext` is `final` and has no `shippingTotal` field. A stage can only add to `$total`.
  Dynamic properties are deprecated, so do not add one.
- Shopper has no native per-order shipping amount. Its own UI uses
  `items total + shippingOption.price` (`shipping-label-summary.blade.php:23`).

### 3.3 Recommendation

**5a (low risk, first).** Add one `App\Actions\Orders\OrderShippingTotal` with `forOrder(Order): int`
(the sum of `order_shipments.cost`) and `forCheckout(AllocationPlan, $checkout): int`. Then:

- Make `CreateOrder`, `OverrideAllocation`, `OrderSummary` and `Api\V1\OrderController` use it.
- Expose `shipping` explicitly in the API totals instead of the remainder.

This removes three divergent derivations without changing any stored value.

**5b (Shopper-native).** Add `App\Cart\Pipelines\AddCheckoutShipping` **after** `Calculate` in
`config/shopper/cart.php`. It adds `CheckoutShippingContext::total()` to `$context->total`. The context
is scoped, set and cleared by `CreateOrder` the same way `CheckoutAllocationContext` already is, and
resolved lazily.

- Outside checkout the context is empty, so the stage is a no-op: cart page, mini-cart, API cart.
- `CreateOrder` computes package costs **before** calling Shopper. Split `storeShipments()` into
  `priceShipments()` (pure) and `persistShipments()` (after the order exists). The `price_amount`
  arithmetic in `:77` then goes away.
- Benefit: Shopper writes the final `price_amount` itself, inside its own locked transaction. Anything
  listening to `CartCompleted` or model events sees the correct total. The app no longer depends on
  running a second update after a vendor action.
- Shipping stays untaxed. This matches today's behaviour; confirm the PPN treatment with the product owner.

**Do not** model Komerce services as `CarrierOption` rows just to fill `shipping_option_id`. Prices change
per route, weight and package, and a split order has several services.

**Open risk:** `OverrideAllocation` changes `price_amount` after the order exists. If it can run after a
Komerce payment was created, the charged amount and `price_amount` diverge (`MarkOrderPaidFromKomerce`
compares against `transaction.amount ?? price_amount`). Add a test that pins the intended rule: either
block re-pricing after a payment exists, or record the difference.

---

## 4. Phased migration plan

Each phase is one PR. It starts with tests that pass on current behaviour (characterization) or fail on a
known bug (marked 🔴). It must keep the Starter degradation and must not change the checkout UX.

Run the suite with `npm run build` and then `php -d memory_limit=1G vendor/bin/phpunit`.

### Phase 0: driver context safety (bug fix, smallest)

- **Tests first**
  - 🔴 `tests/Unit/Shipping/DriverScopedContextTest.php`: for `rajaongkir` `calculateRates`/`track` and
    `komerce` `track`/`createShipment`, resolve the driver, `forgetScopedInstances()`, set the new context,
    and assert that it is used.
  - 🔴 Feature test: dispatch two `CreateRajaOngkirDeliveryForShipment` jobs in one process
    (`Bus::dispatchSync` twice with `forgetScopedInstances()` in between, or `Queue::fake` +
    `->handle()`), and assert that both shipments get an AWB (`Http::fake` the Delivery endpoints).
- **Change:** `app/Shipping/Drivers/{RajaOngkirDriver,KomerceShippingDriver}.php` resolve contexts per call.
- **Verify:**
  - the full suite;
  - locally, run `php artisan octane:start` and request the Cost checkout rates twice: the second response
    must not be empty;
  - run `queue:work` with two paid sandbox orders (`komerce:simulate-payment`), and both get an AWB.

### Phase 1: the projection as the single write path

- **Tests first**
  - `tests/Feature/Shipping/SyncOrderShippingFromShipmentsTest.php` (new; nothing covers it today). Cover:
    - one package
    - two warehouses
    - an AWB change
    - status mapping per `NormalizeShipmentStatus` value
    - `shipped_at` set once
    - an unknown carrier
    - idempotency (run twice, same rows)
  - 🔴 Starter manual AWB, in `tests/Feature/Cpanel/ShopperShippingLabelRajaOngkirTest.php` with Delivery
    disabled. Admin enters an AWB, then assert that:
    - `order_shipments.awb` is set,
    - the account page shows it,
    - `RefreshPendingShipmentTracking` picks it up,
    - only one `OrderShipping` row exists.
  - An architecture test that `OrderShipping` writes live only in `SyncOrderShippingFromShipments`.
- **Change:**
  - migration `order_shipments.order_shipping_id`
  - backfill command
  - `SyncOrderShippingFromShipments`
  - `CreateShippingLabel::save()`
  - `OrderShipments::orderShipmentFor()` uses the FK
- **Verify:**
  - with Delivery on (sandbox), print and refresh actions on a two-warehouse order;
  - with Delivery off, manual AWB, then the customer page, then Cost tracking;
  - the backfill on a copy of production data reports 0 unmatched rows.

### Phase 2: fulfilment behind the driver (removes the job ↔ driver cycle)

- **Tests first:** keep `ShippingDeliveryTest`, `ReconcileKomerceShipmentTest`, `WarehouseOpsE2ETest` and
  `KomerceDisabledTest` green **unchanged**. They are the characterization net. Add unit tests for
  `KomerceDeliveryFulfillment` that cover:
  - a store-order payload snapshot
  - resuming from stored `order_no`
  - a cancelled remote order blocking pickup
  - an AWB missing from pickup, then filled from detail
  - a label failure keeping the AWB
- **Change:**
  - new `app/Shipping/Komerce/KomerceDeliveryFulfillment.php`, moved from `Job::execute()` and its helpers
  - `KomerceShippingDriver::createShipment()` calls it
  - the job keeps `handle()`/`failed()`/uniqueness only
  - `IssueRajaOngkirFulfillment` and `DispatchRajaOngkirDelivery` are untouched apart from imports
- **Verify:** a sandbox paid order runs store → pickup → AWB → label, with `metadata.komerce.*` keys
  identical to before (diff a before/after snapshot of one shipment's metadata).

### Phase 3: cancel, detail, label through capability interfaces

- **Tests first:** existing `PrintLabelTest` and the cancel tests (`KomerceCheckoutTest`/listener). Add a
  test that, with a fake driver bound under `komerce` that does **not** implement `CancelsShipments`,
  cancel degrades without throwing.
- **Change:**
  - `app/Shipping/Contracts/{CancelsShipments,PrintsLabels,ReadsShipmentDetail}.php`
  - `app/Shipping/Data/ShipmentDetail.php`
  - `CancelKomerceDelivery`, `ReconcileKomerceShipment` and `PrintShipmentLabels` call
    `Shipping::driver('komerce')` and check the interface
  - remove `ShippingDeliveryClient` from their constructors
  - the job's `printOfficialLabel()` uses `PrintsLabels`
- **Verify:**
  - `grep -rln "ShippingDeliveryClient" app/` lists only the client itself, the driver, `KomerceDeliveryFulfillment`,
    `SyncKomerceCarriers`, `PrintShipmentLabelController` (which uses a constant only), and `FetchKomerceDeliveryRates` until Phase 4;
  - in sandbox, cancel an unpaid order and see the remote order cancelled.

### Phase 4 (optional): Delivery quotes via `calculateRates()`

- **Precondition:** Phase 0. Komerce needs pin points and item value, which `Address`/`Package` do not
  carry, so this requires a `KomerceQuoteContext` (origin/destination ids, pins, item value).
- **Tests first:** `KomerceDeliveryRatesTest` and `RajaOngkirRatesTest` unchanged. Add assertions that:
  - checkout and the Store Order re-quote use the same parser;
  - the 10-minute cache key is unchanged;
  - with the Starter capability, `calculateRates()` is never called.
- **Change:**
  - `KomerceShippingDriver::calculateRates()` returns `ShippingRate`s, plus the rich rows through
    `KomerceQuoteContext::lastRows()`, keyed by `serviceCode`
  - `supportsRealTimeRates()` becomes `true`
  - `FetchKomerceDeliveryRates` keeps only selection, formatting and fallback signalling
  - the fulfilment re-quote uses the driver
- **Verify:** the checkout rates payload is byte-for-byte identical before and after for a fixed
  `Http::fake` (snapshot test).
- **Skip this phase** if Shopper still has no carrier configuration that uses `Carrier.driver = 'komerce'`.
  The gain is mostly one parser, which Phase 2's shared tariff service already provides.

### Phase 5: order total

- **5a tests first:** `CreateOrder` with:
  - one package
  - a split order
  - a coupon
  - a tax-inclusive zone

  Assert `price_amount == cart total + Σ order_shipments.cost` and Komerce `amount == price_amount`. Add
  `OverrideAllocation` repricing (with the payment rule from §3.3), `OrderSummary` render and API `totals`.
- **5a change:**
  - `app/Actions/Orders/OrderShippingTotal.php`
  - the four call sites
  - the API `totals.shipping` comes from the action
- **5b tests first:** the same tests, plus "cart page total excludes shipping" and "pipeline stage is a
  no-op without the context".
- **5b change:**
  - `app/Cart/Pipelines/AddCheckoutShipping.php`
  - `app/Support/CheckoutShippingContext.php` (scoped)
  - register the stage after `Calculate` in `config/shopper/cart.php`
  - split `CreateOrder::storeShipments()`
  - delete the `:77` arithmetic
- **Verify:** sandbox checkout (Delivery and the Cost fallback). The charged amount equals the order
  total in cpanel, the storefront and mobile. The cart page shows no shipping.

---

## 5. Shopper upgrade checklist (shipping)

Run this on every `shopper/*` bump:

1. `ShippingDriver` contract: new methods (e.g. cancel/label)? If so, implement them on both drivers and
   retire the matching app interface.
2. `ShippingManager::driver()` still memoizes? Phase 0 makes this harmless, but keep the test.
3. `CreateOrderFromCartAction`: does it now accept shipping or fire a pre-create hook? Does the cart
   pipeline gain a shipping stage or a `shippingTotal`? If so, remove `AddCheckoutShipping` to avoid
   **double-counting**.
4. Diff `vendor/shopper/cart/config/cart.php` against `config/shopper/cart.php` (new default stages).
5. `order_shipping` / `order_shipping_events` columns and the `ShipmentStatus` / `FulfillmentStatus`
   enums are unchanged. The projection test pins the mapping.
6. The cpanel classes we extend still exist with the same hooks: `CreateShippingLabel::save/mount`,
   `Pages\Order\Shipments::table`, `Components\Orders\OrderSummary::render`.
7. `patches/shopper-core-idr-zero-decimal.patch` still applies (see AGENTS.md).

## 6. Out of scope / follow-ups

- `App\Payment\KomerceDriver` has the same stale-context pattern as §0.1 (`KomercePaymentLookupContext`,
  memoized by Shopper's `PaymentManager`). Give it the same fix and test in a separate change.
- `DispatchRajaOngkirDelivery` runs the job in `app()->terminating()`. Under Octane that is still in the
  worker; check that it does not block the worker (timeouts) once Phase 0 makes it actually succeed.
- Revenue reporting with or without shipping (§2.4) needs a product decision.
