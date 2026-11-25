<?php

declare(strict_types=1);

namespace Tests\Feature\Xendit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Order;
use Tests\Feature\Xendit\Concerns\FakesXendit;
use Tests\Support\PlacesHostedPaymentOrders;
use Tests\TestCase;

/**
 * Placing an order on the web leaves the SPA for the Xendit invoice (409 +
 * X-Inertia-Location for Inertia, 302 otherwise); see PlacesHostedPaymentOrders.
 */
final class XenditWebRedirectTest extends TestCase
{
    use FakesXendit;
    use PlacesHostedPaymentOrders;
    use RefreshDatabase;

    private const XENDIT_INVOICE = 'https://checkout-staging.xendit.co/web/inv_new_1';

    public function test_inertia_place_order_leaves_the_spa_for_the_xendit_invoice(): void
    {
        [$user, $order] = $this->arrangeXendit();

        $response = $this->placeOrder($user, $order, inertia: true);

        $response->assertStatus(409);
        $response->assertHeader('X-Inertia-Location', self::XENDIT_INVOICE);
        $this->assertNull($response->headers->get('Location'));
    }

    public function test_plain_place_order_still_redirects_to_the_xendit_invoice(): void
    {
        [$user, $order] = $this->arrangeXendit();

        $this->placeOrder($user, $order, inertia: false)
            ->assertRedirect(self::XENDIT_INVOICE)
            ->assertHeaderMissing('X-Inertia-Location');
    }

    /**
     * @return array{0: User, 1: Order}
     */
    private function arrangeXendit(): array
    {
        $this->configureXendit();
        $this->fakeXenditApi();

        $user = $this->hostedPaymentCustomer();
        $order = Order::factory()->create([
            'number' => 'ORD-2001',
            'price_amount' => 125000,
            'currency_code' => 'IDR',
            'status' => OrderStatus::New,
            'payment_status' => PaymentStatus::Pending,
            'payment_method_id' => $this->xenditPaymentMethod('BCA')->id,
            'customer_id' => $user->id,
        ]);

        $this->stubCheckout($order, ['id' => $order->payment_method_id, 'driver' => 'xendit', 'channel_code' => 'BCA', 'payment_type' => 'bank_transfer']);

        return [$user, $order];
    }
}
