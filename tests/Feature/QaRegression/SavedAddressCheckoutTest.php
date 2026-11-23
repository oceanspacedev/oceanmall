<?php

declare(strict_types=1);

namespace Tests\Feature\QaRegression;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Shopper\Cart\Models\Cart;
use Shopper\Cart\Models\CartLine;
use Shopper\Core\Enum\AddressType;
use Shopper\Core\Models\Address;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\CarrierOption;
use Shopper\Core\Models\Country;
use Shopper\Core\Models\Currency;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Core\Models\Price;
use Shopper\Core\Models\Zone;
use Tests\TestCase;

/**
 * QA 2026-11-23 (go-live blockers):
 *  - the mobile app posts the zone option's service_code as the integer the
 *    API returned; the API refused it ("Service code harus berupa string."),
 *    so a store without a shipping provider could not check out on mobile;
 *  - a saved address (web: auto-applied default, API: shipping-address/saved)
 *    lived only in the checkout state, so Shopper created the order without
 *    a shipping address (shipping_address_id NULL) and without the province.
 */
final class SavedAddressCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Country $country;

    private CarrierOption $option;

    private PaymentMethod $payment;

    private Inventory $inventory;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake();

        $manifest = public_path('build/manifest.json');
        $this->withHeader('X-Inertia-Version', file_exists($manifest) ? hash_file('xxh128', $manifest) : '');

        $currency = Currency::query()->where('code', 'IDR')->first()
            ?? Currency::factory()->create(['code' => 'IDR', 'name' => 'Rupiah', 'symbol' => 'Rp', 'format' => 'Rp1.234']);
        $this->country = Country::factory()->create(['cca2' => 'ID']);

        $zone = Zone::factory()->create(['is_enabled' => true, 'currency_id' => $currency->id]);
        $zone->countries()->attach($this->country->id);

        $carrier = Carrier::query()->create(['name' => 'Kurir Toko', 'slug' => 'manual', 'driver' => 'manual', 'is_enabled' => true]);
        $zone->carriers()->attach($carrier->id);

        $this->option = CarrierOption::query()->create([
            'carrier_id' => $carrier->id,
            'zone_id' => $zone->id,
            'name' => 'Reguler',
            'price' => 15000,
            'is_enabled' => true,
        ]);

        $this->payment = PaymentMethod::factory()->create([
            'title' => 'Bayar di tempat (COD)',
            'slug' => 'cod',
            'driver' => 'manual',
            'is_enabled' => true,
        ]);
        $zone->paymentMethods()->attach($this->payment->id);

        $this->inventory = Inventory::factory()->create(['is_default' => true, 'country_id' => $this->country->id]);
    }

    public function test_api_accepts_the_integer_service_code_it_returned(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/cart/items', ['product_id' => $this->stockedProduct()->id, 'quantity' => 1])->assertOk();

        $this->postJson('/api/v1/checkout/shipping-address', $this->addressPayload())
            ->assertOk()
            ->assertJsonPath('data.shipping_rates.0.service_code', $this->option->id);

        // Exactly what the app sends back: the JSON number from shipping_rates.
        $this->postJson('/api/v1/checkout/shipping-option', ['service_code' => $this->option->id])
            ->assertOk()
            ->assertJsonPath('data.shipping_option.service_code', $this->option->id);

        $this->postJson('/api/v1/checkout/shipping-option', ['service_code' => ['x']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('service_code');
    }

    public function test_api_order_with_a_saved_address_has_its_shipping_address(): void
    {
        $user = User::factory()->create();
        $saved = $this->savedAddress($user);
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/cart/items', ['product_id' => $this->stockedProduct()->id, 'quantity' => 1])->assertOk();

        $this->postJson('/api/v1/checkout/shipping-address/saved', ['address_id' => $saved->id])
            ->assertOk()
            ->assertJsonPath('data.shipping_rates.0.service_code', $this->option->id);
        $this->postJson('/api/v1/checkout/shipping-option', ['service_code' => $this->option->id])->assertOk();
        $this->postJson('/api/v1/checkout/place-order', ['payment_method_id' => $this->payment->id])->assertSuccessful();

        $this->assertOrderShipsTo(Order::query()->latest('id')->firstOrFail());
    }

    public function test_web_order_with_the_auto_applied_default_address_has_its_shipping_address(): void
    {
        $user = User::factory()->create();
        $this->savedAddress($user);
        $product = $this->stockedProduct();
        $cart = Cart::query()->create(['currency_code' => 'IDR', 'customer_id' => $user->id]);
        CartLine::query()->create([
            'cart_id' => $cart->id,
            'purchasable_type' => $product->getMorphClass(),
            'purchasable_id' => $product->id,
            'quantity' => 1,
            'unit_price_amount' => 399000,
        ]);

        $this->actingAs($user)->withSession([
            config('shopper.cart.session.key', 'shopper_cart') => $cart->id,
            'zone_country_code' => 'ID',
        ]);

        // Returning customer: the default address is applied without posting the form.
        $this->get(route('shop.checkout.index'))->assertOk();
        $this->post(route('shop.checkout.shipping-option'), ['service_code' => (string) $this->option->id])
            ->assertSessionHasNoErrors();
        $this->post(route('shop.checkout.place-order'), ['payment_method_id' => $this->payment->id])
            ->assertSessionHasNoErrors();

        $this->assertOrderShipsTo(Order::query()->latest('id')->firstOrFail());
    }

    private function assertOrderShipsTo(Order $order): void
    {
        $this->assertNotNull($order->shipping_address_id, 'Order must keep the delivery address.');
        $address = $order->shippingAddress;
        $this->assertSame('Jl. Melawai Raya No. 1', $address->street_address);
        $this->assertSame('Jakarta Selatan', $address->city);
        $this->assertSame('DKI Jakarta', $address->state);
        $this->assertSame('12240', $address->postal_code);
        $this->assertSame('081234567890', $address->phone);
        $this->assertSame(399000 + 15000, (int) $order->price_amount);
    }

    private function savedAddress(User $user): Address
    {
        return Address::query()->create([
            'user_id' => $user->id,
            'country_id' => $this->country->id,
            'first_name' => 'Budi',
            'last_name' => 'Santoso',
            'street_address' => 'Jl. Melawai Raya No. 1',
            'postal_code' => '12240',
            'city' => 'Jakarta Selatan',
            'state' => 'DKI Jakarta',
            'phone_number' => '081234567890',
            'type' => AddressType::Shipping,
            'shipping_default' => true,
            'billing_default' => false,
            'metadata' => json_encode([]),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function addressPayload(): array
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

    private function stockedProduct(): Product
    {
        $product = Product::factory()->standard()->create([
            'published_at' => now()->subDay(),
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
