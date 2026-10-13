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
- **Test suite is green as of 2026-10-13 (387 tests).** Tests use kebab-case Inertia page names
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
- **Checkout quotes come from Komerce Delivery, not RajaOngkir Cost.** `FetchDeliveryRates` first
  calls `FetchKomerceDeliveryRates` (`GET /tariff/api/v1/calculate`, needs warehouse + destination
  pin points and `item_value > 0`); the selected row is persisted on the shipment with
  `provider=shipping_delivery` so `CreateRajaOngkirDeliveryForShipment` reuses it for Store Order
  without re-quoting. RajaOngkir Shipping Cost is only the fallback (no Delivery key / no pin
  points) plus destination search and waybill tracking.
- **Courier and payment lists come from the providers, not from config.** `php artisan
  komerce:sync-carriers` (scheduled daily) probes Komerce Delivery (and RajaOngkir Cost for
  information) for the warehouse → reference route (`komerce.sync.*`) and enables the couriers
  Delivery offers; the storefront footer and checkout read that carriers table.
  `RAJAONGKIR_COURIERS` is an optional override and should stay empty. Payment methods in the footer/checkout are matched against the
  official Komerce catalog (`GET /api/v1/user/methods`) and use its `display_name`/`logo_url`;
  `channel_code` in `sh_payment_methods` metadata must equal the catalog `bank_code` (e.g. `BRI`).
- **Plan tier is detected at runtime, never configured.** `App\Support\KomerceCapabilities`
  learns entitlements from the APIs (2xx = entitled, "Invalid API key" 400/401/403 = not on the
  plan), counts Shipping Cost requests per WIB day, remembers 429/"limit" answers until midnight,
  and infers the tier (starter / pro / komship). `php artisan komerce:capabilities` probes and
  prints it (scheduled 00:05, before `komerce:sync-carriers`); every real API response is also
  observed passively via `UsesKomerceHttp`, so a rejected product switches off within one call.
  All `komerce_*_enabled()` helpers = key configured AND entitled.
- **The Collaborator account is currently on the free (Starter) plan**: Shipping Cost Check (100
  requests/day, 17 domestic couriers), Payment API and QRISLY only — **no Shipping Delivery API in
  production** (sandbox keys do have it). Everything degrades by `komerce_shipping_delivery_enabled()`:
  checkout quotes via RajaOngkir Cost (cached 12 h per route+weight), destination searches are
  cached 30 days, `komerce:sync-carriers` enables whatever Cost quotes, and cpanel falls back to
  Shopper's manual AWB entry (`CreateShippingLabel::save()` → `parent::save()`), with tracking via
  Cost `track/waybill`. Keep an eye on the daily quota; upgrading the plan re-enables Delivery.
- **Mobile QA on sandbox.** The sandbox payment page cannot be completed and webhooks cannot reach a
  laptop: `php artisan komerce:simulate-payment ORD-...` (local env + sandbox host only) marks an order
  paid through the real `MarkOrderPaidFromKomerce` path. Paid orders need `queue:listen` to reach
  Delivery. Tester checklist and accounts: `mobile/TESTING.md`. Metro without watchman can miss newly
  created files ("Unable to resolve module") — restart with `npx expo start -c`.
- **Payment/shipping (Komerce / RajaOngkir).** Full checkout needs sandbox creds
  (`KOMERCE_PAYMENT_API_KEY`, `KOMERCE_SHIPPING_*_API_KEY`, `KOMERCE_WEBHOOK_SECRET`, RajaOngkir base URLs). Storefront browsing, cart,
  and the admin panel work without them. Stripe stays disabled (`PAYMENT_STRIPE_ENABLED=false`).
