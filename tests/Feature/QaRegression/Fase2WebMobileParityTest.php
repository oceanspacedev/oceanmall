<?php

declare(strict_types=1);

namespace Tests\Feature\QaRegression;

use App\Actions\Checkout\InitiateOrderPayment;
use App\Actions\Warehouse\SuggestAllocation;
use App\Listeners\ReleaseDiscountOnOrderCancelled;
use App\Models\DiscountRedemption;
use App\Models\Product;
use App\Models\User;
use App\Notifications\QueuedVerifyEmail;
use App\Payment\PaymentReturnUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Shopper\Cart\Models\Cart;
use Shopper\Core\Enum\DiscountApplyTo;
use Shopper\Core\Enum\DiscountEligibility;
use Shopper\Core\Enum\DiscountRequirement;
use Shopper\Core\Enum\DiscountType;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Enum\ShippingStatus;
use Shopper\Core\Events\Orders\OrderCancelled;
use Shopper\Core\Models\Country;
use Shopper\Core\Models\Currency;
use Shopper\Core\Models\Discount;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Core\Models\Zone;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class Fase2WebMobileParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_unverified_mobile_user_cannot_checkout_and_can_resend_verification(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create(['email' => 'unverified-api@example.com']);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/checkout/place-order', ['payment_method_id' => 1])
            ->assertForbidden();

        $this->postJson('/api/v1/auth/email/verification-notification')
            ->assertOk();

        Notification::assertSentTo($user, QueuedVerifyEmail::class);
    }

    public function test_delete_account_blocks_staff_and_active_orders_and_deletes_eligible_customer(): void
    {
        $this->configureShopperCpanel();
        Role::query()->firstOrCreate(['name' => config('shopper.admin.roles.admin'), 'guard_name' => 'web']);

        $staff = User::factory()->create(['email' => 'staff-del@example.com']);
        $staff->assignRole(config('shopper.admin.roles.admin'));

        $this->actingAs($staff)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasErrors('account');
        $this->assertNotNull($staff->fresh());

        $activeCustomer = User::factory()->create(['email' => 'active-del@example.com']);
        Order::factory()->create([
            'customer_id' => $activeCustomer->id,
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
            'currency_code' => 'IDR',
        ]);

        $this->actingAs($activeCustomer)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasErrors('account');
        $this->assertNotNull($activeCustomer->fresh());

        $eligibleCustomer = User::factory()->create(['email' => 'clean-del@example.com']);
        $this->actingAs($eligibleCustomer)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('home'));
        $this->assertNull($eligibleCustomer->fresh());
    }

    public function test_per_user_coupon_redemption_tracks_and_releases_on_unpaid_cancel(): void
    {
        $discount = Discount::query()->create([
            'is_active' => true,
            'code' => 'HEMAT1X',
            'type' => DiscountType::FixedAmount,
            'value' => 10000,
            'apply_to' => DiscountApplyTo::Order,
            'eligibility' => DiscountEligibility::Everyone,
            'min_required' => DiscountRequirement::None,
            'start_at' => now()->subDay(),
            'end_at' => now()->addDay(),
            'usage_limit_per_user' => true,
        ]);

        $user = User::factory()->create(['email' => 'PromoUser@Example.COM']);
        $order = Order::factory()->create([
            'customer_id' => $user->id,
            'discount_id' => $discount->id,
            'status' => OrderStatus::New,
            'payment_status' => PaymentStatus::Pending,
            'shipping_status' => ShippingStatus::Unfulfilled,
            'currency_code' => 'IDR',
        ]);

        DiscountRedemption::record($discount->id, $order->id, (string) $user->email);
        $this->assertTrue(DiscountRedemption::hasActiveRedemption($discount->id, 'promouser@example.com'));

        resolve(ReleaseDiscountOnOrderCancelled::class)->handle(
            new OrderCancelled($order),
        );

        $this->assertFalse(DiscountRedemption::hasActiveRedemption($discount->id, 'promouser@example.com'));
    }

    public function test_suggest_allocation_supports_backorder_products_and_return_url_does_not_dirty_order(): void
    {
        $country = Country::factory()->create(['cca2' => 'ID']);
        Zone::factory()->create(['is_enabled' => true]);
        Currency::query()->firstOrCreate(
            ['code' => shopper_currency()],
            ['name' => 'Rupiah', 'symbol' => 'Rp', 'format' => '1,234.56'],
        );
        $inventory = Inventory::factory()->create(['is_default' => true]);

        $product = Product::factory()->standard()->create([
            'published_at' => now()->subDay(),
            'allow_backorder' => true,
        ]);
        $cart = Cart::query()->create(['currency_code' => shopper_currency()]);
        $cart->lines()->create([
            'purchasable_type' => $product->getMorphClass(),
            'purchasable_id' => $product->id,
            'quantity' => 2,
            'unit_price_amount' => 50000,
        ]);

        $plan = resolve(SuggestAllocation::class)->handle($cart, ['country_id' => $country->id]);
        $this->assertCount(1, $plan->shipments);
        $this->assertSame($inventory->id, $plan->shipments[0]->inventory_id);

        $manualMethod = PaymentMethod::factory()->create([
            'title' => 'Manual',
            'slug' => 'manual',
            'driver' => 'manual',
            'is_enabled' => true,
        ]);
        $order = Order::factory()->create([
            'payment_method_id' => $manualMethod->id,
            'price_amount' => 100000,
            'currency_code' => shopper_currency(),
            'status' => OrderStatus::New,
            'payment_status' => PaymentStatus::Pending,
            'shipping_status' => ShippingStatus::Unfulfilled,
        ]);

        resolve(InitiateOrderPayment::class)->handle($order, [], [
            'return_url' => route('payment.return.mobile', ['order' => $order->number]),
        ]);

        $this->assertNull(PaymentReturnUrl::get($order));
        $this->assertArrayNotHasKey('payment_return_url', $order->getAttributes());
    }
}
