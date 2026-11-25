<?php

declare(strict_types=1);

namespace Tests\Feature\Midtrans;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Shopper\Core\Models\Order;
use Tests\Support\PlacesHostedPaymentOrders;
use Tests\TestCase;

/**
 * Placing an order on the web leaves the SPA for Midtrans Snap (409 +
 * X-Inertia-Location for Inertia, 302 otherwise); see PlacesHostedPaymentOrders.
 */
final class MidtransWebRedirectTest extends TestCase
{
    use InteractsWithMidtrans;
    use PlacesHostedPaymentOrders;
    use RefreshDatabase;

    private const SNAP_REDIRECT = 'https://app.sandbox.midtrans.com/snap/v4/redirection/snap-token-1';

    public function test_inertia_place_order_leaves_the_spa_for_midtrans_snap(): void
    {
        [$user, $order] = $this->arrangeMidtrans();

        $response = $this->placeOrder($user, $order, inertia: true);

        $response->assertStatus(409);
        $response->assertHeader('X-Inertia-Location', self::SNAP_REDIRECT);
        $this->assertNull($response->headers->get('Location'));
    }

    public function test_plain_place_order_still_redirects_to_midtrans_snap(): void
    {
        [$user, $order] = $this->arrangeMidtrans();

        $this->placeOrder($user, $order, inertia: false)
            ->assertRedirect(self::SNAP_REDIRECT)
            ->assertHeaderMissing('X-Inertia-Location');
    }

    /**
     * @return array{0: User, 1: Order}
     */
    private function arrangeMidtrans(): array
    {
        $this->withMidtrans();
        Http::fake([self::SNAP_URL => Http::response(['token' => 'snap-token-1', 'redirect_url' => self::SNAP_REDIRECT], 201)]);

        $user = $this->hostedPaymentCustomer();
        $order = $this->midtransOrder('bca_va', ['customer_id' => $user->id]);

        $this->stubCheckout($order, ['id' => $order->payment_method_id, 'driver' => 'midtrans', 'channel_code' => 'bca_va', 'payment_type' => 'bank_transfer']);

        return [$user, $order];
    }
}
