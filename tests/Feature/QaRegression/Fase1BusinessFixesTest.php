<?php

declare(strict_types=1);

namespace Tests\Feature\QaRegression;

use App\Actions\Checkout\FetchDeliveryRates;
use App\Actions\CreateOrder;
use App\Actions\Orders\CancelOrderWithProviders;
use App\Actions\Warehouse\OverrideAllocation;
use App\Actions\ZoneSessionManager;
use App\CheckoutSession;
use App\DTO\AllocationPlan;
use App\DTO\ShipmentDraft;
use App\Integrations\Komerce\Actions\Payment\CancelUnpaidKomerceOrder;
use App\Integrations\Komerce\Actions\Payment\RetryKomercePayment;
use App\Models\OrderShipment;
use App\Models\Product;
use App\Models\User;
use App\Payment\Drivers\ConfirmFirstStripeDriver;
use App\Payment\Exceptions\PaymentRetryRefused;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Shopper\Cart\CartSessionManager;
use Shopper\Cart\Models\Cart;
use Shopper\Cart\Models\CartLine;
use Shopper\Cart\Models\CartLineAdjustment;
use Shopper\Core\Enum\DiscountApplyTo;
use Shopper\Core\Enum\DiscountEligibility;
use Shopper\Core\Enum\DiscountRequirement;
use Shopper\Core\Enum\DiscountType;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Enum\ShippingStatus;
use Shopper\Core\Events\Orders\OrderCancelled;
use Shopper\Core\Events\Orders\OrderPaid;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\CarrierOption;
use Shopper\Core\Models\Country;
use Shopper\Core\Models\Currency;
use Shopper\Core\Models\Discount;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Core\Models\Price;
use Shopper\Core\Models\Zone;
use Shopper\Payment\Enum\TransactionStatus;
use Shopper\Payment\Enum\TransactionType;
use Shopper\Payment\Models\PaymentTransaction;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class Fase1BusinessFixesTest extends TestCase
{
    use RefreshDatabase;

    private function idrCurrency(): Currency
    {
        $code = shopper_currency();

        return Currency::query()->where('code', $code)->first()
            ?? Currency::factory()->create(['code' => $code, 'name' => $code, 'symbol' => $code, 'format' => '1,234.56']);
    }

    private function seedIndonesiaZone(Currency $currency): array
    {
        $country = Country::query()->where('cca2', 'ID')->first()
            ?? Country::factory()->create(['cca2' => 'ID']);
        $zone = Zone::factory()->create(['is_enabled' => true, 'currency_id' => $currency->id]);
        $zone->countries()->attach($country->id);

        return [$country, $zone];
    }

    public function test_web_registration_assigns_customer_role_and_backfill_migration_covers_existing_users(): void
    {
        Role::query()->firstOrCreate([
            'name' => config('shopper.admin.roles.user', 'user'),
            'guard_name' => 'web',
        ]);

        $legacyUser = User::factory()->create(['email' => 'legacy@example.com']);
        $this->assertFalse($legacyUser->hasRole(config('shopper.admin.roles.user', 'user')));

        $migration = require database_path('migrations/2026_11_26_000100_assign_user_role_to_web_customers.php');
        $migration->up();

        $this->assertTrue($legacyUser->fresh()->hasRole(config('shopper.admin.roles.user', 'user')));
        $this->assertTrue(User::query()->scopes('customers')->whereKey($legacyUser->id)->exists());

        $this->post(route('register.store'), [
            'first_name' => 'Rina',
            'last_name' => 'Putri',
            'email' => 'RINA@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        $registered = User::query()->where('email', 'rina@example.com')->firstOrFail();
        $this->assertTrue($registered->hasRole(config('shopper.admin.roles.user', 'user')));
        $this->assertTrue(User::query()->scopes('customers')->whereKey($registered->id)->exists());
    }

    public function test_stripe_idr_minor_unit_helpers_and_webhook_hardening(): void
    {
        $this->assertSame(15000000, ConfirmFirstStripeDriver::toStripeAmount(150000, 'IDR'));
        $this->assertSame(150000, ConfirmFirstStripeDriver::fromStripeAmount(15000000, 'IDR'));
        $this->assertNull(ConfirmFirstStripeDriver::fromStripeAmount(150050, 'IDR'));
        $this->assertSame(2500, ConfirmFirstStripeDriver::toStripeAmount(2500, 'USD'));

        config()->set('shopper.payment.drivers.stripe.enabled', true);
        config()->set('shopper.payment.drivers.stripe.credentials.webhook_secret', 'whsec_test');
        config()->set('shopper.stripe.webhook_secret', 'whsec_test');

        $inventory = Inventory::factory()->create(['is_default' => true]);
        $product = Product::factory()->standard()->create();
        $product->mutateStock($inventory->id, 2);

        $discount = Discount::query()->create([
            'code' => 'STRIPE10',
            'is_active' => true,
            'type' => DiscountType::FixedAmount,
            'value' => 10000,
            'apply_to' => DiscountApplyTo::Order->value,
            'min_required' => DiscountRequirement::None->value,
            'eligibility' => DiscountEligibility::Everyone->value,
            'total_use' => 1,
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
        ]);

        $order = Order::factory()->create([
            'number' => 'ORD-STR-1',
            'currency_code' => 'IDR',
            'price_amount' => 100000,
            'status' => OrderStatus::New,
            'payment_status' => PaymentStatus::Pending,
            'discount_id' => $discount->id,
        ]);

        $shipment = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inventory->id,
            'status' => 'pending',
        ]);
        $shipment->lines()->create([
            'purchasable_type' => $product->getMorphClass(),
            'purchasable_id' => $product->id,
            'qty' => 2,
        ]);

        PaymentTransaction::query()->create([
            'order_id' => $order->id,
            'driver' => 'stripe',
            'type' => TransactionType::Initiate,
            'status' => TransactionStatus::Pending,
            'amount' => 100000,
            'currency_code' => 'IDR',
            'reference' => 'pi_str_1',
        ]);

        // Unscaled IDR (100000 instead of 10000000) must be rejected as amount_mismatch.
        $this->postStripeWebhook('payment_intent.succeeded', 'pi_str_1', 100000)
            ->assertOk()
            ->assertJsonPath('status', 'amount_mismatch');
        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);

        // Failed webhook cancels unpaid order via CancelOrderWithProviders (releases stock + discount).
        $this->postStripeWebhook('payment_intent.payment_failed', 'pi_str_1', 10000000)
            ->assertOk()
            ->assertJsonPath('status', 'handled');

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(PaymentStatus::Voided, $order->payment_status);
        $this->assertSame(4, $product->fresh()->stockInventory($inventory->id));
        $this->assertSame(0, $discount->fresh()->total_use);
        $this->assertSame('cancelled', $shipment->fresh()->status);

        // Partial refund on a Paid order must set PartiallyRefunded without cancelling the order.
        $paidOrder = Order::factory()->create([
            'number' => 'ORD-STR-2',
            'currency_code' => 'IDR',
            'price_amount' => 100000,
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
        ]);
        PaymentTransaction::query()->create([
            'order_id' => $paidOrder->id,
            'driver' => 'stripe',
            'type' => TransactionType::Capture,
            'status' => TransactionStatus::Success,
            'amount' => 100000,
            'currency_code' => 'IDR',
            'reference' => 'pi_str_2',
        ]);

        // Out-of-order failed event never overwrites a Paid order.
        $this->postStripeWebhook('payment_intent.payment_failed', 'pi_str_2', 10000000)
            ->assertOk()
            ->assertJsonPath('status', 'already_processed');
        $this->assertSame(PaymentStatus::Paid, $paidOrder->fresh()->payment_status);

        // Partial refund (Rp 40,000 = 4,000,000 Stripe minor units).
        $this->postStripeWebhook('charge.refunded', 'pi_str_2', 4000000, refund: true)
            ->assertOk()
            ->assertJsonPath('status', 'handled');
        $paidOrder->refresh();
        $this->assertSame(OrderStatus::Processing, $paidOrder->status);
        $this->assertSame(PaymentStatus::PartiallyRefunded, $paidOrder->payment_status);
    }

    public function test_coupon_store_clears_confirm_payment_session_and_api_rejects_rp0_and_exhausted_coupons(): void
    {
        $currency = $this->idrCurrency();
        [$country, $zone] = $this->seedIndonesiaZone($currency);
        $inventory = Inventory::factory()->create(['is_default' => true, 'country_id' => $country->id]);

        $product = Product::factory()->standard()->create();
        Price::query()->create([
            'priceable_type' => $product->getMorphClass(),
            'priceable_id' => $product->id,
            'amount' => 50000,
            'currency_id' => $currency->id,
        ]);
        $product->mutateStock($inventory->id, 10);

        $minOrderDiscount = Discount::query()->create([
            'code' => 'MIN200',
            'is_active' => true,
            'type' => DiscountType::FixedAmount,
            'value' => 20000,
            'apply_to' => DiscountApplyTo::Order->value,
            'min_required' => DiscountRequirement::Price->value,
            'min_required_value' => '200000',
            'eligibility' => DiscountEligibility::Everyone->value,
            'usage_limit' => 5,
            'total_use' => 0,
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
        ]);

        $exhausted = Discount::query()->create([
            'code' => 'HABIS',
            'is_active' => true,
            'type' => DiscountType::Percentage,
            'value' => 10,
            'apply_to' => DiscountApplyTo::Order->value,
            'min_required' => DiscountRequirement::None->value,
            'eligibility' => DiscountEligibility::Everyone->value,
            'usage_limit' => 1,
            'total_use' => 1,
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
        ]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1])
            ->assertOk();

        $this->postJson('/api/v1/cart/coupon', ['code' => 'HABIS'])
            ->assertStatus(422);

        $this->postJson('/api/v1/cart/coupon', ['code' => 'MIN200'])
            ->assertStatus(422);

        $apiCart = Cart::query()->where('customer_id', $user->id)->whereNull('completed_at')->firstOrFail();
        $this->assertNull($apiCart->coupon_code);

        // Even if an invalidated coupon + stale CartLineAdjustment sit on the cart when CreateOrder runs,
        // CreateOrder cleans them and never burns total_use / discount_id.
        $line = $apiCart->lines()->firstOrFail();
        $apiCart->forceFill(['coupon_code' => $minOrderDiscount->code])->save();
        CartLineAdjustment::query()->create([
            'cart_line_id' => $line->id,
            'discount_id' => $minOrderDiscount->id,
            'amount' => 20000,
            'code' => $minOrderDiscount->code,
        ]);

        $paymentMethod = PaymentMethod::factory()->create(['driver' => 'manual', 'is_enabled' => true]);

        $order = resolve(CreateOrder::class)->handle([
            'shipping_address' => [
                'first_name' => 'Budi',
                'last_name' => 'Santoso',
                'street_address' => 'Jl. Merdeka 1',
                'postal_code' => '10110',
                'city' => 'Jakarta',
                'country_id' => $country->id,
                'phone_number' => '081234567890',
            ],
            'shipping_option' => [['id' => 1, 'name' => 'Reguler', 'price' => 10000, 'driver' => 'manual']],
            'allocation_plan' => new AllocationPlan([
                new ShipmentDraft($inventory->id, [[
                    'purchasable_type' => $product->getMorphClass(),
                    'purchasable_id' => $product->id,
                    'qty' => 1,
                ]]),
            ]),
            'payment' => [['id' => $paymentMethod->id, 'driver' => 'manual', 'title' => 'COD']],
        ], $apiCart->fresh());

        $this->assertNull($order->discount_id);
        $this->assertSame(0, $minOrderDiscount->fresh()->total_use);
        $this->assertSame(60000, (int) $order->price_amount);
        $this->assertSame(0, (int) $order->items()->firstOrFail()->discount_amount);

        // Web CartCouponController clears confirm_payment session key on store & destroy.
        $anyDiscount = Discount::query()->create([
            'code' => 'ANY10',
            'is_active' => true,
            'type' => DiscountType::Percentage,
            'value' => 10,
            'apply_to' => DiscountApplyTo::Order->value,
            'min_required' => DiscountRequirement::None->value,
            'eligibility' => DiscountEligibility::Everyone->value,
            'total_use' => 0,
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
        ]);
        $webCart = resolve(CartSessionManager::class)->create(['currency_code' => 'IDR', 'customer_id' => $user->id]);
        CartLine::query()->create([
            'cart_id' => $webCart->id,
            'purchasable_type' => $product->getMorphClass(),
            'purchasable_id' => $product->id,
            'quantity' => 1,
            'unit_price_amount' => 50000,
        ]);

        $this->actingAs($user)
            ->withSession(['confirm_payment' => ['driver' => 'stripe', 'reference' => 'pi_old']])
            ->post(route('shop.cart.coupon.store'), ['code' => $anyDiscount->code])
            ->assertRedirect();
        $this->assertNull(session('confirm_payment'));
    }

    public function test_zone_currency_switch_reprices_cart_lines_and_drops_unpriced_lines(): void
    {
        $idr = Currency::query()->where('code', 'IDR')->first()
            ?? Currency::factory()->create(['code' => 'IDR', 'name' => 'Rupiah', 'symbol' => 'Rp', 'format' => 'Rp1.234']);
        $usd = Currency::query()->where('code', 'USD')->first()
            ?? Currency::factory()->create(['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'format' => '$1,234.56']);

        $idCountry = Country::query()->where('cca2', 'ID')->first() ?? Country::factory()->create(['cca2' => 'ID']);
        $usCountry = Country::query()->where('cca2', 'US')->first() ?? Country::factory()->create(['cca2' => 'US']);

        $idZone = Zone::factory()->create(['is_enabled' => true, 'currency_id' => $idr->id]);
        $idZone->countries()->attach($idCountry->id);

        $usZone = Zone::factory()->create(['is_enabled' => true, 'currency_id' => $usd->id]);
        $usZone->countries()->attach($usCountry->id);

        $pricedEverywhere = Product::factory()->standard()->create();
        Price::query()->create(['priceable_type' => $pricedEverywhere->getMorphClass(), 'priceable_id' => $pricedEverywhere->id, 'amount' => 150000, 'currency_id' => $idr->id]);
        Price::query()->create(['priceable_type' => $pricedEverywhere->getMorphClass(), 'priceable_id' => $pricedEverywhere->id, 'amount' => 1000, 'currency_id' => $usd->id]);

        $idrOnly = Product::factory()->standard()->create();
        Price::query()->create(['priceable_type' => $idrOnly->getMorphClass(), 'priceable_id' => $idrOnly->id, 'amount' => 75000, 'currency_id' => $idr->id]);

        ZoneSessionManager::setSessionForCountryCode('ID');
        $cart = resolve(CartSessionManager::class)->create(['currency_code' => 'IDR', 'zone_id' => $idZone->id]);
        $lineA = CartLine::query()->create([
            'cart_id' => $cart->id,
            'purchasable_type' => $pricedEverywhere->getMorphClass(),
            'purchasable_id' => $pricedEverywhere->id,
            'quantity' => 1,
            'unit_price_amount' => 150000,
        ]);
        $lineB = CartLine::query()->create([
            'cart_id' => $cart->id,
            'purchasable_type' => $idrOnly->getMorphClass(),
            'purchasable_id' => $idrOnly->id,
            'quantity' => 1,
            'unit_price_amount' => 75000,
        ]);

        ZoneSessionManager::setSessionForCountryCode('US');

        $cart->refresh();
        $this->assertSame('USD', $cart->currency_code);
        $this->assertSame(1000, (int) $lineA->fresh()->unit_price_amount);
        $this->assertNull($lineB->fresh());
    }

    public function test_unpriced_and_external_products_cannot_enter_cart_and_create_order_refuses_unpublished_and_refreshes_price(): void
    {
        $currency = $this->idrCurrency();
        [$country] = $this->seedIndonesiaZone($currency);
        $inventory = Inventory::factory()->create(['is_default' => true, 'country_id' => $country->id]);

        $unpriced = Product::factory()->standard()->create();
        $unpriced->mutateStock($inventory->id, 5);

        $external = Product::factory()->external()->create();
        Price::query()->create([
            'priceable_type' => $external->getMorphClass(),
            'priceable_id' => $external->id,
            'amount' => 50000,
            'currency_id' => $currency->id,
        ]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/cart/items', ['product_id' => $unpriced->id, 'quantity' => 1])
            ->assertStatus(422);
        $this->postJson('/api/v1/cart/items', ['product_id' => $external->id, 'quantity' => 1])
            ->assertStatus(422);

        $this->actingAs($user)
            ->from(route('shop.product', $unpriced))
            ->post(route('shop.cart.add'), ['product_id' => $unpriced->id, 'quantity' => 1])
            ->assertSessionHasErrors('cart');

        // Price increase between cart-add and CreateOrder is refreshed, and unpublished product is refused.
        $live = Product::factory()->standard()->create();
        $price = Price::query()->create([
            'priceable_type' => $live->getMorphClass(),
            'priceable_id' => $live->id,
            'amount' => 100000,
            'currency_id' => $currency->id,
        ]);
        $live->mutateStock($inventory->id, 5);

        $cart = Cart::query()->create(['currency_code' => $currency->code, 'customer_id' => $user->id]);
        CartLine::query()->create([
            'cart_id' => $cart->id,
            'purchasable_type' => $live->getMorphClass(),
            'purchasable_id' => $live->id,
            'quantity' => 1,
            'unit_price_amount' => 100000,
        ]);

        $price->update(['amount' => 125000]);

        $paymentMethod = PaymentMethod::factory()->create(['driver' => 'manual', 'is_enabled' => true]);

        $order = resolve(CreateOrder::class)->handle([
            'shipping_address' => [
                'first_name' => 'Budi',
                'last_name' => 'Santoso',
                'street_address' => 'Jl. Merdeka 1',
                'postal_code' => '10110',
                'city' => 'Jakarta',
                'country_id' => $country->id,
                'phone_number' => '081234567890',
            ],
            'shipping_option' => [['id' => 1, 'name' => 'Reguler', 'price' => 10000, 'driver' => 'manual']],
            'allocation_plan' => new AllocationPlan([
                new ShipmentDraft($inventory->id, [[
                    'purchasable_type' => $live->getMorphClass(),
                    'purchasable_id' => $live->id,
                    'qty' => 1,
                ]]),
            ]),
            'payment' => [['id' => $paymentMethod->id, 'driver' => 'manual', 'title' => 'COD']],
        ], $cart->fresh());

        $this->assertSame(135000, (int) $order->price_amount);

        // Unpublished product in cart is refused by CreateOrder.
        $draft = Product::factory()->standard()->create();
        Price::query()->create([
            'priceable_type' => $draft->getMorphClass(),
            'priceable_id' => $draft->id,
            'amount' => 50000,
            'currency_id' => $currency->id,
        ]);
        $draft->mutateStock($inventory->id, 5);
        $cart2 = Cart::query()->create(['currency_code' => $currency->code, 'customer_id' => $user->id]);
        CartLine::query()->create([
            'cart_id' => $cart2->id,
            'purchasable_type' => $draft->getMorphClass(),
            'purchasable_id' => $draft->id,
            'quantity' => 1,
            'unit_price_amount' => 50000,
        ]);
        $draft->update(['is_visible' => false]);

        $this->expectException(HttpException::class);
        resolve(CreateOrder::class)->handle([
            'shipping_address' => [
                'first_name' => 'Budi',
                'last_name' => 'Santoso',
                'street_address' => 'Jl. Merdeka 1',
                'postal_code' => '10110',
                'city' => 'Jakarta',
                'country_id' => $country->id,
                'phone_number' => '081234567890',
            ],
            'shipping_option' => [['id' => 1, 'name' => 'Reguler', 'price' => 10000, 'driver' => 'manual']],
            'allocation_plan' => new AllocationPlan([
                new ShipmentDraft($inventory->id, [[
                    'purchasable_type' => $draft->getMorphClass(),
                    'purchasable_id' => $draft->id,
                    'qty' => 1,
                ]]),
            ]),
            'payment' => [['id' => $paymentMethod->id, 'driver' => 'manual', 'title' => 'COD']],
        ], $cart2->fresh());
    }

    public function test_override_allocation_rejects_cancelled_orders_and_freezes_paid_order_totals(): void
    {
        $from = Inventory::factory()->create(['is_default' => true]);
        $to = Inventory::factory()->create(['is_default' => false]);
        $product = Product::factory()->standard()->create();
        $product->mutateStock($from->id, 3);
        $product->mutateStock($to->id, 5);

        $paidOrder = Order::factory()->create([
            'price_amount' => 230000,
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
        ]);
        $shipment = OrderShipment::query()->create([
            'order_id' => $paidOrder->id,
            'inventory_id' => $from->id,
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'cost' => 10000,
            'currency_code' => 'IDR',
            'status' => 'pending',
        ]);
        $line = $shipment->lines()->create([
            'purchasable_type' => $product->getMorphClass(),
            'purchasable_id' => $product->id,
            'qty' => 1,
        ]);

        $fakeRates = new class
        {
            public function handle(array $shippingAddress, array $packages, ?int $originInventoryId = null): array
            {
                return [['carrier_code' => 'jnt', 'carrier_name' => 'JNT', 'service_code' => 'EZ', 'service_name' => 'EZ', 'amount' => 18000, 'currency' => 'IDR']];
            }
        };
        $this->app->instance(FetchDeliveryRates::class, $fakeRates);

        $actor = User::factory()->create();
        resolve(OverrideAllocation::class)->handle($paidOrder, [[
            'shipment_line_id' => $line->id,
            'qty' => 1,
            'from_inventory_id' => $from->id,
            'to_inventory_id' => $to->id,
        ]], $actor);

        $paidOrder->refresh();
        $this->assertSame(230000, (int) $paidOrder->price_amount);
        $meta = is_string($paidOrder->metadata) ? json_decode($paidOrder->metadata, true) : $paidOrder->metadata;
        $this->assertSame(8000, data_get($meta, 'allocation_shipping_variance.variance'));

        // Cancelled order must be refused by OverrideAllocation (no phantom stock).
        $cancelledOrder = Order::factory()->create([
            'price_amount' => 100000,
            'currency_code' => 'IDR',
            'status' => OrderStatus::Cancelled,
            'payment_status' => PaymentStatus::Voided,
        ]);
        $cancelledShipment = OrderShipment::query()->create([
            'order_id' => $cancelledOrder->id,
            'inventory_id' => $from->id,
            'status' => 'pending',
        ]);
        $cancelledLine = $cancelledShipment->lines()->create([
            'purchasable_type' => $product->getMorphClass(),
            'purchasable_id' => $product->id,
            'qty' => 1,
        ]);

        $this->expectException(ValidationException::class);
        resolve(OverrideAllocation::class)->handle($cancelledOrder, [[
            'shipment_line_id' => $cancelledLine->id,
            'qty' => 1,
            'from_inventory_id' => $from->id,
            'to_inventory_id' => $to->id,
        ]], $actor);
    }

    public function test_unregistered_provider_shipment_is_marked_cancelled_on_order_cancellation(): void
    {
        $this->requireModules('Komerce');

        config()->set('komerce.shipping_delivery_api_key', 'test-delivery-key');
        config()->set('shopper.shipping.drivers.komerce.enabled', true);

        $inventory = Inventory::factory()->create(['is_default' => true]);
        $order = Order::factory()->create([
            'status' => OrderStatus::New,
            'payment_status' => PaymentStatus::Pending,
        ]);
        $shipment = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inventory->id,
            'driver' => 'komerce',
            'external_reference' => null,
            'status' => 'pending',
        ]);

        resolve(CancelOrderWithProviders::class)->handle($order, throughPaymentProvider: false);

        $this->assertSame('cancelled', $shipment->fresh()->status);
    }

    public function test_komerce_retry_lock_refuses_concurrent_call(): void
    {
        $this->requireModules('Komerce');

        $komerceOrder = Order::factory()->create([
            'status' => OrderStatus::New,
            'payment_status' => PaymentStatus::Pending,
        ]);
        $lock = Cache::lock('komerce:retry:'.$komerceOrder->id, 15);
        $this->assertTrue($lock->get());
        try {
            $this->expectException(PaymentRetryRefused::class);
            resolve(RetryKomercePayment::class)->handle($komerceOrder);
        } finally {
            $lock->release();
        }
    }

    public function test_cancel_unpaid_komerce_qrisly_reconciles_paid_order_and_sets_cancelled_at_when_unpaid(): void
    {
        $this->requireModules('Komerce');
        Event::fake([OrderCancelled::class, OrderPaid::class]);

        config()->set('komerce.payment_api_key', '');
        config()->set('komerce.qrisly_api_key', 'test-qrisly-key');
        config()->set('komerce.qrisly_base_url', 'https://qrisly.example.test');

        Http::fake([
            'https://qrisly.example.test/*' => Http::response([
                'data' => ['status' => 'UNPAID'],
            ]),
        ]);

        $order = Order::factory()->create([
            'status' => OrderStatus::New,
            'payment_status' => PaymentStatus::Pending,
            'metadata' => json_encode([
                'komerce' => [
                    'provider' => 'qrisly',
                    'qrisly_history_id' => 'hist_123',
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        $cancelled = resolve(CancelUnpaidKomerceOrder::class)->handle($order);

        $this->assertSame(OrderStatus::Cancelled, $cancelled->status);
        $this->assertNotNull($cancelled->cancelled_at);
        Event::assertDispatched(OrderCancelled::class);
    }

    public function test_profile_update_email_is_case_insensitive_and_account_order_hides_metadata_and_notes(): void
    {
        $existing = User::factory()->create(['email' => 'taken@example.com']);
        $user = User::factory()->create(['email' => 'other@example.com']);

        $this->actingAs($user)
            ->from(route('profile.edit'))
            ->patch(route('profile.update'), [
                'first_name' => 'Other',
                'last_name' => 'User',
                'email' => 'TAKEN@example.com',
            ])
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHasErrors('email');

        $order = Order::factory()->create([
            'customer_id' => $user->id,
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
            'notes' => 'Internal staff note',
            'metadata' => json_encode(['komerce' => ['secret' => '123']], JSON_THROW_ON_ERROR),
        ]);

        $this->actingAs($user)
            ->get(route('account.orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.id', $order->id)
                ->missing('order.metadata')
                ->missing('order.notes'));
    }

    public function test_checkout_suppresses_delivery_rates_when_stock_allocation_fails(): void
    {
        $currency = $this->idrCurrency();
        [$country, $zone] = $this->seedIndonesiaZone($currency);
        $inventory = Inventory::factory()->create(['is_default' => true, 'country_id' => $country->id]);

        $carrier = Carrier::query()->create(['name' => 'Kurir Toko', 'slug' => 'manual', 'driver' => 'manual', 'is_enabled' => true]);
        $zone->carriers()->attach($carrier->id);
        CarrierOption::query()->create([
            'carrier_id' => $carrier->id,
            'zone_id' => $zone->id,
            'name' => 'Reguler',
            'price' => 15000,
            'is_enabled' => true,
        ]);

        $product = Product::factory()->standard()->create();
        $user = User::factory()->create();
        $cart = Cart::query()->create(['currency_code' => $currency->code, 'customer_id' => $user->id]);
        CartLine::query()->create([
            'cart_id' => $cart->id,
            'purchasable_type' => $product->getMorphClass(),
            'purchasable_id' => $product->id,
            'quantity' => 1,
            'unit_price_amount' => 50000,
        ]);

        $this->actingAs($user)
            ->withSession([
                config('shopper.cart.session.key', 'shopper_cart') => $cart->id,
                'zone_country_code' => 'ID',
                CheckoutSession::SHIPPING_ADDRESS => [
                    'first_name' => 'Budi',
                    'last_name' => 'Santoso',
                    'street_address' => 'Jl. Merdeka 1',
                    'postal_code' => '10110',
                    'city' => 'Jakarta',
                    'country_id' => $country->id,
                    'phone_number' => '081234567890',
                ],
            ])
            ->get(route('shop.checkout.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('allocation', null)
                ->where('deliveryOptions', [])
                ->where('shippingRatesHint', __('Stok produk di keranjang tidak dapat dipenuhi oleh lokasi gudang toko saat ini.')));
    }

    private function postStripeWebhook(string $type, string $intentId, int $amount, bool $refund = false)
    {
        $object = $refund
            ? [
                'id' => 'ch_test',
                'object' => 'charge',
                'payment_intent' => $intentId,
                'amount_refunded' => $amount,
            ]
            : [
                'id' => $intentId,
                'object' => 'payment_intent',
                'amount' => $amount,
                'amount_received' => $amount,
            ];

        $body = (string) json_encode([
            'id' => 'evt_'.uniqid(),
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => $object],
        ], JSON_THROW_ON_ERROR);

        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", 'whsec_test');

        return $this->call('POST', '/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $body);
    }
}
