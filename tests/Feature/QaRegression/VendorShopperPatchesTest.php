<?php

declare(strict_types=1);

namespace Tests\Feature\QaRegression;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Shopper\Cart\Exceptions\DiscountLimitReachedException;
use Shopper\Core\Enum\DiscountApplyTo;
use Shopper\Core\Enum\DiscountEligibility;
use Shopper\Core\Enum\DiscountRequirement;
use Shopper\Core\Enum\DiscountType;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\CarrierOption;
use Shopper\Core\Models\Country;
use Shopper\Core\Models\Currency;
use Shopper\Core\Models\Discount;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\OrderItem;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Core\Models\Price;
use Shopper\Core\Models\Setting;
use Shopper\Core\Models\Zone;
use Shopper\Livewire\SlideOvers\AddVariant;
use Shopper\Livewire\SlideOvers\ManagePricing;
use Shopper\Livewire\SlideOvers\ShippingOptionForm;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Regressions for defects in Shopper vendor code, fixed by
 * patches/shopper-cart-coupon-last-use.patch and
 * patches/shopper-framework-idr-prices-deleted-products.patch.
 */
final class VendorShopperPatchesTest extends TestCase
{
    use RefreshDatabase;

    private Currency $idr;

    private Zone $zone;

    private CarrierOption $option;

    private PaymentMethod $payment;

    private Inventory $inventory;

    // ---------------------------------------------------------------------
    // (1) A limited coupon is applied on its last allowed use.
    // ---------------------------------------------------------------------

    public function test_single_use_voucher_is_charged_as_shown_at_checkout(): void
    {
        $this->bootStore();
        $discount = $this->coupon('SEKALI', limit: 1);

        [$shown, $order] = $this->webBuyWith('SEKALI');

        // Rp200.000 - Rp50.000 + Rp15.000 ongkir.
        $this->assertSame(165000, $shown);
        $this->assertSame(165000, (int) $order->price_amount);
        $this->assertSame('SEKALI', $order->discount_code);
        $this->assertSame(50000, (int) $order->items()->sum('discount_amount'));
        $this->assertSame(1, (int) $discount->fresh()->total_use);
    }

    public function test_last_use_of_a_limited_coupon_is_charged_as_shown_at_checkout(): void
    {
        $this->bootStore();
        $discount = $this->coupon('LAST', limit: 100, used: 99);

        [$shown, $order] = $this->webBuyWith('LAST');

        $this->assertSame(165000, $shown);
        $this->assertSame($shown, (int) $order->price_amount);
        $this->assertSame(100, (int) $discount->fresh()->total_use);
    }

    public function test_app_place_order_applies_a_single_use_voucher(): void
    {
        $this->bootStore();
        $discount = $this->coupon('APP1', limit: 1);
        $product = $this->product(200000);

        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1])->assertOk();
        $this->postJson('/api/v1/cart/coupon', ['code' => 'APP1'])->assertOk();
        $this->postJson('/api/v1/checkout/shipping-address', $this->address())->assertOk();
        $this->postJson('/api/v1/checkout/shipping-option', ['service_code' => (string) $this->option->id])->assertOk();

        $response = $this->postJson('/api/v1/checkout/place-order', ['payment_method_id' => $this->payment->id])
            ->assertCreated();

        $this->assertSame(165000, (int) $response->json('data.amount'));
        $this->assertSame(165000, (int) $this->latestOrder()->price_amount);
        $this->assertSame(1, (int) $discount->fresh()->total_use);
    }

    public function test_a_spent_limited_coupon_still_cannot_exceed_its_limit(): void
    {
        $this->bootStore();
        $discount = $this->coupon('RACE', limit: 1);

        $user = User::factory()->create();
        $product = $this->product(200000);
        $this->actingAs($user)->withSession(['zone_country_code' => 'ID']);
        $this->post(route('shop.cart.add'), ['product_id' => $product->id, 'quantity' => 1])->assertSessionHasNoErrors();
        $this->post(route('shop.cart.coupon.store'), ['code' => 'RACE'])->assertSessionHasNoErrors();
        $this->post(route('shop.checkout.shipping-address'), $this->address());
        $this->get(route('shop.checkout.index'));
        $this->post(route('shop.checkout.shipping-option'), ['service_code' => (string) $this->option->id]);

        // Another buyer takes the last use between checkout and place-order.
        $discount->update(['total_use' => 1]);

        Exceptions::fake();

        $this->post(route('shop.checkout.place-order'), ['payment_method_id' => $this->payment->id])
            ->assertSessionHasErrors('order');

        // The atomic reservation still guards the limit: no order, no extra use.
        Exceptions::assertReported(DiscountLimitReachedException::class);
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(1, (int) $discount->fresh()->total_use);
    }

    // ---------------------------------------------------------------------
    // (2) Admin can save IDR prices of Rp 1.000.000 and more.
    // ---------------------------------------------------------------------

    public function test_admin_saves_an_idr_product_price_of_rp_4_999_000(): void
    {
        $admin = $this->cpanelAdmin(withIdr: true);
        $product = Product::factory()->standard()->create();

        Livewire::actingAs($admin)
            ->test(ManagePricing::class, ['modelId' => $product->id, 'modelType' => $product::class])
            ->set("data.{$this->idr->id}.amount", '4999000')
            ->set("data.{$this->idr->id}.compare_amount", '5499000')
            ->set("data.{$this->idr->id}.cost_amount", '4000000')
            ->call('save')
            ->assertHasNoErrors();

        $price = Price::query()->where('priceable_id', $product->id)->firstOrFail();
        $this->assertSame(4999000, (int) $price->amount);
        $this->assertSame(5499000, (int) $price->compare_amount);
        $this->assertSame(4000000, (int) $price->cost_amount);
    }

    public function test_admin_resaves_an_existing_rp_10_998_000_price(): void
    {
        $admin = $this->cpanelAdmin(withIdr: true);
        $product = Product::factory()->standard()->create();
        Price::query()->create([
            'priceable_type' => $product->getMorphClass(),
            'priceable_id' => $product->id,
            'amount' => 10998000,
            'currency_id' => $this->idr->id,
        ]);

        Livewire::actingAs($admin)
            ->test(ManagePricing::class, ['modelId' => $product->id, 'modelType' => $product::class])
            ->assertSet("data.{$this->idr->id}.amount", 10998000)
            ->set("data.{$this->idr->id}.compare_amount", '11999000')
            ->call('save')
            ->assertHasNoErrors();

        $price = Price::query()->where('priceable_id', $product->id)->firstOrFail();
        $this->assertSame(10998000, (int) $price->amount);
        $this->assertSame(11999000, (int) $price->compare_amount);
    }

    public function test_idr_price_rule_still_rejects_fractions_and_overflow(): void
    {
        $admin = $this->cpanelAdmin(withIdr: true);
        $product = Product::factory()->standard()->create();

        Livewire::actingAs($admin)
            ->test(ManagePricing::class, ['modelId' => $product->id, 'modelType' => $product::class])
            ->set("data.{$this->idr->id}.amount", '1000000000')
            ->call('save')
            ->assertHasErrors(["data.{$this->idr->id}.amount"]);

        $this->assertFalse(Price::query()->where('priceable_id', $product->id)->exists());
    }

    public function test_admin_adds_a_variant_priced_rp_10_998_000(): void
    {
        $admin = $this->cpanelAdmin(withIdr: true);
        $product = Product::factory()->standard()->create();

        Livewire::actingAs($admin)
            ->test(AddVariant::class, ['product' => $product])
            ->set('data.name', '256GB')
            ->set("data.prices.{$this->idr->id}.amount", '10998000')
            ->call('save')
            ->assertHasNoErrors();

        $variant = $product->variants()->firstOrFail();
        $this->assertSame(
            10998000,
            (int) Price::query()
                ->where('priceable_type', $variant->getMorphClass())
                ->where('priceable_id', $variant->id)
                ->value('amount'),
        );
    }

    public function test_admin_sets_a_shipping_option_price_of_rp_1_250_000(): void
    {
        $admin = $this->cpanelAdmin(withIdr: true);
        $zone = Zone::factory()->create(['is_enabled' => true, 'currency_id' => $this->idr->id]);
        $carrier = Carrier::query()->create(['name' => 'Kargo', 'slug' => 'kargo', 'driver' => 'manual', 'is_enabled' => true]);
        $zone->carriers()->attach($carrier->id);

        Livewire::actingAs($admin)
            ->test(ShippingOptionForm::class, ['zoneId' => $zone->id])
            ->set('data.name', 'Kargo besar')
            ->set('data.price', '1250000')
            ->set('data.carrier_id', $carrier->id)
            ->call('store')
            ->assertHasNoErrors();

        $this->assertSame(1250000, (int) CarrierOption::query()->where('name', 'Kargo besar')->value('price'));
    }

    // ---------------------------------------------------------------------
    // (3) Orders of a deleted product still render in cpanel.
    // ---------------------------------------------------------------------

    public function test_orders_list_and_detail_render_after_the_ordered_product_is_deleted(): void
    {
        $admin = $this->cpanelAdmin();
        $product = Product::factory()->standard()->create(['name' => 'Discontinued Phone']);
        $order = Order::factory()->create(['currency_code' => 'IDR', 'price_amount' => 50000]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'name' => 'Discontinued Phone',
            'sku' => 'QA-1',
            'quantity' => 1,
            'unit_price_amount' => 50000,
            'product_type' => $product->getMorphClass(),
            'product_id' => $product->id,
        ]);

        // Staff delete the product from the catalogue (Shopper soft delete).
        $product->delete();

        $this->actingAs($admin)->get(route('shopper.orders.index'))
            ->assertOk()
            ->assertSee('Discontinued Phone')
            ->assertSee(shopper_fallback_url(), false);

        $this->actingAs($admin)->get(route('shopper.orders.detail', $order))
            ->assertOk()
            ->assertSee('Discontinued Phone');

        $this->actingAs($admin)->get(route('shopper.dashboard'))->assertOk();
    }

    // ---------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------

    private function cpanelAdmin(bool $withIdr = false): User
    {
        $this->configureShopperCpanel();

        if ($withIdr) {
            $this->idr = $this->idrCurrency();
            Setting::query()->updateOrCreate(['key' => 'default_currency_id'], ['value' => $this->idr->id]);
            Setting::query()->updateOrCreate(['key' => 'currencies'], ['value' => [$this->idr->id]]);
            Cache::flush();
        }

        $admin = User::factory()->create();
        Role::query()->firstOrCreate(['name' => config('shopper.admin.roles.admin'), 'guard_name' => 'web']);
        $admin->assignRole(config('shopper.admin.roles.admin'));

        return $admin;
    }

    private function idrCurrency(): Currency
    {
        return Currency::query()->where('code', 'IDR')->first()
            ?? Currency::factory()->create(['code' => 'IDR', 'name' => 'Rupiah', 'symbol' => 'Rp', 'format' => 'Rp1.234']);
    }

    private function bootStore(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $manifest = public_path('build/manifest.json');
        $this->withHeader('X-Inertia-Version', file_exists($manifest) ? hash_file('xxh128', $manifest) : '');

        $this->idr = $this->idrCurrency();
        $country = Country::factory()->create(['cca2' => 'ID']);

        $this->zone = Zone::factory()->create(['is_enabled' => true, 'currency_id' => $this->idr->id]);
        $this->zone->countries()->attach($country->id);

        $carrier = Carrier::query()->create(['name' => 'Kurir Toko', 'slug' => 'manual', 'driver' => 'manual', 'is_enabled' => true]);
        $this->zone->carriers()->attach($carrier->id);

        $this->option = CarrierOption::query()->create([
            'carrier_id' => $carrier->id,
            'zone_id' => $this->zone->id,
            'name' => 'Reguler',
            'description' => 'Reguler 3-5 hari',
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

        Setting::query()->updateOrCreate(['key' => 'default_currency_id'], ['value' => $this->idr->id]);
        Cache::flush();

        $this->inventory = Inventory::factory()->create(['is_default' => true, 'country_id' => $country->id]);
    }

    private function coupon(string $code, ?int $limit, int $used = 0): Discount
    {
        return Discount::query()->create([
            'code' => $code,
            'is_active' => true,
            'type' => DiscountType::FixedAmount,
            'value' => 50000,
            'apply_to' => DiscountApplyTo::Order->value,
            'min_required' => DiscountRequirement::None->value,
            'eligibility' => DiscountEligibility::Everyone->value,
            'usage_limit' => $limit,
            'usage_limit_per_user' => false,
            'total_use' => $used,
            'start_at' => now()->subDay(),
            'end_at' => now()->addYear(),
        ]);
    }

    private function product(int $price): Product
    {
        $product = Product::factory()->standard()->create([
            'published_at' => now()->subDay(),
            'name' => 'Voucher Product',
            'weight_value' => 0.5,
            'weight_unit' => 'kg',
        ]);
        $product->mutateStock($this->inventory->id, 10);

        Price::query()->create([
            'priceable_type' => $product->getMorphClass(),
            'priceable_id' => $product->id,
            'amount' => $price,
            'currency_id' => $this->idr->id,
        ]);

        return $product;
    }

    /**
     * @return array{0: int, 1: Order} the checkout total shown (incl. shipping) and the placed order
     */
    private function webBuyWith(string $code): array
    {
        $product = $this->product(200000);

        $this->actingAs(User::factory()->create())->withSession(['zone_country_code' => 'ID']);
        $this->post(route('shop.cart.add'), ['product_id' => $product->id, 'quantity' => 1])->assertSessionHasNoErrors();
        $this->post(route('shop.cart.coupon.store'), ['code' => $code])->assertSessionHasNoErrors();
        $this->post(route('shop.checkout.shipping-address'), $this->address());
        $this->get(route('shop.checkout.index'));
        $this->post(route('shop.checkout.shipping-option'), ['service_code' => (string) $this->option->id]);

        $shown = 0;
        $this->get(route('shop.checkout.index', ['step' => 3]))->assertInertia(
            function (Assert $page) use (&$shown): void {
                $shown = (int) $page->toArray()['props']['cartContext']['total'] + 15000;
            }
        );

        $this->post(route('shop.checkout.place-order'), ['payment_method_id' => $this->payment->id])
            ->assertSessionHasNoErrors();

        return [$shown, $this->latestOrder()];
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

    private function latestOrder(): Order
    {
        return Order::query()->latest('id')->firstOrFail();
    }
}
