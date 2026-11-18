<?php

declare(strict_types=1);

namespace Tests\Feature\Checkout;

use App\CheckoutSession;
use App\Models\Product;
use App\Models\User;
use App\Shipping\Contracts\RequiresCoordinates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;
use Shopper\Cart\Models\Cart;
use Shopper\Cart\Models\CartLine;
use Shopper\Core\Models\Country;
use Shopper\Core\Models\Currency;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Price;
use Shopper\Core\Models\Zone;
use Shopper\Shipping\DataTransferObjects\Address;
use Shopper\Shipping\Drivers\Driver;
use Shopper\Shipping\Facades\Shipping;
use Tests\TestCase;

/**
 * Web checkout acts on shippingCapabilities.requiresCoordinates (the customer
 * may share a pin point) and logs a missing-rate configuration hint once per
 * address and cause instead of on every render.
 */
final class CheckoutCoordinatesAndHintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake();

        $manifest = public_path('build/manifest.json');
        $this->withHeader('X-Inertia-Version', file_exists($manifest) ? hash_file('xxh128', $manifest) : '');

        $currency = Currency::query()->where('code', 'IDR')->first()
            ?? Currency::factory()->create(['code' => 'IDR', 'name' => 'Rupiah', 'symbol' => 'Rp', 'format' => 'Rp1.234']);
        $country = Country::factory()->create(['cca2' => 'ID']);
        $zone = Zone::factory()->create(['is_enabled' => true, 'currency_id' => $currency->id]);
        $zone->countries()->attach($country->id);

        $inventory = Inventory::factory()->create(['is_default' => true, 'country_id' => $country->id]);
        $this->actingAsCustomerWithCart($inventory);
    }

    public function test_a_coordinate_driver_lets_the_customer_share_a_pin_point(): void
    {
        Shipping::extend('pinship', static fn (): PinShipDriver => new PinShipDriver);
        config()->set('shopper.shipping.drivers.pinship.enabled', true);

        $this->get(route('shop.checkout.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('shippingCapabilities.requiresCoordinates', true)
                ->where('shippingCapabilities.destinationSearch', false));

        $this->post(route('shop.checkout.shipping-address'), [...$this->address(), 'pin_point' => 'not-a-pin'])
            ->assertSessionHasErrors('pin_point');

        $this->post(route('shop.checkout.shipping-address'), [...$this->address(), 'pin_point' => '-6.712748,108.532027'])
            ->assertSessionHasNoErrors();

        $this->assertSame('-6.712748,108.532027', session(CheckoutSession::SHIPPING_ADDRESS)['pin_point']);

        // The form gets it back for editing.
        $this->get(route('shop.checkout.index', ['step' => 1]))
            ->assertInertia(fn (Assert $page) => $page->where('shippingAddress.pin_point', '-6.712748,108.532027'));
    }

    public function test_the_no_shipping_option_notice_is_logged_once_per_address_and_cause(): void
    {
        // Keyless, and the zone has no CarrierOption: a common misconfiguration.
        Log::spy();

        $this->post(route('shop.checkout.shipping-address'), $this->address())->assertSessionHasNoErrors();

        for ($i = 0; $i < 3; $i++) {
            $this->get(route('shop.checkout.index'))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('shippingRatesHint', fn (?string $hint): bool => is_string($hint)));
        }

        Log::shouldHaveReceived('notice')
            ->withArgs(fn (string $message): bool => str_contains($message, 'no shipping option'))
            ->once();
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
            'phone_number' => '081234567890',
        ];
    }

    private function actingAsCustomerWithCart(Inventory $inventory): void
    {
        $user = User::factory()->create();
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
    }
}

final class PinShipDriver extends Driver implements RequiresCoordinates
{
    public function code(): string
    {
        return 'pinship';
    }

    public function name(): string
    {
        return 'PinShip';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function supportsRealTimeRates(): bool
    {
        return true;
    }

    public function supportsLabels(): bool
    {
        return false;
    }

    public function supportsTracking(): bool
    {
        return false;
    }

    public function calculateRates(Address $from, Address $to, array $packages): Collection
    {
        return collect();
    }
}
