<?php

declare(strict_types=1);

namespace Tests\Feature\QaRegression;

use App\Actions\Checkout\SelectedShipmentRates;
use App\Actions\CreateOrder;
use App\CheckoutSession;
use App\DTO\AllocationPlan;
use App\DTO\ShipmentDraft;
use App\Models\OrderShipment;
use App\Models\Product;
use App\Models\User;
use App\Stock\AllocationPlanStockAllocator;
use App\Support\CheckoutAllocationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Shopper\Cart\Models\Cart;
use Shopper\Cart\Models\CartLine;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\CarrierOption;
use Shopper\Core\Models\Country;
use Shopper\Core\Models\Currency;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Core\Models\Price;
use Shopper\Core\Models\Setting;
use Shopper\Core\Models\Zone;
use Shopper\Core\Stock\StockAllocation;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Go-live QA: (1) the stock reserved at checkout is re-checked against the
 * stock each warehouse holds when the order is placed (the allocation plan
 * from the shipping step can be stale), so the last unit is never sold twice
 * and stock never goes below zero; (2) a cart split over several warehouses
 * is charged a rate for every package, on web and in the app, and is never
 * ordered with Rp0 shipping.
 */
final class StockAndSplitShippingTest extends TestCase
{
    use RefreshDatabase;

    private Country $country;

    private CarrierOption $option;

    private PaymentMethod $payment;

    private Inventory $inventory;

    private Inventory $second;

    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake();

        $manifest = public_path('build/manifest.json');
        $this->withHeader('X-Inertia-Version', file_exists($manifest) ? (string) hash_file('xxh128', $manifest) : '');

        $this->currency = Currency::query()->where('code', 'IDR')->first()
            ?? Currency::factory()->create(['code' => 'IDR', 'name' => 'Rupiah', 'symbol' => 'Rp', 'format' => 'Rp1.234']);
        $this->country = Country::factory()->create(['cca2' => 'ID']);

        $zone = Zone::factory()->create(['is_enabled' => true, 'currency_id' => $this->currency->id]);
        $zone->countries()->attach($this->country->id);

        $carrier = Carrier::query()->create(['name' => 'Kurir Toko', 'slug' => 'manual', 'driver' => 'manual', 'is_enabled' => true]);
        $zone->carriers()->attach($carrier->id);

        $this->option = CarrierOption::query()->create([
            'carrier_id' => $carrier->id,
            'zone_id' => $zone->id,
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
        $zone->paymentMethods()->attach($this->payment->id);

        Setting::query()->updateOrCreate(['key' => 'default_currency_id'], ['value' => $this->currency->id]);
        Cache::flush();

        $this->inventory = Inventory::factory()->create(['is_default' => true, 'country_id' => $this->country->id]);
        $this->second = Inventory::factory()->create(['is_default' => false, 'country_id' => $this->country->id, 'name' => 'Gudang Bandung']);
    }

    public function test_last_unit_sold_to_another_web_buyer_refuses_the_order(): void
    {
        $product = $this->product(stock: 1);
        $first = User::factory()->create(['email' => 'stock-web-a@example.com']);
        $second = User::factory()->create(['email' => 'stock-web-b@example.com']);

        // A reaches the payment step (allocation plan stored in the session).
        $this->webCheckoutUpToPayment($first, [$product]);
        $sessionA = session()->all();

        // B buys the last unit in their own session.
        session()->flush();
        $this->webCheckoutUpToPayment($second, [$product]);
        $this->post(route('shop.checkout.place-order'), ['payment_method_id' => $this->payment->id])->assertSessionHasNoErrors();
        $this->assertSame(0, $product->fresh()->stockInventory($this->inventory->id));

        // A then clicks "Bayar" on the page they had open.
        session()->flush();
        session()->put($sessionA);
        $this->actingAs($first);
        $this->post(route('shop.checkout.place-order'), ['payment_method_id' => $this->payment->id])
            ->assertSessionHasErrors('order');

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(0, $product->fresh()->stockInventory($this->inventory->id));
    }

    public function test_last_unit_sold_to_another_app_buyer_refuses_the_order(): void
    {
        $product = $this->product(stock: 1);
        $first = User::factory()->create(['email' => 'stock-app-a@example.com']);
        $second = User::factory()->create(['email' => 'stock-app-b@example.com']);

        foreach ([$first, $second] as $user) {
            Sanctum::actingAs($user);
            $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1])->assertOk();
            $this->postJson('/api/v1/checkout/shipping-address', $this->address())->assertOk();
            $this->postJson('/api/v1/checkout/shipping-option', ['service_code' => (string) $this->option->id])->assertOk();
        }

        Sanctum::actingAs($second);
        $this->postJson('/api/v1/checkout/place-order', ['payment_method_id' => $this->payment->id])->assertCreated();

        Sanctum::actingAs($first);
        $this->postJson('/api/v1/checkout/place-order', ['payment_method_id' => $this->payment->id])
            ->assertStatus(422)
            ->assertJsonPath('message', __('Some items in your cart are no longer in stock. Please update your cart and try again.'));

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(0, $product->fresh()->stockInventory($this->inventory->id));
    }

    public function test_stock_lowered_by_staff_after_the_shipping_step_refuses_the_order(): void
    {
        $product = $this->product(stock: 3);
        $user = User::factory()->create(['email' => 'stock-staff@example.com']);
        $this->webCheckoutUpToPayment($user, [$product], qty: 3);

        // Staff correct the stock down to 1 while the customer is on step 3.
        $product->mutateStock($this->inventory->id, -2);

        $this->post(route('shop.checkout.place-order'), ['payment_method_id' => $this->payment->id])
            ->assertSessionHasErrors('order');

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(1, $product->fresh()->stockInventory($this->inventory->id));
        $this->assertNull(Cart::query()->where('customer_id', $user->id)->latest('id')->first()?->completed_at);
    }

    public function test_planned_allocations_never_exceed_the_stock_of_their_warehouse(): void
    {
        $product = $this->product(stock: 1);
        $product->mutateStock($this->second->id, 5);

        $context = resolve(CheckoutAllocationContext::class);
        $context->set(new AllocationPlan([
            new ShipmentDraft($this->inventory->id, [
                ['purchasable_type' => $product->getMorphClass(), 'purchasable_id' => $product->id, 'qty' => 3],
            ]),
        ]));

        $allocator = resolve(AllocationPlanStockAllocator::class);
        $allocations = $allocator->allocate($product, 3);

        $this->assertSame(
            [[$this->inventory->id, 1]],
            array_map(static fn (StockAllocation $a): array => [$a->inventoryId, $a->quantity], $allocations),
        );

        // Sold out in the planned warehouse: nothing, not another warehouse
        // the customer was never quoted shipping from.
        $product->mutateStock($this->inventory->id, -1);
        $this->assertSame([], $allocator->allocate($product, 3));

        $context->clear();
    }

    public function test_app_split_cart_charges_shipping_for_every_package(): void
    {
        $first = $this->product(stock: 5, price: 100000, name: 'A');
        $second = $this->product(stock: 5, price: 200000, inventory: $this->second, name: 'B');
        Sanctum::actingAs(User::factory()->create(['email' => 'split-app@example.com']));

        $this->postJson('/api/v1/cart/items', ['product_id' => $first->id, 'quantity' => 1])->assertOk();
        $this->postJson('/api/v1/cart/items', ['product_id' => $second->id, 'quantity' => 1])->assertOk();
        $this->postJson('/api/v1/checkout/shipping-address', $this->address())->assertOk()
            // The app is offered what shipping the whole cart costs.
            ->assertJsonPath('data.shipping_rates.0.amount', 30000);

        $this->postJson('/api/v1/checkout/shipping-option', ['service_code' => (string) $this->option->id])->assertOk()
            ->assertJsonPath('data.shipping_option.amount', 30000);

        $response = $this->postJson('/api/v1/checkout/place-order', ['payment_method_id' => $this->payment->id])->assertCreated();

        $order = Order::query()->latest('id')->firstOrFail();
        $shipments = OrderShipment::query()->where('order_id', $order->id)->orderBy('id')->get();

        $this->assertSame(330000, (int) $order->price_amount);
        $this->assertSame(330000, $response->json('data.amount'));
        $this->assertSame([$this->inventory->id, $this->second->id], $shipments->pluck('inventory_id')->all());
        $this->assertSame([15000, 15000], $shipments->pluck('cost')->map(fn ($cost): int => (int) $cost)->all());
        $this->assertSame(['manual', 'manual'], $shipments->pluck('driver')->all());
    }

    public function test_web_split_cart_refuses_a_single_service_code(): void
    {
        $first = $this->product(stock: 5, price: 100000, name: 'A');
        $second = $this->product(stock: 5, price: 200000, inventory: $this->second, name: 'B');
        $this->webCartWithAddress(User::factory()->create(['email' => 'split-web@example.com']), [$first, $second]);
        $this->get(route('shop.checkout.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('allocation', 2));

        // Legacy single-option post (old tab / crafted request) on a split cart.
        $this->post(route('shop.checkout.shipping-option'), ['service_code' => (string) $this->option->id])
            ->assertSessionHasErrors('service_code');
        $this->assertNull(session()->get(CheckoutSession::SHIPPING_OPTION));

        $this->post(route('shop.checkout.place-order'), ['payment_method_id' => $this->payment->id])
            ->assertSessionHasErrors('order');
        $this->assertSame(0, Order::query()->count());
    }

    public function test_web_split_cart_rates_map_charges_each_package(): void
    {
        $first = $this->product(stock: 5, price: 100000, name: 'A');
        $second = $this->product(stock: 5, price: 200000, inventory: $this->second, name: 'B');
        $this->webCartWithAddress(User::factory()->create(['email' => 'split-web-map@example.com']), [$first, $second]);
        $this->get(route('shop.checkout.index'))->assertOk();

        $this->post(route('shop.checkout.shipping-option'), ['rates' => [
            $this->inventory->id => (string) $this->option->id,
            $this->second->id => (string) $this->option->id,
        ]])->assertSessionHasNoErrors();
        $this->get(route('shop.checkout.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('step', 3));
        $this->post(route('shop.checkout.place-order'), ['payment_method_id' => $this->payment->id])->assertSessionHasNoErrors();

        $this->assertSame(330000, (int) Order::query()->latest('id')->firstOrFail()->price_amount);
        $this->assertSame(4, $first->fresh()->stockInventory($this->inventory->id));
        $this->assertSame(4, $second->fresh()->stockInventory($this->second->id));
    }

    public function test_web_plan_that_splits_after_the_rate_was_picked_asks_for_a_rate_per_package(): void
    {
        $first = $this->product(stock: 5, price: 100000, name: 'A');
        $second = $this->product(stock: 5, price: 200000, name: 'B');
        $second->mutateStock($this->second->id, 5);
        $this->webCheckoutUpToPayment(User::factory()->create(['email' => 'split-replan@example.com']), [$first, $second]);
        $this->assertNotNull(session()->get(CheckoutSession::SHIPPING_OPTION));

        // B runs out in the default warehouse: the cart now ships in two packages.
        $second->mutateStock($this->inventory->id, -5);

        $this->get(route('shop.checkout.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('allocation', 2)
            ->where('step', 2));
        $this->assertNull(session()->get(CheckoutSession::SHIPPING_OPTION));

        $this->post(route('shop.checkout.place-order'), ['payment_method_id' => $this->payment->id])
            ->assertSessionHasErrors('order');
        $this->assertSame(0, Order::query()->count());
    }

    public function test_web_plan_moved_to_another_warehouse_asks_for_the_rate_again(): void
    {
        $product = $this->product(stock: 2);
        $product->mutateStock($this->second->id, 5);
        $this->webCheckoutUpToPayment(User::factory()->create(['email' => 'replan-moved@example.com']), [$product], qty: 2);

        // The default warehouse can no longer fill the line: it ships from the second one.
        $product->mutateStock($this->inventory->id, -1);

        $this->get(route('shop.checkout.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('allocation.0.inventory_id', $this->second->id)
            ->where('step', 2));
        $this->assertNull(session()->get(CheckoutSession::SHIPPING_OPTION));
    }

    public function test_create_order_refuses_a_split_plan_without_a_rate_for_every_package(): void
    {
        $user = User::factory()->create(['email' => 'split-direct@example.com']);
        $first = $this->product(stock: 5, price: 100000, name: 'A');
        $second = $this->product(stock: 5, price: 200000, inventory: $this->second, name: 'B');

        $cart = Cart::query()->create(['currency_code' => 'IDR', 'customer_id' => $user->id]);
        foreach ([[$first, 100000], [$second, 200000]] as [$product, $price]) {
            CartLine::query()->create([
                'cart_id' => $cart->id,
                'purchasable_type' => $product->getMorphClass(),
                'purchasable_id' => $product->id,
                'quantity' => 1,
                'unit_price_amount' => $price,
            ]);
        }

        $this->actingAs($user);

        try {
            resolve(CreateOrder::class)->handle([
                'shipping_address' => $this->address(['country_id' => $this->country->id]),
                'shipping_option' => [['id' => (string) $this->option->id, 'price' => 15000, 'amount' => 15000, 'driver' => 'manual']],
                'payment' => [['id' => $this->payment->id, 'driver' => 'manual']],
            ], $cart);
            $this->fail('A split cart with a single rate was ordered.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame(__('Please select a delivery option for all packages.'), $e->getMessage());
        }

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, OrderShipment::query()->count());
        $this->assertSame(5, $first->fresh()->stockInventory($this->inventory->id));
        $this->assertSame(5, $second->fresh()->stockInventory($this->second->id));
        $this->assertNull($cart->fresh()->completed_at);
    }

    public function test_backorder_product_short_in_its_planned_warehouse_is_backordered_there(): void
    {
        // Out of stock in the default warehouse, last unit in the second one.
        $product = $this->product(stock: 0);
        $product->forceFill(['allow_backorder' => true])->save();
        $product->mutateStock($this->second->id, 1);

        Sanctum::actingAs(User::factory()->create(['email' => 'backorder@example.com']));
        $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1])->assertOk();
        $this->postJson('/api/v1/checkout/shipping-address', $this->address())->assertOk();
        $this->postJson('/api/v1/checkout/shipping-option', ['service_code' => (string) $this->option->id])->assertOk();

        // The second warehouse's last unit sells meanwhile.
        $product->mutateStock($this->second->id, -1);

        $this->postJson('/api/v1/checkout/place-order', ['payment_method_id' => $this->payment->id])->assertCreated();

        // Backordered in the warehouse that ships it, not in the default one.
        $shipment = OrderShipment::query()->sole();
        $this->assertSame($this->second->id, (int) $shipment->inventory_id);
        $this->assertSame(0, $product->fresh()->stockInventory($this->inventory->id));
        $this->assertSame(-1, $product->fresh()->stockInventory($this->second->id));
    }

    public function test_web_split_plan_that_merges_after_rates_were_picked_asks_for_the_rate_again(): void
    {
        $first = $this->product(stock: 5, price: 100000, name: 'A');
        $second = $this->product(stock: 5, price: 200000, inventory: $this->second, name: 'B');
        $this->webCartWithAddress(User::factory()->create(['email' => 'split-merge@example.com']), [$first, $second]);
        $this->get(route('shop.checkout.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('allocation', 2));
        $this->post(route('shop.checkout.shipping-option'), ['rates' => [
            $this->inventory->id => (string) $this->option->id,
            $this->second->id => (string) $this->option->id,
        ]])->assertSessionHasNoErrors();

        // B is restocked in the default warehouse: the cart now ships in one
        // package, heavier and worth more than the one its W1 rate was quoted for.
        $second->mutateStock($this->inventory->id, 5);

        $this->get(route('shop.checkout.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('allocation', 1)
            ->where('step', 2));
        $this->assertNull(session()->get(CheckoutSession::SHIPPING_OPTION));
        $this->assertNull(session()->get(CheckoutSession::SHIPPING_OPTIONS_BY_SHIPMENT));

        $this->post(route('shop.checkout.place-order'), ['payment_method_id' => $this->payment->id])
            ->assertSessionHasErrors('order');
        $this->assertSame(0, Order::query()->count());
    }

    public function test_a_rate_counts_only_for_the_package_it_was_quoted_for(): void
    {
        $rates = resolve(SelectedShipmentRates::class);
        $line = fn (int $id, int $qty = 1): array => ['purchasable_type' => 'product', 'purchasable_id' => $id, 'qty' => $qty];
        $quotedFor = new ShipmentDraft($this->inventory->id, [$line(1), $line(2)]);
        $rate = $rates->stamp(['service_code' => 'reg', 'amount' => 15000], $quotedFor);

        // Same package (line order does not matter): the rate, without its fingerprint.
        $this->assertSame(
            ['service_code' => 'reg', 'amount' => 15000, 'inventory_id' => $this->inventory->id],
            $rates->forShipment(new ShipmentDraft($this->inventory->id, [$line(2), $line(1)]), ['shipping_option' => [$rate]], 1),
        );

        // Lines moved in or out of the warehouse, or another quantity: not this package's rate.
        foreach ([[$line(1)], [$line(1), $line(2), $line(3)], [$line(1), $line(2, 2)]] as $lines) {
            $draft = new ShipmentDraft($this->inventory->id, $lines);
            $this->assertSame([], $rates->forShipment($draft, ['shipping_option' => [$rate]], 1));
            $this->assertSame([], $rates->forShipment($draft, ['shipping_options_by_shipment' => [$this->inventory->id => $rate]], 2));
            $this->assertFalse($rates->coverPlan(new AllocationPlan([$draft]), ['shipping_options_by_shipment' => [$this->inventory->id => $rate]]));
        }

        // Rates saved before fingerprints existed still count.
        $this->assertTrue($rates->coverPlan(
            new AllocationPlan([new ShipmentDraft($this->inventory->id, [$line(9)])]),
            ['shipping_option' => [['service_code' => 'reg', 'amount' => 15000]]],
        ));
    }

    private function product(int $stock = 10, int $price = 399000, ?Inventory $inventory = null, string $name = 'QA Product'): Product
    {
        $product = Product::factory()->standard()->create([
            'published_at' => now()->subDay(),
            'name' => $name,
            'weight_value' => 0.5,
            'weight_unit' => 'kg',
        ]);

        if ($stock > 0) {
            $product->mutateStock(($inventory ?? $this->inventory)->id, $stock);
        }

        Price::query()->create([
            'priceable_type' => $product->getMorphClass(),
            'priceable_id' => $product->id,
            'amount' => $price,
            'currency_id' => $this->currency->id,
        ]);

        return $product;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function address(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Budi',
            'last_name' => 'Santoso',
            'street_address' => 'Jl. Merdeka 1',
            'postal_code' => '45153',
            'city' => 'Cirebon',
            'state' => 'Jawa Barat',
            'phone_number' => '081234567890',
        ], $overrides);
    }

    /**
     * @param  list<Product>  $products
     */
    private function webCartWithAddress(User $user, array $products, int $qty = 1): void
    {
        $this->actingAs($user)->withSession(['zone_country_code' => 'ID']);

        foreach ($products as $product) {
            $this->post(route('shop.cart.add'), ['product_id' => $product->id, 'quantity' => $qty])->assertSessionHasNoErrors();
        }

        $this->post(route('shop.checkout.shipping-address'), $this->address())->assertSessionHasNoErrors();
    }

    /**
     * Web checkout up to (not including) place-order, with one shipping rate.
     *
     * @param  list<Product>  $products
     */
    private function webCheckoutUpToPayment(User $user, array $products, int $qty = 1): void
    {
        $this->webCartWithAddress($user, $products, $qty);
        $this->get(route('shop.checkout.index'))->assertOk();
        $this->post(route('shop.checkout.shipping-option'), ['service_code' => (string) $this->option->id])->assertSessionHasNoErrors();
    }
}
