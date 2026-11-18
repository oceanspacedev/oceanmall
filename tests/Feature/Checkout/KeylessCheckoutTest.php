<?php

declare(strict_types=1);

namespace Tests\Feature\Checkout;

use App\Livewire\Shopper\SlideOvers\CreateShippingLabel;
use App\Models\OrderShipment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Shopper\Cart\Models\Cart;
use Shopper\Cart\Models\CartLine;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\CarrierOption;
use Shopper\Core\Models\Country;
use Shopper\Core\Models\Currency;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\OrderShipping;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Core\Models\Price;
use Shopper\Core\Models\Zone;
use Shopper\Payment\Enum\TransactionType;
use Shopper\Payment\Models\PaymentTransaction;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Goal (a): with every Komerce / RajaOngkir / QRISLY key blank (TestCase
 * blanks them) the store runs on Shopper alone — a zone CarrierOption, a
 * Shopper `manual` payment method, a free-text address, cpanel manual AWB —
 * and makes no external HTTP call at all (no provider, no geocoder).
 */
final class KeylessCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Zone $zone;

    private CarrierOption $option;

    private PaymentMethod $payment;

    private Inventory $inventory;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake();

        $manifest = public_path('build/manifest.json');
        $this->withHeader(
            'X-Inertia-Version',
            file_exists($manifest) ? hash_file('xxh128', $manifest) : '',
        );

        $currency = Currency::query()->where('code', 'IDR')->first()
            ?? Currency::factory()->create(['code' => 'IDR', 'name' => 'Rupiah', 'symbol' => 'Rp', 'format' => 'Rp1.234']);
        $country = Country::factory()->create(['cca2' => 'ID']);

        $this->zone = Zone::factory()->create(['is_enabled' => true, 'currency_id' => $currency->id]);
        $this->zone->countries()->attach($country->id);

        $carrier = Carrier::query()->create([
            'name' => 'Kurir Toko',
            'slug' => 'manual',
            'driver' => 'manual',
            'is_enabled' => true,
        ]);
        $this->zone->carriers()->attach($carrier->id);

        $this->option = CarrierOption::query()->create([
            'carrier_id' => $carrier->id,
            'zone_id' => $this->zone->id,
            'name' => 'Reguler',
            'description' => 'Pengiriman reguler 3-5 hari kerja.',
            'price' => 15000,
            'is_enabled' => true,
        ]);

        $this->payment = PaymentMethod::factory()->create([
            'title' => 'Bayar di tempat (COD)',
            'slug' => 'cod',
            'driver' => 'manual',
            'is_enabled' => true,
        ]);
        $this->zone->paymentMethods()->attach($this->payment->id);

        $this->inventory = Inventory::factory()->create(['is_default' => true, 'country_id' => $country->id]);
    }

    public function test_web_checkout_runs_on_shopper_alone_without_provider_keys(): void
    {
        $user = User::factory()->create();
        $product = $this->stockedProduct();
        $cart = Cart::query()->create(['currency_code' => 'IDR', 'customer_id' => $user->id]);
        CartLine::query()->create([
            'cart_id' => $cart->id,
            'purchasable_type' => $product->getMorphClass(),
            'purchasable_id' => $product->id,
            'quantity' => 1,
            'unit_price_amount' => 50000,
        ]);

        $this->actingAs($user)->withSession([
            config('shopper.cart.session.key', 'shopper_cart') => $cart->id,
            'zone_country_code' => 'ID',
        ]);

        // Step 1: no destination picker, free-text province / city / postal code.
        $this->get(route('shop.checkout.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('shop/checkout')
                ->where('shippingCapabilities', [
                    'destinationSearch' => false,
                    'requiresDestination' => false,
                    'requiresCoordinates' => false,
                ])
            );

        $this->post(route('shop.checkout.shipping-address'), [
            'first_name' => 'Budi',
            'last_name' => 'Santoso',
            'street_address' => 'Jl. Merdeka 1',
            'postal_code' => '45153',
            'city' => 'Cirebon',
            'state' => 'Jawa Barat',
            'phone_number' => '081234567890',
        ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('shop.checkout.index'));

        // Step 2: the zone's manual CarrierOption is quoted by Shopper's CarrierRateService.
        $this->get(route('shop.checkout.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('step', 2)
                ->where('shippingRatesHint', null)
                ->has('deliveryOptions', 1)
                ->where('deliveryOptions.0.service_code', $this->option->id)
                ->where('deliveryOptions.0.service_name', 'Reguler')
                ->where('deliveryOptions.0.amount', 15000)
                ->where('deliveryOptions.0.driver', 'manual')
                ->where('paymentOptions.0.id', $this->payment->id)
            );

        $this->post(route('shop.checkout.shipping-option'), ['service_code' => (string) $this->option->id])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('shop.checkout.index'));

        // Step 3: Shopper manual payment places the order.
        $response = $this->post(route('shop.checkout.place-order'), ['payment_method_id' => $this->payment->id]);
        $response->assertSessionHasNoErrors();

        $order = Order::query()->latest('id')->firstOrFail();
        $response->assertRedirect(route('shop.checkout.success', ['order' => $order->id]));

        $this->assertSame(50000 + 15000, (int) $order->price_amount);
        $this->assertSame($this->option->id, (int) $order->shipping_option_id);
        $this->assertSame($this->payment->id, (int) $order->payment_method_id);

        $metadata = json_decode((string) $order->getRawOriginal('metadata'), true);
        $this->assertSame('Cirebon', data_get($metadata, 'shipping_address.city'));
        $this->assertSame('45153', data_get($metadata, 'shipping_address.postal_code'));
        $this->assertNull(data_get($metadata, 'shipping_address.destination_id'));
        // No geocoding without a driver that needs coordinates.
        $this->assertNull(data_get($metadata, 'shipping_address.pin_point'));

        $shipment = OrderShipment::query()->where('order_id', $order->id)->sole();
        $this->assertSame('manual', $shipment->driver);
        $this->assertSame(15000, (int) $shipment->cost);

        // Payment started through Shopper's PaymentProcessingService (manual driver).
        $this->assertDatabaseHas((new PaymentTransaction)->getTable(), [
            'order_id' => $order->id,
            'driver' => 'manual',
            'type' => TransactionType::Initiate->value,
        ]);
        $this->assertNull(data_get($metadata, 'komerce'), 'No provider bucket on a pure Shopper order.');

        // Success page: nothing to pay online, no "payment not ready" state.
        $this->get(route('shop.checkout.success', ['order' => $order->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('shop/checkout-success')
                ->where('paymentAction.driver', 'manual')
                ->where('paymentAction.kind', 'none')
                ->missing('komercePayment')
            );

        // cpanel: Shopper's own manual AWB entry still works.
        $this->actingAs($this->admin());

        Livewire::test(CreateShippingLabel::class, ['order' => $order])
            ->set('data.tracking_number', 'AWB-MANUAL-001')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(
            'AWB-MANUAL-001',
            OrderShipping::query()->where('order_id', $order->id)->value('tracking_number'),
        );

        Http::assertNothingSent();
    }

    public function test_mobile_api_checkout_runs_on_shopper_alone_without_provider_keys(): void
    {
        $user = User::factory()->create();
        $product = $this->stockedProduct();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1])->assertOk();

        // The app reads the capabilities before it shows the address form.
        $this->getJson('/api/v1/checkout')
            ->assertOk()
            ->assertJsonPath('data.shipping_capabilities', [
                'destination_search' => false,
                'requires_destination' => false,
                'requires_coordinates' => false,
            ]);

        // Older app builds still search; they get an empty list and no provider call.
        $this->getJson('/api/v1/checkout/destinations?q=cirebon')
            ->assertOk()
            ->assertExactJson(['data' => []]);

        $this->postJson('/api/v1/checkout/shipping-address', [
            'first_name' => 'Budi',
            'last_name' => 'Santoso',
            'street_address' => 'Jl. Merdeka 1',
            'postal_code' => '45153',
            'city' => 'Cirebon',
            'state' => 'Jawa Barat',
            'phone_number' => '081234567890',
        ])
            ->assertOk()
            ->assertJsonPath('data.shipping_address.city', 'Cirebon')
            ->assertJsonPath('data.shipping_address.destination_id', null)
            ->assertJsonPath('data.shipping_rates.0.service_code', $this->option->id)
            ->assertJsonPath('data.shipping_rates.0.amount', 15000)
            ->assertJsonPath('data.shipping_rates.0.driver', 'manual')
            ->assertJsonPath('data.payment_methods.0.id', $this->payment->id);

        $this->postJson('/api/v1/checkout/shipping-option', ['service_code' => (string) $this->option->id])
            ->assertOk()
            ->assertJsonPath('data.shipping_option.service_code', $this->option->id);

        $this->postJson('/api/v1/checkout/place-order', ['payment_method_id' => $this->payment->id])
            ->assertCreated()
            ->assertJsonPath('data.payment_action.driver', 'manual')
            ->assertJsonPath('data.payment_action.kind', 'none')
            ->assertJsonPath('data.payment', null)
            ->assertJsonPath('data.payment_error', null)
            ->assertJsonPath('data.can_retry_payment', false);

        $order = Order::query()->latest('id')->firstOrFail();
        // Same shared payment step as the web: Shopper records the Initiate transaction.
        $this->assertDatabaseHas((new PaymentTransaction)->getTable(), [
            'order_id' => $order->id,
            'driver' => 'manual',
            'type' => TransactionType::Initiate->value,
        ]);
        $this->assertSame(399000 + 15000, (int) $order->price_amount);
        $this->assertSame('manual', OrderShipment::query()->where('order_id', $order->id)->value('driver'));

        Http::assertNothingSent();
    }

    public function test_api_still_accepts_legacy_destination_keys_without_requiring_them(): void
    {
        $user = User::factory()->create();
        $product = $this->stockedProduct();
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1])->assertOk();

        // An app build already in the stores sends rajaongkir_* keys.
        $this->postJson('/api/v1/checkout/shipping-address', [
            'first_name' => 'Budi',
            'last_name' => 'Santoso',
            'street_address' => 'Jl. Merdeka 1',
            'postal_code' => '45153',
            'city' => 'Cirebon',
            'phone_number' => '081234567890',
            'rajaongkir_destination_id' => '17547',
            'rajaongkir_destination_label' => 'KEDAWUNG, CIREBON',
        ])
            ->assertOk()
            ->assertJsonPath('data.shipping_address.destination_id', '17547')
            ->assertJsonPath('data.shipping_address.rajaongkir_destination_id', '17547')
            ->assertJsonPath('data.shipping_rates.0.driver', 'manual');

        Http::assertNothingSent();
    }

    public function test_no_rate_hint_is_neutral_without_provider_keys(): void
    {
        $this->option->update(['is_enabled' => false]);

        $user = User::factory()->create();
        $product = $this->stockedProduct();
        $cart = Cart::query()->create(['currency_code' => 'IDR', 'customer_id' => $user->id]);
        CartLine::query()->create([
            'cart_id' => $cart->id,
            'purchasable_type' => $product->getMorphClass(),
            'purchasable_id' => $product->id,
            'quantity' => 1,
            'unit_price_amount' => 50000,
        ]);

        $this->actingAs($user)->withSession([
            config('shopper.cart.session.key', 'shopper_cart') => $cart->id,
            'zone_country_code' => 'ID',
        ]);

        $this->post(route('shop.checkout.shipping-address'), [
            'first_name' => 'Budi',
            'last_name' => 'Santoso',
            'street_address' => 'Jl. Merdeka 1',
            'postal_code' => '45153',
            'city' => 'Cirebon',
            'phone_number' => '081234567890',
        ])->assertSessionHasNoErrors();

        $this->get(route('shop.checkout.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('deliveryOptions', 0)
                ->where('shippingRatesHint', fn (?string $hint): bool => is_string($hint)
                    && str_contains($hint, 'Belum ada opsi pengiriman')
                    && ! str_contains($hint, 'Komerce')
                    && ! str_contains($hint, 'RajaOngkir')
                    && ! str_contains($hint, '.env'))
            );

        Http::assertNothingSent();
    }

    private function admin(): User
    {
        $this->configureShopperCpanel();

        $admin = User::factory()->create();
        Role::query()->firstOrCreate(['name' => config('shopper.admin.roles.admin'), 'guard_name' => 'web']);
        $admin->assignRole(config('shopper.admin.roles.admin'));

        return $admin;
    }

    private function stockedProduct(): Product
    {
        $product = Product::factory()->standard()->create([
            'published_at' => now()->subDay(),
            'name' => 'Keyless Product',
            'weight_value' => 0.5,
            'weight_unit' => 'kg',
        ]);
        $product->mutateStock($this->inventory->id, 10);

        $currency = Currency::query()->where('code', shopper_currency())->first()
            ?? Currency::factory()->create([
                'code' => shopper_currency(),
                'name' => shopper_currency(),
                'symbol' => shopper_currency(),
                'format' => '1,234.56',
            ]);

        Price::query()->create([
            'priceable_type' => $product->getMorphClass(),
            'priceable_id' => $product->id,
            'amount' => 399000,
            'currency_id' => $currency->id,
        ]);

        return $product;
    }
}
