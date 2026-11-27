<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Integrations\Komerce\KomerceServiceProvider;
use App\Livewire\Shopper\OrderCustomer;
use App\Models\OrderShipment;
use App\Models\Product;
use App\Models\User;
use App\Shipping\ShippingCapabilities;
use Database\Seeders\CarrierSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\StoreConfigSeeder;
use Database\Seeders\ZoneSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Shopper\Cart\Models\Cart;
use Shopper\Cart\Models\CartLine;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Enum\ShippingStatus;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\CarrierOption;
use Shopper\Core\Models\Country;
use Shopper\Core\Models\Currency;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Core\Models\Price;
use Shopper\Core\Models\Zone;
use Shopper\Payment\Facades\Payment;
use Shopper\Shipping\Facades\Shipping;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The claim in bootstrap/providers.php, tested: with the KomerceServiceProvider
 * line removed the app boots and checks out on Shopper alone (web, mobile API,
 * cpanel, seeders), even with rows the module left behind (carriers with
 * driver=rajaongkir, payment methods with driver=komerce, shipments with
 * driver=komerce).
 */
final class StoreWithoutKomerceModuleTest extends TestCase
{
    use RefreshDatabase;

    private ?string $providersFile = null;

    public function createApplication(): Application
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';

        // bootstrap/providers.php minus every provider module line
        // (app/Integrations/*: Komerce, Xendit, Midtrans, Biteship, KiriminAja).
        $providers = array_values(array_filter(
            require $app->getBootstrapProvidersPath(),
            static fn (string $provider): bool => ! str_starts_with($provider, 'App\\Integrations\\'),
        ));
        $this->providersFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'oceanmall-providers-'.bin2hex(random_bytes(6)).'.php';
        file_put_contents($this->providersFile, '<?php return '.var_export($providers, true).';');
        RegisterProviders::merge([], $this->providersFile);

        // Process-wide registrations an earlier test's app made through the
        // module: a fresh process without the module never has them.
        CarrierSeeder::flushState();
        PaymentMethodSeeder::flushState();
        OrderCustomer::flushShippingAddressCallbacks();

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if ($this->providersFile !== null && file_exists($this->providersFile)) {
            unlink($this->providersFile);
        }
    }

    public function test_the_module_is_really_gone(): void
    {
        $this->assertSame([], $this->app->getProviders(KomerceServiceProvider::class));
        $this->assertNotContains('rajaongkir', Shipping::availableDrivers());
        $this->assertNotContains('komerce', Shipping::availableDrivers());
        $this->assertNotContains('komerce', Payment::availableDrivers());
        $this->assertFalse(Payment::isConfigured('komerce'));
        $this->assertTrue(resolve(ShippingCapabilities::class)->drivers()->isEmpty());

        foreach (['biteship', 'kiriminaja'] as $code) {
            $this->assertNotContains($code, Shipping::availableDrivers());
        }

        foreach (['xendit', 'midtrans'] as $code) {
            $this->assertNotContains($code, Payment::availableDrivers());
        }

        $this->post('/webhooks/komerce/payment', [])->assertNotFound();
    }

    public function test_web_and_mobile_checkout_work_with_leftover_provider_rows(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        [$zone, $option, $cod, $inventory] = $this->store();

        // Rows the module created before it was removed.
        $jne = Carrier::query()->create(['name' => 'JNE', 'slug' => 'jne', 'driver' => 'rajaongkir', 'is_enabled' => true]);
        $zone->carriers()->attach($jne->id);
        $va = PaymentMethod::factory()->create(['title' => 'BCA VA', 'slug' => 'komerce-bca', 'driver' => 'komerce', 'is_enabled' => true]);
        $zone->paymentMethods()->attach($va->id);

        $user = User::factory()->create();
        $product = $this->product($inventory);
        $cart = Cart::query()->create(['currency_code' => 'IDR', 'customer_id' => $user->id]);
        CartLine::query()->create([
            'cart_id' => $cart->id,
            'purchasable_type' => $product->getMorphClass(),
            'purchasable_id' => $product->id,
            'quantity' => 1,
            'unit_price_amount' => 50000,
        ]);

        $manifest = public_path('build/manifest.json');
        $this->withHeader('X-Inertia-Version', file_exists($manifest) ? hash_file('xxh128', $manifest) : '');
        $this->actingAs($user)->withSession([
            config('shopper.cart.session.key', 'shopper_cart') => $cart->id,
            'zone_country_code' => 'ID',
        ]);

        $this->post(route('shop.checkout.shipping-address'), $this->address())->assertSessionHasNoErrors();

        $this->get(route('shop.checkout.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('shippingCapabilities.destinationSearch', false)
                ->has('deliveryOptions', 1)
                ->where('deliveryOptions.0.driver', 'manual')
                ->has('paymentOptions', 1)
                ->where('paymentOptions.0.id', $cod->id));

        $this->post(route('shop.checkout.shipping-option'), ['service_code' => (string) $option->id])->assertSessionHasNoErrors();
        $this->post(route('shop.checkout.place-order'), ['payment_method_id' => $cod->id])->assertSessionHasNoErrors();

        $order = Order::query()->sole();
        $this->assertSame(50000 + 15000, (int) $order->price_amount);
        $this->get(route('shop.checkout.success', ['order' => $order->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('paymentAction.kind', 'none'));

        // Mobile API.
        $mobileUser = User::factory()->create();
        Sanctum::actingAs($mobileUser);
        $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1])->assertOk();
        $this->getJson('/api/v1/checkout')
            ->assertOk()
            ->assertJsonPath('data.shipping_capabilities.requires_destination', false);
        // No provider search: only the region the web customer above already saved.
        $this->getJson('/api/v1/checkout/destinations?q=cirebon')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.zip_code', '45153')
            ->assertJsonPath('data.0.city_name', 'Cirebon');
        $this->postJson('/api/v1/checkout/shipping-address', $this->address())
            ->assertOk()
            ->assertJsonCount(1, 'data.shipping_rates')
            ->assertJsonPath('data.shipping_rates.0.driver', 'manual')
            ->assertJsonCount(1, 'data.payment_methods');
        $this->postJson('/api/v1/checkout/shipping-option', ['service_code' => (string) $option->id])->assertOk();
        $this->postJson('/api/v1/checkout/place-order', ['payment_method_id' => $cod->id])
            ->assertCreated()
            ->assertJsonPath('data.payment_action.kind', 'none');
        $this->postJson('/api/v1/checkout/place-order', ['payment_method_id' => $va->id])->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_cpanel_order_pages_render_for_orders_left_by_the_module(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        [, , , $inventory] = $this->store();
        $va = PaymentMethod::factory()->create(['title' => 'BCA VA', 'slug' => 'komerce-bca', 'driver' => 'komerce', 'is_enabled' => true]);
        $order = Order::factory()->create([
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
            'payment_method_id' => $va->id,
        ]);
        OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inventory->id,
            'driver' => 'komerce',
            'external_reference' => 'KOM-OLD-1',
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'awb' => 'JNE-OLD-1',
            'status' => 'in_transit',
            'cost' => 12000,
            'currency_code' => 'IDR',
        ]);

        $this->configureShopperCpanel();
        $admin = User::factory()->create();
        Role::query()->firstOrCreate(['name' => config('shopper.admin.roles.admin'), 'guard_name' => 'web']);
        $admin->assignRole(config('shopper.admin.roles.admin'));

        $this->actingAs($admin)
            ->get(route('shopper.orders.detail', $order))
            ->assertOk()
            ->assertDontSee('Pengiriman RajaOngkir / Komerce', false);

        $this->artisan('shipping:fulfill-paid')->assertSuccessful();
        $this->artisan('shipping:refresh-tracking')->assertSuccessful();
        $this->artisan('shipping:sync-carriers')->assertSuccessful();
        $this->artisan('orders:expire-unpaid')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_seeders_run_without_the_module(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        Currency::query()->where('code', 'IDR')->first()
            ?? Currency::factory()->create(['code' => 'IDR', 'name' => 'Rupiah', 'symbol' => 'Rp', 'format' => 'Rp1.234']);
        Country::factory()->create(['cca2' => 'ID', 'cca3' => 'IDN', 'name' => 'Indonesia']);

        $this->seed([PaymentMethodSeeder::class, CarrierSeeder::class, ZoneSeeder::class]);

        $this->assertSame(0, Carrier::query()->where('driver', 'rajaongkir')->count());
        $this->assertSame(0, PaymentMethod::query()->where('driver', 'komerce')->count());
        $this->assertTrue(PaymentMethod::query()->where('slug', 'cod')->where('is_enabled', true)->exists());
        Http::assertNothingSent();
    }

    public function test_store_seeder_writes_no_provider_origin_without_the_module(): void
    {
        // Production skips the demo accounts; the store settings and the
        // default warehouse are seeded either way.
        $this->app->detectEnvironment(static fn (): string => 'production');
        Country::factory()->create(['cca2' => 'ID', 'cca3' => 'IDN', 'name' => 'Indonesia']);

        $this->artisan('db:seed', ['--class' => StoreConfigSeeder::class, '--force' => true])->assertSuccessful();

        $this->assertTrue(Inventory::query()->where('code', 'oceanmall-cirebon')->where('is_default', true)->exists());
        $this->assertSame(0, DB::table('inventory_shipping_origins')->count());
    }

    /**
     * @return array{0: Zone, 1: CarrierOption, 2: PaymentMethod, 3: Inventory}
     */
    private function store(): array
    {
        $currency = Currency::query()->where('code', 'IDR')->first()
            ?? Currency::factory()->create(['code' => 'IDR', 'name' => 'Rupiah', 'symbol' => 'Rp', 'format' => 'Rp1.234']);
        $country = Country::factory()->create(['cca2' => 'ID']);
        $zone = Zone::factory()->create(['is_enabled' => true, 'currency_id' => $currency->id]);
        $zone->countries()->attach($country->id);

        $manual = Carrier::query()->create(['name' => 'Kurir Toko', 'slug' => 'manual', 'driver' => 'manual', 'is_enabled' => true]);
        $zone->carriers()->attach($manual->id);
        $option = CarrierOption::query()->create([
            'carrier_id' => $manual->id,
            'zone_id' => $zone->id,
            'name' => 'Reguler',
            'price' => 15000,
            'is_enabled' => true,
        ]);

        $cod = PaymentMethod::factory()->create(['title' => 'COD', 'slug' => 'cod', 'driver' => 'manual', 'is_enabled' => true]);
        $zone->paymentMethods()->attach($cod->id);

        $inventory = Inventory::factory()->create(['is_default' => true, 'country_id' => $country->id]);

        return [$zone, $option, $cod, $inventory];
    }

    private function product(Inventory $inventory): Product
    {
        $product = Product::factory()->standard()->create(['published_at' => now()->subDay(), 'weight_value' => 0.5, 'weight_unit' => 'kg']);
        $product->mutateStock($inventory->id, 10);
        $currency = Currency::query()->where('code', shopper_currency())->first()
            ?? Currency::factory()->create(['code' => shopper_currency(), 'name' => shopper_currency(), 'symbol' => shopper_currency(), 'format' => '1,234.56']);
        Price::query()->create([
            'priceable_type' => $product->getMorphClass(),
            'priceable_id' => $product->id,
            'amount' => 50000,
            'currency_id' => $currency->id,
        ]);

        return $product;
    }

    /**
     * @return array<string, string>
     */
    private function address(): array
    {
        return [
            'first_name' => 'Budi',
            'last_name' => 'Santoso',
            'street_address' => 'Jl. Merdeka 1',
            'postal_code' => '45153',
            'city' => 'Cirebon',
            'state' => 'Jawa Barat',
            'phone_number' => '081234567890',
        ];
    }
}
