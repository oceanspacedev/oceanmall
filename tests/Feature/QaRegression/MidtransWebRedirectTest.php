<?php

declare(strict_types=1);

namespace Tests\Feature\QaRegression;

use App\Actions\Checkout\FetchPaymentMethods;
use App\Actions\CreateOrder;
use App\CheckoutSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Order;
use Tests\Feature\Midtrans\InteractsWithMidtrans;
use Tests\Feature\Xendit\Concerns\FakesXendit;
use Tests\TestCase;

/**
 * Storefront "Place order" is an Inertia XHR (checkout.vue paymentForm.post).
 * A hosted payment page (Midtrans Snap, Xendit invoice) must be answered with
 * 409 + X-Inertia-Location so the client leaves the SPA; an external 302 is
 * followed by the XHR itself and is CORS-blocked, leaving the customer on
 * checkout with an emptied cart. Plain (non-Inertia) requests keep the 302.
 */
final class MidtransWebRedirectTest extends TestCase
{
    use FakesXendit;
    use InteractsWithMidtrans;
    use RefreshDatabase;

    private const SNAP_REDIRECT = 'https://app.sandbox.midtrans.com/snap/v4/redirection/snap-token-1';

    private const XENDIT_INVOICE = 'https://checkout-staging.xendit.co/web/inv_new_1';

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
    private function arrangeMidtrans(): array
    {
        $this->withMidtrans();
        Http::fake([self::SNAP_URL => Http::response(['token' => 'snap-token-1', 'redirect_url' => self::SNAP_REDIRECT], 201)]);

        $user = $this->customer();
        $order = $this->midtransOrder('bca_va', ['customer_id' => $user->id]);

        $this->stubCheckout($order, ['id' => $order->payment_method_id, 'driver' => 'midtrans', 'channel_code' => 'bca_va', 'payment_type' => 'bank_transfer']);

        return [$user, $order];
    }

    /**
     * @return array{0: User, 1: Order}
     */
    private function arrangeXendit(): array
    {
        $this->configureXendit();
        $this->fakeXenditApi();

        $user = $this->customer();
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

    private function customer(): User
    {
        Mail::fake();
        Notification::fake();

        return User::factory()->create([
            'email' => 'buyer@example.com',
            'first_name' => 'Budi',
            'last_name' => 'Santoso',
            'phone_number' => '0812-3456-7890',
            'email_verified_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $selection
     */
    private function stubCheckout(Order $order, array $selection): void
    {
        $this->app->instance(FetchPaymentMethods::class, new class($selection)
        {
            /** @param array<string, mixed> $selection */
            public function __construct(private array $selection) {}

            /** @return list<array<string, mixed>> */
            public function handle(int $countryId): array
            {
                return [$this->selection];
            }
        });

        $this->app->instance(CreateOrder::class, new class($order)
        {
            public function __construct(private Order $order) {}

            public function handle(): Order
            {
                return $this->order;
            }
        });
    }

    private function placeOrder(User $user, Order $order, bool $inertia): TestResponse
    {
        $request = $this->actingAs($user)
            ->withSession([CheckoutSession::SHIPPING_ADDRESS => ['country_id' => 1]]);

        if ($inertia) {
            $request = $request->withHeaders([
                'X-Inertia' => 'true',
                'X-Requested-With' => 'XMLHttpRequest',
                'Accept' => 'text/html, application/xhtml+xml',
            ]);
        }

        return $request->post(route('shop.checkout.place-order'), ['payment_method_id' => $order->payment_method_id]);
    }
}
