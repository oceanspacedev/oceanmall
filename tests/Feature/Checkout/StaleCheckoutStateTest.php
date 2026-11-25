<?php

declare(strict_types=1);

namespace Tests\Feature\Checkout;

use App\Actions\Notify\NotifyOrderCustomer;
use App\CheckoutSession;
use App\Enums\OrderNotificationType;
use App\Models\Product;
use App\Models\User;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Shopper\Cart\Models\Cart;
use Shopper\Cart\Models\CartLine;
use Shopper\Core\Events\Orders\OrderPaid;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\CarrierOption;
use Shopper\Core\Models\Country;
use Shopper\Core\Models\Currency;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Core\Models\Price;
use Shopper\Core\Models\Zone;
use Shopper\Payment\Enum\TransactionType;
use Shopper\Payment\Models\PaymentTransaction;
use Tests\TestCase;

/**
 * Launch blockers found by the production-readiness QA:
 *
 * - a delivery rate picked for one address was kept after the address changed
 *   (web), and a rate / allocation plan made for one cart was used after the
 *   cart changed (API guest-cart merge): the order was charged the wrong
 *   shipping and left items out of its parcels;
 * - the order notification de-dup queried a JSON path on the TEXT column
 *   `notifications.data`, which PostgreSQL rejects, and that exception
 *   stopped checkout before the payment was initiated;
 * - a migration used a select alias in HAVING, which PostgreSQL rejects.
 */
final class StaleCheckoutStateTest extends TestCase
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
        $this->withHeader('X-Inertia-Version', file_exists($manifest) ? hash_file('xxh128', $manifest) : '');

        $currency = Currency::query()->where('code', 'IDR')->first()
            ?? Currency::factory()->create(['code' => 'IDR', 'name' => 'Rupiah', 'symbol' => 'Rp', 'format' => 'Rp1.234']);
        $country = Country::factory()->create(['cca2' => 'ID']);

        $this->zone = Zone::factory()->create(['is_enabled' => true, 'currency_id' => $currency->id]);
        $this->zone->countries()->attach($country->id);

        $carrier = Carrier::query()->create(['name' => 'Kurir Toko', 'slug' => 'manual', 'driver' => 'manual', 'is_enabled' => true]);
        $this->zone->carriers()->attach($carrier->id);

        $this->option = CarrierOption::query()->create([
            'carrier_id' => $carrier->id,
            'zone_id' => $this->zone->id,
            'name' => 'Reguler',
            'price' => 15000,
            'is_enabled' => true,
        ]);

        $this->payment = PaymentMethod::factory()->create(['title' => 'COD', 'slug' => 'cod', 'driver' => 'manual', 'is_enabled' => true]);
        $this->zone->paymentMethods()->attach($this->payment->id);

        $this->inventory = Inventory::factory()->create(['is_default' => true, 'country_id' => $country->id]);
    }

    public function test_web_changing_the_address_voids_the_picked_delivery_option(): void
    {
        $this->webCheckoutWithPickedOption();
        $this->assertNotNull(session(CheckoutSession::SHIPPING_OPTION));

        // A payment method picked and a payment prepared for the old total.
        $this->withSession([
            CheckoutSession::PAYMENT => [['id' => $this->payment->id, 'driver' => 'manual']],
            'confirm_payment' => ['driver' => 'stripe', 'reference' => 'pi_old', 'data' => []],
        ])
            ->post(route('shop.checkout.shipping-address'), $this->address(['city' => 'Jayapura', 'state' => 'Papua', 'postal_code' => '99111']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('shop.checkout.index'));

        $this->assertNull(session(CheckoutSession::SHIPPING_OPTION), 'The rate was quoted for the previous address.');
        $this->assertNull(session(CheckoutSession::PAYMENT));
        $this->assertNull(session('confirm_payment'), 'Prepared for the previous total.');
        $this->assertNull(session(CheckoutSession::SHIPPING_OPTIONS_BY_SHIPMENT));
        $this->assertNull(session(CheckoutSession::ALLOCATION_PLAN));
        $this->assertSame('Jayapura', session(CheckoutSession::SHIPPING_ADDRESS.'.city'));

        // Back on the delivery step, not on payment.
        $this->get(route('shop.checkout.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('step', 2));

        // An order cannot be placed until a rate for the new address is picked.
        $this->post(route('shop.checkout.place-order'), ['payment_method_id' => $this->payment->id]);
        $this->assertSame(0, Order::query()->count());
    }

    public function test_an_order_is_refused_when_the_cart_no_longer_matches_the_plan(): void
    {
        $cart = $this->webCheckoutWithPickedOption();

        // The cart changes behind the checkout (another device, the app, a
        // merge): no code path voided the session's plan and rate.
        $other = $this->stockedProduct('Second Product');
        CartLine::query()->create([
            'cart_id' => $cart->id,
            'purchasable_type' => $other->getMorphClass(),
            'purchasable_id' => $other->id,
            'quantity' => 3,
            'unit_price_amount' => 399000,
        ]);

        $this->post(route('shop.checkout.place-order'), ['payment_method_id' => $this->payment->id])
            ->assertSessionHasErrors(['order' => __('Your cart changed. Please select the delivery option again.')]);

        $this->assertSame(0, Order::query()->count(), 'Never an order at the old cart\'s shipping rate.');
    }

    public function test_an_order_is_refused_when_a_quantity_or_product_of_the_plan_changed(): void
    {
        $refused = ['order' => __('Your cart changed. Please select the delivery option again.')];

        // Quantity raised after the rate was picked (heavier parcel).
        $cart = $this->webCheckoutWithPickedOption(quantity: 2);
        $line = CartLine::query()->where('cart_id', $cart->id)->sole();
        $line->update(['quantity' => 5]);
        $this->post(route('shop.checkout.place-order'), ['payment_method_id' => $this->payment->id])
            ->assertSessionHasErrors($refused);

        // Quantity lowered.
        $line->update(['quantity' => 1]);
        $this->post(route('shop.checkout.place-order'), ['payment_method_id' => $this->payment->id])
            ->assertSessionHasErrors($refused);

        // Product replaced by another one.
        $other = $this->stockedProduct('Second Product');
        $line->update(['purchasable_id' => $other->id, 'quantity' => 2]);
        $this->post(route('shop.checkout.place-order'), ['payment_method_id' => $this->payment->id])
            ->assertSessionHasErrors($refused);

        $this->assertSame(0, Order::query()->count());

        // Back to exactly what the plan was made for: the order goes through.
        $first = Product::query()->where('name', 'First Product')->sole();
        $line->update(['purchasable_id' => $first->id, 'quantity' => 2]);
        $this->post(route('shop.checkout.place-order'), ['payment_method_id' => $this->payment->id])
            ->assertSessionHasNoErrors();
        $this->assertSame(2 * 399000 + 15000, (int) Order::query()->sole()->price_amount);
    }

    public function test_api_adopting_a_guest_cart_voids_a_quote_left_from_an_earlier_cart(): void
    {
        $user = User::factory()->create();
        $first = $this->stockedProduct('First Product');
        $second = $this->stockedProduct('Second Product');
        $guestToken = (string) Str::uuid();

        $this->withHeader('X-Cart-Token', $guestToken)
            ->postJson('/api/v1/cart/items', ['product_id' => $second->id, 'quantity' => 2])
            ->assertOk();
        $this->flushHeaders();

        Sanctum::actingAs($user);
        $this->postJson('/api/v1/cart/items', ['product_id' => $first->id, 'quantity' => 1])->assertOk();
        $this->postJson('/api/v1/checkout/shipping-address', $this->address())->assertOk();
        $this->postJson('/api/v1/checkout/shipping-option', ['service_code' => (string) $this->option->id])->assertOk();

        // The customer's own cart is gone (closed elsewhere); the checkout
        // state cached for it is still there.
        Cart::query()->where('customer_id', $user->id)->update(['completed_at' => now()]);

        $this->withHeader('X-Cart-Token', $guestToken)
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonCount(1, 'data.lines')
            ->assertJsonPath('data.lines.0.purchasable_id', $second->id);
        $this->flushHeaders();

        $this->getJson('/api/v1/checkout')
            ->assertOk()
            ->assertJsonPath('data.shipping_option', null);
    }

    public function test_api_merging_a_guest_cart_voids_the_picked_delivery_option(): void
    {
        $user = User::factory()->create();
        $first = $this->stockedProduct('First Product');
        $second = $this->stockedProduct('Second Product');
        $guestToken = (string) Str::uuid();

        // A guest cart with another product, as the app keeps one per device.
        $this->withHeader('X-Cart-Token', $guestToken)
            ->postJson('/api/v1/cart/items', ['product_id' => $second->id, 'quantity' => 2])
            ->assertOk();
        $this->flushHeaders();

        Sanctum::actingAs($user);
        $this->postJson('/api/v1/cart/items', ['product_id' => $first->id, 'quantity' => 1])->assertOk();
        $this->postJson('/api/v1/checkout/shipping-address', $this->address())->assertOk();
        $this->postJson('/api/v1/checkout/shipping-option', ['service_code' => (string) $this->option->id])
            ->assertOk()
            ->assertJsonPath('data.shipping_option.service_code', $this->option->id);

        // Opening the cart with the device token absorbs the guest cart.
        $this->withHeader('X-Cart-Token', $guestToken)
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonCount(2, 'data.lines');
        $this->flushHeaders();

        $this->getJson('/api/v1/checkout')
            ->assertOk()
            ->assertJsonPath('data.shipping_option', null);

        $this->postJson('/api/v1/checkout/place-order', ['payment_method_id' => $this->payment->id])
            ->assertStatus(422);

        $this->assertSame(0, Order::query()->count());
    }

    public function test_order_notifications_are_sent_once_without_a_json_path_query(): void
    {
        $this->webCheckoutWithPickedOption();
        $this->post(route('shop.checkout.place-order'), ['payment_method_id' => $this->payment->id])
            ->assertSessionHasNoErrors();

        $order = Order::query()->latest('id')->firstOrFail();
        $customer = User::query()->findOrFail($order->customer_id);
        $this->assertSame(1, $customer->notifications()->count(), 'Order placed (a COD order has nothing to pay online), sent by checkout.');

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $notify = resolve(NotifyOrderCustomer::class);
        $notify->handle($order, OrderNotificationType::OrderPlaced);
        $this->assertSame(1, $customer->notifications()->count(), 'Each type once per order.');

        $notify->handle($order, OrderNotificationType::Paid);
        $notify->handle($order, OrderNotificationType::Paid);
        $this->assertSame(2, $customer->notifications()->count());

        // notifications.data is TEXT: a JSON path on it fails on PostgreSQL.
        foreach ($queries as $sql) {
            if (str_contains($sql, 'notifications')) {
                $this->assertStringNotContainsString('json_extract', $sql);
                $this->assertStringNotContainsString('->', $sql);
            }
        }
    }

    public function test_a_failing_notification_never_stops_checkout_or_the_paid_listeners(): void
    {
        $this->webCheckoutWithPickedOption();

        // The notification channel is down (database error, mail transport).
        $this->app->instance(Dispatcher::class, new class implements Dispatcher
        {
            public function send($notifiables, $notification): void
            {
                throw new RuntimeException('notifications are down');
            }

            public function sendNow($notifiables, $notification, ?array $channels = null): void
            {
                throw new RuntimeException('notifications are down');
            }
        });

        $response = $this->post(route('shop.checkout.place-order'), ['payment_method_id' => $this->payment->id]);
        $order = Order::query()->latest('id')->firstOrFail();

        // The payment was still initiated and the customer lands on success.
        $response->assertRedirect(route('shop.checkout.success', ['order' => $order->id]));
        $this->assertDatabaseHas((new PaymentTransaction)->getTable(), [
            'order_id' => $order->id,
            'type' => TransactionType::Initiate->value,
        ]);
        $this->assertNull(session(CheckoutSession::KEY), 'The checkout is over: no second order from the same cart.');

        // The paid event reaches every listener (fulfilment runs after the notification).
        event(new OrderPaid($order));
        $this->addToAssertionCount(1);
    }

    public function test_the_country_dedupe_migration_uses_the_aggregate_in_having(): void
    {
        $source = (string) file_get_contents(database_path('migrations/2026_10_12_000000_dedupe_countries_and_seed_indonesia_tax_zone.php'));
        $this->assertStringNotContainsString("->having('total'", $source, 'PostgreSQL rejects a select alias in HAVING.');

        $this->assertStringContainsString("->havingRaw('COUNT(*) > 1')", $source);

        // The real migration still merges duplicate countries.
        $countries = shopper_table('countries');
        $keep = Country::factory()->create(['cca2' => 'ZZ']);
        $extra = Country::factory()->create(['cca2' => 'ZZ']);
        $this->assertSame(2, DB::table($countries)->where('cca2', 'ZZ')->count());

        (require database_path('migrations/2026_10_12_000000_dedupe_countries_and_seed_indonesia_tax_zone.php'))->up();

        $this->assertSame([$keep->id], DB::table($countries)->where('cca2', 'ZZ')->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $this->assertNotSame($keep->id, $extra->id);
    }

    /**
     * A logged-in web customer with one product in the cart, an address and
     * the zone's delivery option picked: ready to place the order.
     */
    private function webCheckoutWithPickedOption(int $quantity = 1): Cart
    {
        $user = User::factory()->create();
        $product = $this->stockedProduct('First Product');
        $cart = Cart::query()->create(['currency_code' => 'IDR', 'customer_id' => $user->id]);
        CartLine::query()->create([
            'cart_id' => $cart->id,
            'purchasable_type' => $product->getMorphClass(),
            'purchasable_id' => $product->id,
            'quantity' => $quantity,
            'unit_price_amount' => 399000,
        ]);

        $this->actingAs($user)->withSession([
            config('shopper.cart.session.key', 'shopper_cart') => $cart->id,
            'zone_country_code' => 'ID',
        ]);

        $this->post(route('shop.checkout.shipping-address'), $this->address())->assertSessionHasNoErrors();
        $this->get(route('shop.checkout.index'))->assertOk();
        $this->post(route('shop.checkout.shipping-option'), ['service_code' => (string) $this->option->id])
            ->assertSessionHasNoErrors();

        return $cart;
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function address(array $overrides = []): array
    {
        return [
            'first_name' => 'Budi',
            'last_name' => 'Santoso',
            'street_address' => 'Jl. Merdeka 1',
            'postal_code' => '45153',
            'city' => 'Cirebon',
            'state' => 'Jawa Barat',
            'phone_number' => '081234567890',
            ...$overrides,
        ];
    }

    private function stockedProduct(string $name): Product
    {
        $product = Product::factory()->standard()->create([
            'published_at' => now()->subDay(),
            'name' => $name,
            'weight_value' => 0.5,
            'weight_unit' => 'kg',
        ]);
        $product->mutateStock($this->inventory->id, 10);

        $currency = Currency::query()->where('code', shopper_currency())->firstOrFail();

        Price::query()->create([
            'priceable_type' => $product->getMorphClass(),
            'priceable_id' => $product->id,
            'amount' => 399000,
            'currency_id' => $currency->id,
        ]);

        return $product;
    }
}
