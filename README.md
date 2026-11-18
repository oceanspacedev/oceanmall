# OceanMall

E-commerce storefront starter berbasis [Laravel](https://laravel.com) + [Shopper](https://laravel.shopper.io) Vue Starter Kit.

## Stack

- PHP 8.3+, Laravel 13
- Shopper 2.11
- Inertia.js 3 + Vue 3 + TypeScript
- Tailwind CSS 4 + Vite 8
- Laravel Fortify (auth + 2FA)
- PostgreSQL (Production) / SQLite (Dev)

## Fitur bawaan

- Storefront: home, produk, kategori, koleksi, search
- Cart & zone/shipping
- Checkout (payment method dikonfigurasi di admin Shopper)
- Account: orders & addresses
- Admin panel Shopper di `/cpanel`
- Customer API `/api/v1` (Sanctum Bearer) + aplikasi mobile Expo di `mobile/`

## Requirements

- PHP 8.3+ dengan ekstensi umum Laravel
- Composer 2
- Node.js 20+ / npm
- Lisensi Shopper (paket private) — siapkan `auth.json` Composer di mesin lokal

## Deployment (Production - 500 CCU Ready)

Untuk menampung trafik tinggi (500 CCU+), aplikasi ini dikonfigurasi menggunakan **Laravel Octane (FrankenPHP)**, **PostgreSQL**, dan **Redis**.

Pastikan server (Ubuntu/Debian) sudah terinstall:
- PostgreSQL (`postgresql postgresql-contrib php8.4-pgsql`)
- Redis Server (`redis-server`)
- FrankenPHP / Octane (dari library `laravel/octane` + `predis/predis` yang sudah tersedia di codebase)

### Konfigurasi `.env`
Pastikan environment database, cache, session, dan queue menggunakan PostgreSQL dan Redis:

```env
OCTANE_SERVER=frankenphp
OCTANE_PORT=8087

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=oceanmall
DB_USERNAME=oceanmall
DB_PASSWORD=oceanmall123

SESSION_DRIVER=redis
SESSION_LIFETIME=120
SESSION_ENCRYPT=false

QUEUE_CONNECTION=redis
CACHE_STORE=redis
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
```

### Menjalankan Server
Build frontend assets, kemudian jalankan Laravel Octane:

```bash
# Clear configs
php artisan config:clear
php artisan route:cache

# Build frontend
npm run build

# Start server dengan Octane di port 8087 (di-reverse proxy oleh Nginx)
npm run start
```

Octane akan berjalan di port `8087`. Pastikan Nginx server Anda diatur sebagai _reverse proxy_ yang meneruskan trafik ke `127.0.0.1:8087`.

## Setup Development (Lokal)

```bash
git clone https://github.com/kungfufafa/oceanmall.git
cd oceanmall

composer install
cp .env.example .env
php artisan key:generate

# SQLite (default)
touch database/database.sqlite
php artisan migrate

# Demo catalog storefront (brand, kategori, koleksi, produk + harga/promo)
php artisan db:seed --class=StorefrontDemoSeeder

npm install
npm run build
```

Atau lewat script Composer:

```bash
composer run setup
```

## Development

```bash
composer run dev
```

Menjalankan `artisan serve`, queue listener, log viewer, dan Vite sekaligus.

App: [http://localhost:8000](http://localhost:8000)  
Admin: [http://localhost:8000/cpanel](http://localhost:8000/cpanel)

### Mobile (Expo)

Aplikasi customer iOS/Android ada di `mobile/` (Expo Router + NativeWind + React Native Reusables), memakai `/api/v1`.

```bash
cd mobile
cp .env.example .env
npm install
npm run dev
```

Detail: [`mobile/README.md`](mobile/README.md).

## Environment

Salin dari `.env.example`. Prefix admin bisa diubah lewat `SHOPPER_PREFIX` (default: `cpanel`).

### Payment & shipping: Shopper dulu, provider sebagai modul

Checkout, order, dan cpanel memakai mekanisme Shopper (driver `Payment` / `Shipping`, carrier + shipping option per zone, event order Shopper). Provider pihak ketiga dipasang sebagai **modul** yang hanya menyentuh extension point Shopper. Modul yang ada sekarang: **Komerce** (`app/Integrations/Komerce`) untuk Komerce Payment (VA / QRIS), QRISLY, RajaOngkir Shipping Cost, dan Komerce Shipping Delivery.

- **Driver** Shopper (didaftarkan oleh `KomerceServiceProvider`): `Payment::extend('komerce')`, `Shipping::extend('rajaongkir')`, `Shipping::extend('komerce')`. Polanya sama dengan `shopper/stripe`.
- **Addon** Shopper `komerce`: kartu pengiriman Komerce di detail order cpanel. Addon ini mati sendiri kalau tidak ada key Komerce, dan bisa dimatikan lewat `config/shopper/addons.php` (`'komerce' => false`). Kustomisasi admin yang generik ada di addon `oceanmall-store` (selalu aktif).
- **Satu sumber config**: credential, base URL, dan opsi Komerce hanya ada di config modul (`app/Integrations/Komerce/config/komerce.php`, key `komerce.*`). `config/shopper/{payment,shipping}.php` hanya menyimpan `enabled` untuk tiap driver. Untuk override per key: `php artisan vendor:publish --tag=komerce-config` (menghasilkan `config/komerce.php`).

Stripe **tetap mati** by default.

### Running without Komerce

Semua key Komerce **opsional**. Dengan key kosong (atau `KOMERCE_ENABLED=false`) store berjalan murni dengan Shopper, tanpa error, tanpa UI berlabel vendor, dan tanpa panggilan API keluar:

| Bagian | Tanpa Komerce |
| --- | --- |
| Pembayaran | Payment method `driver=manual` (mis. `cod`). `PaymentMethodSeeder` membuat dan mengaktifkannya di zone. Metode Komerce tetap ada tapi nonaktif. Seeder hanya mengisi `is_enabled` saat membuat metode; seed ulang tidak mengubah pilihan staf. |
| Ongkir | Carrier Shopper + **shipping option per zone** (cpanel → Settings → Zones / Carriers), dihitung `CarrierRateService`. `CarrierSeeder` + `ZoneSeeder` menyiapkan carrier `manual` dengan opsi di zone Indonesia. |
| Alamat checkout | Kota / provinsi / kode pos diketik bebas (web dan mobile). Pencarian kecamatan dan pin point hanya muncul kalau ada driver yang membutuhkannya (`shippingCapabilities` / `shipping_capabilities`). **Build app lama**: build mobile yang belum membaca `shipping_capabilities` selalu mewajibkan kecamatan dari pencarian. Tanpa driver tujuan, `/api/v1/checkout/destinations` mengembalikan baris teks bebas (`AppShippingAddressBookDestinations`): wilayah (kode pos + kota + provinsi, tanpa nama/jalan) yang sudah pernah disimpan pelanggan lain, dicari dari nama daerah atau kode pos, plus teks yang diketik bila berisi kode pos 5 digit (mis. "Cirebon 45153"). Id baris itu (`text:...`) tidak pernah disimpan. Alamat tanpa tujuan dikirim ke build lama dengan id legacy `text:...` (`AddressDestination::withLegacyKeys(freeTextFallback)`) supaya alamat tersimpan bisa dipakai ulang tanpa cari ulang; `destination_id` netral tetap null. Batas yang tersisa: nama daerah yang belum pernah disimpan siapa pun tetap "tidak ditemukan" (pelanggan perlu mengetik kode pos), dan kode pos baru tanpa nama daerah tersimpan dengan kota = kode pos. Solusi penuhnya: jadikan build baru versi minimum. |
| Riwayat kirim | Event yang staf tambahkan di cpanel (Shopper "add shipment event") tampil di timeline pelanggan web dan mobile (`App\Shipping\ShipmentTimeline`), bersama riwayat dari provider kalau ada. |
| Resi (AWB) | Diisi manual di cpanel lewat form label / AWB bawaan Shopper. |
| Jadwal | `shipping:*` dan `orders:expire-unpaid` hanya terjadwal kalau ada driver terkonfigurasi yang mendukungnya (`orders:expire-unpaid` juga selama masih ada order belum bayar dari driver yang dimatikan) (kalau dipanggil manual, keluar SUCCESS dengan satu baris info). `komerce:capabilities` hanya terjadwal kalau ada produk Komerce terkonfigurasi. |
| Webhook | `/webhooks/komerce/*` menjawab `200 {status: disabled}` tanpa memproses apa pun selama key produknya kosong (atau `KOMERCE_ENABLED=false`). Kalau key ada tetapi plan sementara terdeteksi tidak mencakup produk itu, webhook tetap diproses (tidak di-ack lalu dibuang). |

Langkah singkat setup keyless:

1. Biarkan semua `KOMERCE_*` kosong. `php artisan migrate --seed` (atau jalankan `PaymentMethodSeeder`, `CarrierSeeder`, `ZoneSeeder`).
2. Di cpanel cek: payment method **COD** aktif di zone Indonesia, carrier **manual** punya shipping option di zone tersebut.
3. Checkout → pilih ongkir dari shipping option → bayar manual. Admin menandai lunas dan mengisi AWB di detail order.

Untuk melepas modul Komerce sepenuhnya: hapus baris `KomerceServiceProvider::class` di `bootstrap/providers.php` (dan folder `app/Integrations/Komerce`). Kode generik tidak pernah mereferensikan modul (dijaga oleh `tests/Feature/Komerce/KomerceModuleTest.php`).

### Adding a payment/shipping provider

Contoh: Biteship / Shipper.id untuk ongkir, Midtrans / Xendit untuk pembayaran. Semua perubahan ada di modul baru, bukan di checkout / order / cpanel generik:

1. **Folder modul**: `app/Integrations/<Provider>/` dengan service provider sendiri, didaftarkan di `bootstrap/providers.php`. Jadikan `app/Integrations/Komerce/KomerceServiceProvider.php` sebagai contoh.
2. **Driver class**: payment driver implement `Shopper\Payment\Contracts\PaymentDriver`, shipping driver implement `Shopper\Shipping\Contracts\ShippingDriver` (`isConfigured()` wajib jujur: key ada dan siap dipakai).
3. **Kontrak kecil app** (implement hanya yang didukung; kode generik cek `instanceof`):
   - Shipping (`app/Shipping/Contracts`): `ResolvesDestinations` (cari tujuan + origin gudang), `RequiresDestination`, `RequiresOriginId`, `RequiresCoordinates`, `FulfillsShipments` (`fulfillShipment()`), `PrintsLabels`, `CancelsShipments`, `ReconcilesShipments`, `TracksShipments`, `SyncsCarriers`, `DelegatesQuotes`, `DelegatesFulfillment`, `PresentsCarriers`, `NormalizesSelectedRates`.
   - Payment (`app/Payment/Contracts`): `ProvidesPaymentAction` (`paymentAction` web / `payment_action` API: `instructions` / `redirect` / `client_secret` / `qr` / `none`), `ProvidesPaymentCatalog`, `ReconcilesPayments`, `RetriesPayments`, `ExpiresPayments`.
4. **Daftarkan** di service provider modul: `Payment::extend('<code>', ...)` / `Shipping::extend('<code>', ...)`.
5. **Config**: config modul sendiri lewat `mergeConfigFrom()`, plus `'<code>' => ['enabled' => filter_var(env('SHIPPING_<CODE>_ENABLED', true), FILTER_VALIDATE_BOOLEAN)]` di `config/shopper/{payment,shipping}.php`. `enabled` adalah saklar operator dengan aturan yang sama seperti `configuredDrivers()` Shopper (`App\Support\OperatorSwitch`): **entry tanpa `enabled` = mati**, nilai `0` / `off` / `false` = mati. Mati berarti driver keluar dari checkout, fulfilment, tracking walau key ada. Jangan duplikasi credential, dan jangan menimpa `enabled` dengan status plan.
6. **Carrier & metode bayar**: buat carrier dengan `driver=<code>` (atau sinkronkan lewat `SyncsCarriers` → `shipping:sync-carriers`) dan payment method dengan `driver=<code>`. Seed lewat `PaymentMethodSeeder::extend(YourSeeder::class)` / `CarrierSeeder::extend(YourSeeder::class)`; `ZoneSeeder` menempelkan semua carrier dan metode bayar yang aktif ke zone, jadi `zones.json` tidak perlu diubah. Logo: `CarrierLogos::extend()` / `PaymentLogos::extend()`, atau isi `metadata.logo_url` (jangan simpan URL CDN provider di metadata: metadata selalu menang, juga setelah provider dilepas).
7. **Origin gudang**: kalau driver `ResolvesDestinations`, `RequiresOriginId` (butuh origin tapi tanpa pencarian tujuan) atau `ValidatesOriginIds`, form lokasi cpanel otomatis menampilkan field origin untuk driver itu (tabel `inventory_shipping_origins`, `ShippingCapabilities::originDrivers()`); hanya driver `ResolvesDestinations` yang mengisi origin otomatis dari alamat gudang. Format id-nya bebas (string ≤ 100); driver yang butuh aturan lain implement `ValidatesOriginIds` (RajaOngkir: integer ≥ 1).
   - **Kutipan di luar checkout**: Shopper `CarrierRateService` atau kode lain bisa memanggil `Shipping::driver('<code>')->calculateRates()` langsung, tanpa `ShippingQuoteContext` dari checkout. Driver memanggil `$context->beginFromAddress($to, ...)` (lalu `end()`) untuk mengisi konteks dari DTO `Address` Shopper: gudang default sebagai origin, id tujuan dari pencarian kode pos 5 digit yang persis sama, dan kurir milik driver (lihat `RajaOngkirDriver::calculateRates()`). Tanpa kode pos yang cocok hasilnya kosong.
   - Baris hasil `searchDestinations()` minimal `id` + `label`, sebaiknya juga `postal_code`, `city`, `state` (boleh null). Field yang null tetap bisa diketik pelanggan di web dan mobile.
   - **Satu kosakata tujuan utama**: alamat menyimpan satu `destination_id` + `destination_driver` yang dipilih pelanggan; form mencari ke driver `ResolvesDestinations` pertama yang terkonfigurasi. Driver tujuan **kedua** yang aktif bersamaan mendapat id dari pencariannya sendiri untuk kode pos alamat yang persis sama (`ShippingQuoteContext::destinationId()`), jadi keduanya bisa memberi tarif; pencarian memakai kode pos lalu nama kota/label alamat (baris harus tetap ber-kode pos persis sama) dan hasilnya di-cache per driver + wilayah (30 hari kalau cocok, 1 hari kalau tidak), jadi tidak ada panggilan API tambahan per kuotasi; tanpa kode pos yang cocok driver kedua tidak memberi tarif. Id tujuan pilihan pelanggan per driver (map per driver di alamat) **tidak** didukung: semua penulis alamat (web, API, order, alokasi gudang) perlu diubah untuk itu.
   - Secara default ongkir provider dan shipping option zone tidak dicampur. Kalau provider terkonfigurasi tapi tidak memberi tarif (kuota habis, gagal, tujuan di luar jangkauan, origin kosong), checkout menampilkan **tidak ada kurir + penjelasan**, seperti sebelum ada driver. Set `SHIPPING_FALLBACK_TO_ZONE_OPTIONS=true` (`shopper.shipping.fallback_to_zone_options`) kalau toko memilih menawarkan shipping option zone (mis. tarif flat "Reguler") pada kondisi itu. Set `SHIPPING_COMBINE_ZONE_OPTIONS=true` (`shopper.shipping.combine_zone_options`) untuk menawarkan shipping option zone dari carrier yang tidak dimiliki provider terkonfigurasi (mis. "Ambil di toko") **di samping** tarif provider; opsi itu tetap ditawarkan saat provider tidak memberi tarif.
   - `RequiresCoordinates`: driver wajib tetap jalan tanpa pin dari pelanggan. Web checkout menawarkan tombol "Gunakan lokasi saya" (mengisi `pin_point`), app mobile belum mengumpulkan pin. Lengkapi pin di server dengan `App\Shipping\DestinationCoordinateResolver`.
8. **Data shipment netral** (`App\Shipping\ShipmentMetadata`): nomor order provider di kolom `order_shipments.external_reference` (mengunci pindah gudang di cpanel), timeline pelanggan di `metadata.tracking_history`, error registrasi di `metadata.fulfillment_error`, pickup di `metadata.pickup_requested_at`. Data mentah provider boleh di bucket sendiri (`metadata.<driver>`). Kolom `order_shipments.driver` = driver yang memberi tarif; `carrier_id` = carrier Shopper dari tarif itu. Detail tarif tambahan dari `ShippingQuoteContext::describe()` ikut tersimpan di sesi/`metadata.rate` (`App\Shipping\SelectedRate`).
9. **Pembayaran**: `RetriesPayments` melempar `App\Payment\Exceptions\PaymentRetryRefused` (`alreadyPaid()` / `notEligible()`) untuk alasan yang ditampilkan ke pelanggan. Payload `instructions` / `qr` mengikuti bentuk netral di `ProvidesPaymentAction`. Gateway redirect (Midtrans Snap, Xendit invoice) cukup mengembalikan `PaymentResult->redirectUrl`: aksi `redirect` disimpan di transaksi Initiate Shopper (`metadata.payment_action`) sehingga halaman order dan app tetap menampilkan link bayar; implement `ProvidesPaymentAction` kalau aksinya bisa berubah setelah dibuat. Driver yang dibayar di form tertanam **sebelum** order dibuat implement `App\Payment\Contracts\ConfirmsBeforeOrder` (contoh: `App\Payment\Drivers\ConfirmFirstStripeDriver`; return URL generik `/checkout/confirm/{driver}/return`) dan mendaftarkan komponennya di `inPagePaymentForms` (`resources/js/pages/shop/checkout.vue`).
10. **Webhook & admin UI**: route webhook di file route modul (`Route::middleware('web')->group(...)`, URL di bawah `webhooks/*` sudah bebas CSRF). UI cpanel khusus provider lewat addon Shopper milik modul.
11. **Events**: konfirmasi bayar lewat `App\Actions\Orders\MarkOrderPaid` (memicu `Shopper\Core\Events\Orders\OrderPaid` sekali). Listener generik lalu mengirim notifikasi dan mengirim shipment ke driver pemiliknya (`FulfillsShipments`). Lifecycle shipment memicu `OrderShipmentCreated` / `OrderShipped` / `OrderShipmentDelivered` / `OrderCompleted` dari Shopper (sekali per transisi, `SyncOrderShippingFromShipments`); scan tracking provider (`metadata.tracking_history`) juga dicatat sebagai `OrderShippingEvent` Shopper sehingga log event shipment di cpanel menampilkannya. Status dari `track()` selalu dinormalisasi (`NormalizeShipmentStatus`). Pembatalan order memanggil `CancelsShipments`.
12. **Tests**: test driver (unit), test checkout dengan driver aktif dan tanpa key, serta test bahwa modul tidak bocor ke kode generik (lihat `KomerceModuleTest`, `ProviderDriverPipelineTest`, `PaymentActionTest`).

### Upgrading to provider-neutral shipping

Migration rilis ini (`2026_11_18_000100` s.d. `000400`) hanya **expand**: tidak ada kolom yang dihapus atau di-rename, jadi skema lama dan baru bisa dipakai bersamaan selama rolling / blue-green deploy.

| Migration | Yang berubah | Instance rilis lama selama jendela deploy |
| --- | --- | --- |
| `000100` | Origin gudang **disalin** dari `sh_inventories.rajaongkir_origin_id` ke tabel `inventory_shipping_origins` (per driver). Kolom lama **tetap ada**. | Tetap membaca/menulis kolom lama. Kode baru tidak membaca dan tidak menulis kolom itu (hanya atribut model `rajaongkir_origin_id` yang di-set eksplisit yang dipetakan ke tabel, `InventoryObserver`), jadi origin yang diubah di cpanel instance lama selama jendela deploy tidak terlihat oleh kode baru (dan sebaliknya). Cek ulang origin gudang di cpanel setelah deploy kalau ada yang diubah di jendela itu. |
| `000200` | Kolom nullable `order_shipments.driver` + `external_reference` (di-backfill). | Shipment buatan instance lama berisi `driver` null; kode baru memperlakukannya sebagai shipment lama (fulfilment ke satu-satunya driver fulfilment yang terkonfigurasi, `ShippingCapabilities::fulfillerFor()`). |
| `000300` | Key alamat `rajaongkir_*` **disalin** ke key netral (`destination_id`, `destination_label`, `pin_point`, `destination_driver`); key lama tetap. | Membaca key lama seperti biasa. Alamat yang disimpan ulang oleh kode baru hanya berisi key netral (`AddressDestination::normalize()`), jadi instance lama meminta pelanggan memilih kecamatan lagi untuk alamat itu. |
| `000400` | Kolom nullable `order_shipments.carrier_id`; `metadata.logo_url` carrier yang menunjuk CDN Komerce dipindah ke `metadata.provider_logo_url_backup`. | Kosmetik saja: instance lama tidak lagi melihat URL logo itu. |

Urutan deploy: `php artisan migrate` → naikkan instance web baru (boleh bergulir) → **ganti queue worker sekaligus** (hentikan semua worker lama, baru jalankan worker baru). Worker jangan dicampur: worker lama tidak mengenal job rilis baru (mis. `App\Jobs\FulfillOrderShipment`) dan tidak membaca data yang kode baru simpan dengan key / tabel netral. Job yang di-queue rilis lama tetap jalan di worker baru (`App\Jobs\CreateRajaOngkirDeliveryForShipment` di-alias oleh `KomerceServiceProvider::LEGACY_CLASSES`). Deploy stop-the-world (`php artisan down` → hentikan worker → migrate → kode baru → `php artisan up`) tetap aman dan paling sederhana.

Rollback: `php artisan migrate:rollback` menulis origin dari `inventory_shipping_origins` kembali ke `sh_inventories.rajaongkir_origin_id` (tabel menjadi sumber kebenaran; origin yang dikosongkan tetap kosong) lalu menghapus tabel, mengembalikan key alamat lama, URL logo kurir, dan menghapus kolom baru `order_shipments`.

**Rilis berikutnya (contract)** akan menghapus kolom `sh_inventories.rajaongkir_origin_id` (dan migration `2026_08_03_093940` yang menambahkannya bisa dilipat saat squash), setelah tidak ada lagi instance atau job rilis lama. Jangan menulis kode baru yang membaca kolom itu.

### Komerce module: environment

| Variable | Keterangan |
| --- | --- |
| `KOMERCE_SHIPPING_COST_API_KEY` | Key **Shipping Cost** dari [collaborator settings](https://collaborator.komerce.id/settings) |
| `KOMERCE_SHIPPING_DELIVERY_API_KEY` | Key **Shipping Delivery** |
| `KOMERCE_PAYMENT_API_KEY` | Key **Payment API** (VA / bank transfer) |
| `KOMERCE_QRISLY_API_KEY` | Key **QRISLY API**, **opsional**. Kosong = QRIS lewat Payment API |
| `KOMERCE_QRISLY_QRIS_ID` | ID template QRIS dari upload di Collaborator (wajib kalau QRISLY aktif) |
| `KOMERCE_QRISLY_BASE_URL` | Default = `KOMERCE_PAYMENT_BASE_URL` |
| `KOMERCE_QRISLY_UNIQUE_AMOUNT` | Default `true` (generate-qris) |
| `KOMERCE_ENABLED` | Kosong = deteksi otomatis per produk. `false` = matikan seluruh modul walau key ada |
| `KOMERCE_PAYMENT_BASE_URL` | Di luar production default sandbox `https://api-sandbox.collaborator.komerce.id/user`. **Production wajib diisi**: `https://api.collaborator.komerce.id/user` |
| `RAJAONGKIR_DELIVERY_BASE_URL` | Di luar production default sandbox `https://api-sandbox.collaborator.komerce.id`. **Production wajib diisi**: `https://api.collaborator.komerce.id` |
| `RAJAONGKIR_COST_BASE_URL` | Default `https://rajaongkir.komerce.id` (satu host) |
| `RAJAONGKIR_COURIERS` | Override manual daftar kurir. **Default kosong**: daftar kurir diambil dari provider lewat `shipping:sync-carriers` |
| `KOMERCE_WEBHOOK_SECRET` | Secret buatan sendiri untuk **Payment callback**; dikirim sebagai `callback_API_KEY` dan diverifikasi melalui HMAC-SHA256 raw body |
| `KOMERCE_PICKUP_VEHICLE` | Kendaraan pickup (default: `Motor`) |
| `KOMERCE_PICKUP_TIME` | Jam pickup format `HH:mm:ss` (default: `10:00:00`) |
| `KOMERCE_TIMEOUT` | Timeout HTTP client (detik) |
| `PAYMENT_KOMERCE_ENABLED` | Saklar operator driver payment `komerce` (default `true`) |
| `PAYMENT_STRIPE_ENABLED` | **Harus `false`** untuk production OceanMall v1 |

Base URL di `.env.example` sengaja dikomentari: dengan `APP_ENV=production` dan base URL kosong, client Komerce gagal dengan jelas (`KomerceNotConfiguredException`), bukan diam-diam mengirim checkout live ke sandbox.

Webhook URL (expose ke Komerce sandbox/production):

```
POST {APP_URL}/webhooks/komerce/payment
POST {APP_URL}/webhooks/komerce/delivery
POST {APP_URL}/webhooks/komerce/qrisly
```

Hanya callback **Payment API** yang secara resmi mendefinisikan header `X-Callback-Api-Key` berisi HMAC-SHA256 raw body dengan `KOMERCE_WEBHOOK_SECRET`. Dokumentasi Delivery dan QRISLY tidak mendefinisikan signature; handler memperlakukan payload mereka sebagai sinyal lalu memeriksa status melalui API provider yang memakai dedicated key sebelum mengubah order.

### Production ops (wajib jalan)

Provider otomatis (Komerce) bergantung pada background jobs. Tanpa ini AWB/tracking/expire unpaid tidak jalan:

| Proses | Cara |
| --- | --- |
| Queue worker | `php artisan queue:work` (atau `composer run dev`) |
| Scheduler | Cron tiap menit: `* * * * * php artisan schedule:run` (dev: `schedule:work`) |

Command generik (jadwal di `routes/console.php`; masing-masing hanya terjadwal kalau ada driver terkonfigurasi yang mendukung kemampuannya). Nama lama `komerce:*` tetap jalan sebagai alias:

| Command | Alias lama | Jadwal | Fungsi |
| --- | --- | --- | --- |
| `shipping:fulfill-paid` | `komerce:fulfill-paid-orders` | 5 menit | Kirim shipment order lunas yang belum berlabel ke driver `FulfillsShipments` |
| `shipping:refresh-tracking` | `komerce:refresh-shipment-tracking` | per jam | Tarik status tracking shipment yang masih jalan |
| `shipping:sync-carriers` | `komerce:sync-carriers` | 00:15 | Driver `SyncsCarriers` menyalakan kurir yang ditawarkan provider (hanya carrier miliknya) |
| `orders:expire-unpaid` | `komerce:expire-unpaid-orders` | 15 menit | Batalkan order belum bayar yang sudah lewat batas driver `ExpiresPayments` + lepas stok. Driver aktif (switch `shopper.payment.drivers.{code}.enabled` + key) mengecek provider dulu. Driver yang dimatikan / key-nya dihapus: order-nya dibatalkan lokal (tanpa panggilan provider) 24 jam setelah batas bayar tersimpan (`ExpireUnpaidOrders::LOCAL_GRACE_HOURS`). |

Command khusus modul Komerce (didaftarkan `KomerceServiceProvider`):

| Command | Fungsi |
| --- | --- |
| `komerce:capabilities` | Probe plan Collaborator (starter / pro / komship) dan produk yang aktif. Terjadwal 00:05 hanya kalau ada produk Komerce terkonfigurasi |
| `komerce:uat` | Laporan kesiapan Komerce (key, plan, webhook, jadwal) |
| `komerce:simulate-payment ORD-...` | Tandai order lunas lewat jalur webhook asli; hanya env lokal + host sandbox |

Origin gudang: hanya dibutuhkan kalau ada driver yang memakai tujuan provider (mis. RajaOngkir Cost dengan key). Isi di cpanel → Settings → Locations (tersimpan di `inventory_shipping_origins` per driver). Tanpa key, checkout memakai shipping option zone dan tidak butuh origin.

Di Collaborator → Developer → Webhook:

- **Webhook Payment** — log callback; URL payment dikirim per-order via `callback_url` (tidak perlu set di form itu).
- **Webhook Shipping Delivery** → **Add Webhook URL** → isi `{APP_URL}/webhooks/komerce/delivery`.
- **Webhook QRISLY** → register `{APP_URL}/webhooks/komerce/qrisly` hanya jika QRISLY aktif (`KOMERCE_QRISLY_API_KEY` + `KOMERCE_QRISLY_QRIS_ID`). Kalau kosong, biarkan — checkout QRIS tetap lewat Payment API.

Jangan commit `.env` atau API key asli.

### Sandbox checklist (manual)

1. Set API key yang dibutuhkan dari collaborator settings + `KOMERCE_WEBHOOK_SECRET`, biarkan `PAYMENT_STRIPE_ENABLED=false` dan base URL tetap dikomentari (default sandbox).
2. `php artisan migrate`, lalu `php artisan komerce:capabilities` untuk melihat produk yang aktif di plan.
3. Di admin Shopper (`/cpanel`): buat / set **Inventory default** (mis. Gudang Jakarta) dan isi **origin RajaOngkir** di form lokasi (ID dari destination search; tersimpan di `inventory_shipping_origins`).
4. Jalankan `php artisan db:seed --class=PaymentMethodSeeder` (metode Komerce yang baru dibuat aktif otomatis kalau key Payment ada; metode yang sudah ada tidak diubah status aktifnya oleh seeder, nyalakan di cpanel), atau buat Payment Method `driver=komerce` (metadata `payment_type` = `bank_transfer` + `channel_code` bank, atau `qris`) dan aktifkan di zone Indonesia. `php artisan shipping:sync-carriers` menyalakan kurir yang ditawarkan provider.
5. Jalankan queue worker (`composer run dev` sudah include) + scheduler (`php artisan schedule:work`) untuk AWB create, tracking poll, dan expire unpaid.
6. Di storefront checkout: isi alamat + **cari district** (RajaOngkir destination) → pilih kurir → pilih **QRIS** (atau VA) → Place order.
7. Salin instruksi bayar (nomor VA / QRIS). Response Payment API memakai field `va_number` / `qr_string` / `expired_at` / `payment_url` — storefront sudah memetakan ke panel VA/QRIS.
8. Order harus beralih ke `payment_status=paid`; job membuat AWB per shipment.
9. Admin ops: buka `/cpanel/orders/{id}/detail` — panel **RajaOngkir / Komerce shipping** (cetak label / pindah gudang sebelum AWB).
10. Customer di `/account/orders/{id}`: Track package → status dinormalisasi; atau **Mark as received** untuk menutup order.
11. UAT otomatis hulu→hilir (QRIS): `php scripts/live-customer-qris-e2e-uat.php` — lihat juga `docs/superpowers/specs/2026-08-04-customer-storefront-walkthrough.md`.
12. UAT gudang (paid → AWB → cetak label → webhook delivered): `php scripts/live-warehouse-ops-e2e-uat.php` — lihat `docs/superpowers/specs/2026-08-04-warehouse-ops-walkthrough.md`. Ops UI: `/cpanel/orders/{id}/detail` (Shopper only).

Catatan QRISLY: tanpa `KOMERCE_QRISLY_QRIS_ID`, QRISLY dimatikan otomatis dan QRIS tetap lewat Payment API.

### Staging happy path (beli → diterima)

| Peran | Langkah | Endpoint / UI |
| --- | --- | --- |
| Customer | Cari district → checkout → bayar | `/checkout` |
| System | Webhook paid → create AWB | `POST /webhooks/komerce/payment` + queue |
| Admin | Print label / override | `/cpanel/orders/{id}/detail` (panel RajaOngkir) |
| Customer | Track / mark received | `/account/orders/{id}` |
| System | Expire unpaid + release stock | `orders:expire-unpaid` (setiap 15 mnt) |
| System | Poll tracking | `shipping:refresh-tracking` (hourly) |

Scheduler entries ada di `routes/console.php`. Di staging/production pastikan cron memanggil `php artisan schedule:run` tiap menit (atau `schedule:work` di dev).

### Stripe

Stripe **dimatikan by default** (`PAYMENT_STRIPE_ENABLED=false`). Jangan set key Stripe kecuali memang mau dipakai untuk eksperimen; Stripe hanya ditawarkan kalau saklarnya aktif **dan** `STRIPE_SECRET_KEY` terisi.

## Scripts berguna

| Command | Keterangan |
| --- | --- |
| `composer run setup` | Install deps, key, migrate, build assets |
| `composer run dev` | Dev server full stack |
| `composer test` | Jalankan PHPUnit |
| `npm run lint` | ESLint (auto-fix) |
| `npm run format` | Prettier di `resources/` |
| `npm run types:check` | Cek tipe Vue/TS |

## Struktur singkat

```
app/Http/Controllers/Shop/   # Storefront controllers
app/Actions/                 # Cart, checkout, order, shipping actions (provider-neutral)
app/Payment/ app/Shipping/   # Kontrak driver + ShippingCapabilities / PaymentActions
app/Addons/Store/            # Addon cpanel generik 'oceanmall-store'
app/Integrations/Komerce/    # Modul Komerce: drivers, clients, webhooks, addon, commands, config, views
resources/js/pages/shop/     # Vue pages (Inertia)
config/shopper/              # Konfigurasi Shopper
routes/web.php               # Storefront + checkout routes
```

## Catatan

- Jangan commit `.env` atau `auth.json`.
- Kit sumber: `shopperlabs/vue-starter-kit` (lihat `.shopper-kit`).
- Dokumentasi Shopper: https://laravel.shopper.io

## License

MIT (kode aplikasi). Paket Shopper mengikuti lisensi masing-masing dari Shopper Labs.
