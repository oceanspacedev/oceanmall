<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Checkout\FetchPaymentMethods;
use App\Actions\CreateOrder;
use App\CheckoutSession;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Shopper\Core\Models\Order;

/**
 * Storefront "Place order" is an Inertia XHR (checkout.vue paymentForm.post).
 * A hosted payment page (Midtrans Snap, Xendit invoice) must be answered with
 * 409 + X-Inertia-Location so the client leaves the SPA; an external 302 is
 * followed by the XHR itself and is CORS-blocked, leaving the customer on
 * checkout with an emptied cart. Plain (non-Inertia) requests keep the 302.
 *
 * Provider-neutral helpers; each gateway module has its own
 * <Module>WebRedirectTest.
 */
trait PlacesHostedPaymentOrders
{
    protected function hostedPaymentCustomer(): User
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
     * Checkout resolves exactly this payment selection and "creates" $order.
     *
     * @param  array<string, mixed>  $selection
     */
    protected function stubCheckout(Order $order, array $selection): void
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

    protected function placeOrder(User $user, Order $order, bool $inertia): TestResponse
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
