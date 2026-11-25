# AGENTS.md

## Cursor Cloud specific instructions

OceanMall is a single Laravel 13 + Shopper e-commerce app (no monorepo). Two surfaces of the
same app: the Inertia/Vue 3 storefront + customer account, and the Shopper (Filament/Livewire)
admin panel at `/cpanel`. Standard commands live in `README.md`, `composer.json` (`scripts`),
and `package.json` (`scripts`) — use those; only the non-obvious notes below are documented here.

### Runtime / stack facts
- **PHP 8.4 is required** (not just 8.3). The committed `composer.lock` pins
  `roave/better-reflection`, which requires `~8.4`, so `composer install` fails platform checks
  on PHP 8.3. PHP 8.4 satisfies the app's `^8.3` constraint.
- DB is SQLite (`database/database.sqlite`); sessions/cache/queue are database-backed. No external
  datastore is needed for local dev.
- The dev env (`.env`, migrated `database/database.sqlite`, `vendor/`, `node_modules/`,
  `public/build`) is created during setup and persists in the VM snapshot; it is gitignored, so it
  is NOT recreated by pulling the repo. If a truly fresh env is ever needed, replay `README.md`
  setup: `cp .env.example .env`, `php artisan key:generate`, `touch database/database.sqlite`,
  `php artisan migrate`.

### Running the app
- `composer run dev` runs `php artisan serve` (:8000), `queue:listen`, `pail`, and `npm run dev`
  (Vite HMR, :5173) together. App: `http://localhost:8000` · Admin: `http://localhost:8000/cpanel`.
- Admin login created during setup: `admin@oceanmall.test` / `password123` (role `administrator`).
  The admin dashboard is `/cpanel/dashboard`. Roles/permissions and base domain data were seeded
  via `Shopper\Database\Seeders\AuthTableSeeder` and `Shopper\Core\Database\Seeders\ShopperSeeder`.

### Non-obvious gotchas
- **Case-sensitivity (repo was developed on macOS; Linux is case-sensitive).**
  - The Vite build imports must match on-disk file casing exactly. A fix was needed for
    `resources/js/components/shop/announcement-bar.vue` (imported `./Container.vue`; file is
    `container.vue`). Watch for similar casing mismatches when the build says "Module not found".
  - Laravel Wayfinder regenerates `Index.ts` (PascalCase) alongside the committed `index.ts`
    (lowercase) under `resources/js/actions/**` and `resources/js/routes/**` on Linux. These are
    generated build artifacts — do NOT commit the regenerated case-variant duplicates (or the
    `package-lock.json` churn) that appear after `npm install` / `npm run build`.
- **Do NOT run `php artisan shopper:link` when serving via `php artisan serve`.** It creates a real
  `public/cpanel` symlink that shadows the `/cpanel` admin route (PHP's built-in server serves the
  physical directory, so `/cpanel` 404s). The repo intentionally ships a dangling `public/cpanel`
  symlink so `/cpanel` routes to the panel; leave it dangling. The only side effect is that a few
  cosmetic `/cpanel/images/*` favicons 404.
- **Tests need built assets.** Feature tests render Inertia views and fail with "Vite manifest not
  found" unless `npm run build` has been run (or the Vite dev server is up). Run `npm run build`
  before `composer test`.
- **Production is PostgreSQL, tests and CI are SQLite only.** Nothing here runs PostgreSQL, so write
  portable SQL: never query a JSON path on a TEXT column (`notifications.data` is TEXT: filter in PHP,
  see `NotifyOrderCustomer::alreadySent()`), never use a select alias in HAVING (`havingRaw` with the
  aggregate), use `lower(col) like ?` for case-insensitive search, and constrain uuid route parameters
  (`whereUuid`). The pgsql connection sets the session time zone to the app's (`DB_TIMEZONE`).
- **Checkout state is voided when what it was quoted for changes.** Saving the shipping address (web
  and API), changing the cart, and absorbing / adopting a guest cart forget the picked delivery option,
  allocation plan and payment. `CreateOrder::planMatchesCart()` is the backstop: an order is refused
  (422 "Your cart changed...") unless the plan lists exactly the cart's products and quantities.
  `NotifyOrderCustomer` is best effort and runs after commit: it never throws into its caller.
- **Storefront pages pass Eloquent models to Inertia as-is: every serialised attribute is public.**
  `App\Models\Price` hides `cost_amount` (returned by `HasProductPricing::prices()` on Product and
  ProductVariant); read the attribute directly in staff code. Do not load prices for a storefront page
  through `Shopper\Core\Models\Price` directly, and scope related / nested products to `publish`.
- **Account security rules.** API login asks for the two-step code when the account has 2FA (per-account
  attempt limit `api-two-factor:{id}`); `User::booted()` deletes Sanctum tokens whenever the password
  changes (the API change-password request keeps its own token); `AuthenticateSession` signs out other
  web sessions; confirming 2FA deletes tokens; tokens expire after `sanctum.expiration` (90 days).
  The customer's track endpoints go through `RefreshShipmentTracking::handleForCustomer()` (15 min
  cooldown per shipment, final statuses never re-asked) plus the `shipment-track` limiter; staff,
  webhooks and the schedule call `handle()`.
- **Order money rules added by the QA fixes.** `orders:expire-unpaid` also cancels (a day after
  creation) unpaid orders with NO payment transaction whose method belongs to an expiring driver;
  `PaymentMethodCatalog::amountOutOfRange()` is checked by web and API before an order exists;
  `ReleaseDiscountOnOrderCancelled` gives an unpaid cancelled order's coupon use back and clears
  `orders.discount_id` (kept in `metadata.released_discount_id`); the signed Komerce payment webhook
  answers 503 when the payment cannot be verified so it is delivered again; `ConfirmOrderReceived`
  needs every non-cancelled parcel shipped. Order mails (`OrderStatusMail`) and the verification mail
  (`QueuedVerifyEmail`) are QUEUED: a worker must run everywhere; `OrderStatusNotification` is the
  in-app (database) notification only.
- **Test suite is green as of 2026-11-25 (1032 tests; older note: 538 on 2026-11-18).** Tests use kebab-case Inertia page names
  (`settings/security`, `auth/confirm-password`), `first_name`/`last_name` profile fields, and
  `@example.com` addresses. The `email:rfc,dns` rule is skipped under `runningUnitTests()` because
  the DNS validator rejects reserved domains and needs network access.
- **IDR money handling is a vendor patch.** Shopper core treats IDR as a 2-decimal currency; the fix
  lives in `patches/shopper-core-idr-zero-decimal.patch`, applied by `cweagans/composer-patches` on
  `composer install`. Do NOT reintroduce `function_exists` helper overrides loaded before Composer
  (the old `bootstrap/pre_autoload.php`): Octane/FrankenPHP requires `vendor/autoload.php` directly
  and bypasses them. After bumping `shopper/core`, confirm the patch still applies.
- **Run the suite with `php -d memory_limit=1G vendor/bin/phpunit`.** At the default 128M the run
  dies with a Blade/icon-manifest memory exhaustion around the Auth tests (pre-existing).
- **Checkout quotes go through the Shopper shipping drivers.** `FetchDeliveryRates` asks every
  configured real-time driver for the carriers it owns (`carriers.driver`), passing destination id /
  pin point / warehouse / item value in the neutral `App\Shipping\ShippingQuoteContext`. With NO
  provider configured it uses Shopper's zone options; when a configured provider returns nothing
  (quota, outage, no origin) checkout shows no courier plus a hint, as before the refactor, unless
  `SHIPPING_FALLBACK_TO_ZONE_OPTIONS=true` (`SHIPPING_COMBINE_ZONE_OPTIONS=true` always offers manual
  carriers' zone options next to provider quotes, also when the provider answers nothing). Driver switches `shopper.{shipping,payment}.drivers.{code}.enabled`
  follow Shopper's rule (missing = off, parsed as bool: `App\Support\OperatorSwitch`). The
  `2026_11_18_000100`–`000400` migrations are expand-only (rolling-deploy safe at the schema level; swap
  ALL queue workers at once): `000100` COPIES `sh_inventories.rajaongkir_origin_id` into
  `inventory_shipping_origins` and keeps the column for the previous release. Current code never
  reads or writes that column (only an explicitly set, dirty `rajaongkir_origin_id` model attribute
  is mapped by `InventoryObserver`); a later contract release drops it (README "Upgrading to
  provider-neutral shipping"). Komerce Delivery is preferred over
  RajaOngkir Cost because `RajaOngkirDriver` *DelegatesQuotes* to `komerce`
  (`ShippingCapabilities::quoteChains()`), not because of an if/else in checkout. The Delivery tariff
  (`GET /tariff/api/v1/calculate`, needs warehouse + destination pin points and `item_value > 0`) is
  `KomerceShippingDriver::calculateRates()`; the selected row is persisted with
  `provider=shipping_delivery` so Store Order (`App\Integrations\Komerce\Services\KomerceDeliveryFulfillment`,
  reached via `FulfillsShipments`) reuses it without re-quoting.
- **One payment provider and one shipping provider sell at a time.** `App\Support\ActiveProvider` picks
  the provider family per section: store setting `shopper.{payment,shipping}.provider`
  (`PAYMENT_PROVIDER` / `SHIPPING_PROVIDER`, `none` = no provider), else the first registered driver
  whose switch is on and `isConfigured()`. `rajaongkir` + `komerce` shipping are ONE family
  (`ShippingCapabilities::providerFamily()`). `OperatorSwitch::on()` = switch on AND selected: it gates
  SELLING (payment method lists, quote chains, destination driver, carrier sync, carrier display,
  the COD rule of PaymentMethodSeeder, place-order via SelectedShipmentRates::coverPlan). Seeders do NOT
  bake the selection into is_enabled: rows of a standby provider stay enabled and are hidden on read. `OperatorSwitch::switchedOn()` = the switch alone: it gates SERVICING orders a previous
  provider already holds (webhook middlewares, `PaymentMethodCatalog::driverServiceable()` in
  `orders:expire-unpaid`, `ShippingCapabilities::serviceable()` behind `fulfillerFor*`, `trackerFor`,
  `forShipmentSupporting`, `whereFulfillable`, and the fulfil / tracking schedules). Never put the
  selection into a driver's `isConfigured()` or a webhook gate: paid callbacks of issued invoices
  would be acknowledged and dropped. `php artisan providers:status` prints the result. An explicitly
  named provider without a key is not replaced by another one. Tests that need a second provider next
  to the selected one must set `shopper.*.provider` explicitly.
- **One primary destination vocabulary.** An address stores one `destination_id` +
  `destination_driver` (`App\Shipping\AddressDestination`; legacy `rajaongkir_*` keys are still read
  as a fallback) picked from the first configured `ResolvesDestinations` driver
  (`ShippingCapabilities::destinationDriver()`). A second configured `ResolvesDestinations` driver
  gets the id its own search finds for the address's exact 5-digit postal code
  (`ShippingQuoteContext::destinationId()` → `secondaryDestination()`), and quotes nothing without a
  match (searched by postal code, then the address city/label; cached per driver+region 30 d / 1 d on a miss); the primary driver never guesses. Per-driver picked destinations are not supported. Old app builds without a destination driver get address-book regions from `/api/v1/checkout/destinations` (`AddressBookDestinations`) and a `text:` legacy id on addresses (never stored).
- **Courier and payment lists come from the providers, not from config.** `php artisan
  shipping:sync-carriers` (alias `komerce:sync-carriers`, scheduled daily) runs every configured
  driver that `SyncsCarriers`; the RajaOngkir driver probes Komerce Delivery (and RajaOngkir Cost for
  information) for the warehouse → reference route (`komerce.sync.*`) and enables the couriers
  Delivery offers. It only touches carriers with `driver=rajaongkir` and keys them by
  `metadata.courier_code`. Generic lifecycle commands: `shipping:fulfill-paid`,
  `shipping:refresh-tracking`, `orders:expire-unpaid` (old `komerce:*` names are aliases); with no
  provider configured they, and their schedules, do nothing (except that `orders:expire-unpaid` still
  cancels, locally and 24 h past their stored expiry, unpaid orders of a switched-off / keyless driver).
  `RAJAONGKIR_COURIERS` is an optional override and should stay empty. Payment methods in the footer/checkout are matched against the
  official Komerce catalog (`GET /api/v1/user/methods`) and use its `display_name`/`logo_url`;
  `channel_code` in `sh_payment_methods` metadata must equal the catalog `bank_code` (e.g. `BRI`).
- **Plan tier is detected at runtime, never configured.** `App\Integrations\Komerce\Support\KomerceCapabilities`
  learns entitlements from the APIs (2xx = entitled, "Invalid API key" 400/401/403 = not on the
  plan), counts Shipping Cost requests per WIB day, remembers 429/"limit" answers until midnight,
  and infers the tier (starter / pro / komship). `php artisan komerce:capabilities` probes and
  prints it (scheduled 00:05, before `shipping:sync-carriers`); every real API response is also
  observed passively via `UsesKomerceHttp`, so a rejected product switches off within one call.
  `App\Integrations\Komerce\Komerce::*Enabled()` (module-internal; the old global
  `komerce_*_enabled()` helpers are gone) = key configured AND entitled. Reads are memoised per
  request/job, but every write (`observe()`/`touch()`) re-reads the cache and rewrites only that
  product's entry under a short `Cache::lock`, so a concurrent 429 / "not entitled" verdict is never
  erased by a stale memo; the daily counter uses atomic `Cache::add` + `increment`.
- **The Collaborator account is currently on the free (Starter) plan**: Shipping Cost Check (100
  requests/day, 17 domestic couriers), Payment API and QRISLY only — **no Shipping Delivery API in
  production** (sandbox keys do have it). Everything degrades by `Komerce::shippingDeliveryEnabled()`:
  checkout quotes via RajaOngkir Cost (cached 12 h per route+weight), destination searches are
  cached 30 days, `shipping:sync-carriers` enables whatever Cost quotes, and cpanel falls back to
  Shopper's manual AWB entry (`CreateShippingLabel::save()` → `parent::save()`), with tracking via
  Cost `track/waybill`. Keep an eye on the daily quota; upgrading the plan re-enables Delivery.
  The `komerce` cpanel card renders for orders shipped (or quoted) through a Komerce driver
  (`rajaongkir` or `komerce`), so it also shows on Starter; Delivery-only buttons inside it stay
  hidden without the Delivery API.
- **Mobile QA on sandbox.** The sandbox payment page cannot be completed and webhooks cannot reach a
  laptop: `php artisan komerce:simulate-payment ORD-...` (local env + sandbox host only) marks an order
  paid through the real `MarkOrderPaidFromKomerce` path (which hands over to the neutral
  `App\Actions\Orders\MarkOrderPaid` and Shopper's `OrderPaid`). Paid orders need `queue:listen` to reach
  Delivery. Tester checklist and accounts: `mobile/TESTING.md`. Metro without watchman can miss newly
  created files ("Unable to resolve module") — restart with `npx expo start -c`.
- **Komerce is a self-contained module: `app/Integrations/Komerce/**`.** `KomerceServiceProvider`
  (listed in `bootstrap/providers.php`) owns everything Komerce: config merge (the ONLY credential
  source is `app/Integrations/Komerce/config/komerce.php`, key `komerce.*`; publish with
  `vendor:publish --tag=komerce-config`), `Payment::extend('komerce')`, `Shipping::extend('rajaongkir'|'komerce')`,
  the `komerce` cpanel addon, webhook routes (`routes/webhooks.php`, names/URLs `webhooks.komerce.*`
  unchanged), the `komerce:capabilities` / `komerce:uat` / `komerce:simulate-payment` commands, the
  00:05 schedule, views (`komerce::`), carrier/payment logo sources, scoped contexts, its payment
  method seeder (`PaymentMethodSeeder::extend`) and the critical log level for paid-after-cancel.
  `config/shopper/{payment,shipping}.php` keep only `enabled` for its drivers. Generic code
  (AppServiceProvider, routes/*.php, controllers, actions) must NEVER import `App\Integrations\Komerce`
  — `tests/Feature/Komerce/KomerceModuleTest.php` enforces it. Removing the provider line + folder
  leaves a pure-Shopper store (manual payment, zone shipping options, manual AWB). New providers go
  in their own `app/Integrations/<Name>` module; see README "Adding a payment/shipping provider".
- **Payment/shipping (Komerce / RajaOngkir).** Full checkout needs sandbox creds
  (`KOMERCE_PAYMENT_API_KEY`, `KOMERCE_SHIPPING_*_API_KEY`, `KOMERCE_WEBHOOK_SECRET`; base URLs stay
  commented in `.env.example` so outside production they default to sandbox and in production they
  must be set). Storefront browsing, cart, checkout (manual payment + zone shipping options) and the
  admin panel work without them — see README "Running without Komerce". Stripe stays disabled
  (`PAYMENT_STRIPE_ENABLED=false`).
