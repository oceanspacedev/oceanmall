<?php

declare(strict_types=1);

namespace Tests\Feature\Checkout;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Shopper\Core\Models\Order;
use Tests\TestCase;

/**
 * Links to the removed Stripe payment page (/checkout/payment/{number}) in
 * emails and browser history keep working.
 */
final class LegacyPaymentLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_payment_page_link_redirects_the_owner_to_the_order(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['customer_id' => $user->id, 'number' => 'ORD-LEGACY-1']);

        $this->actingAs($user)
            ->get('/checkout/payment/ORD-LEGACY-1')
            ->assertRedirect(route('shop.checkout.success', ['order' => $order->id]));
    }

    public function test_another_customers_order_is_not_found(): void
    {
        Order::factory()->create(['customer_id' => User::factory()->create()->id, 'number' => 'ORD-LEGACY-2']);

        $this->actingAs(User::factory()->create())
            ->get('/checkout/payment/ORD-LEGACY-2')
            ->assertNotFound();
    }
}
