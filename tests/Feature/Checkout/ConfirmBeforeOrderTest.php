<?php

declare(strict_types=1);

namespace Tests\Feature\Checkout;

use App\Models\Product;
use App\Models\User;
use App\Payment\Contracts\ConfirmsBeforeOrder;
use App\Payment\Drivers\ConfirmFirstStripeDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Shopper\Cart\Models\Cart;
use Shopper\Cart\Models\CartLine;
use Shopper\Core\Enum\PaymentStatus;
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
use Shopper\Payment\DataTransferObjects\PaymentResult;
use Shopper\Payment\DataTransferObjects\WebhookResult;
use Shopper\Payment\Drivers\Driver;
use Shopper\Payment\Facades\Payment;
use Shopper\Payment\Models\PaymentTransaction;
use Tests\TestCase;

/**
 * Goal (b) for embedded-form gateways: any driver implementing
 * ConfirmsBeforeOrder (Stripe through ConfirmFirstStripeDriver, or e.g. a
 * Xendit cards driver) gets the confirm-first web checkout with no edit to
 * the generic checkout controller.
 */
final class ConfirmBeforeOrderTest extends TestCase
{
    use RefreshDatabase;

    private CardPayDriver $driver;

    private PaymentMethod $card;

    private CarrierOption $option;

    private Inventory $inventory;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake();

        $this->driver = new CardPayDriver;
        $driver = $this->driver;
        Payment::extend('cardpay', static fn (): CardPayDriver => $driver);
        config()->set('shopper.payment.drivers.cardpay.enabled', true);

        $manifest = public_path('build/manifest.json');
        $this->withHeader('X-Inertia-Version', file_exists($manifest) ? hash_file('xxh128', $manifest) : '');

        $currency = Currency::query()->where('code', 'IDR')->first()
            ?? Currency::factory()->create(['code' => 'IDR', 'name' => 'Rupiah', 'symbol' => 'Rp', 'format' => 'Rp1.234']);
        $country = Country::factory()->create(['cca2' => 'ID']);
        $zone = Zone::factory()->create(['is_enabled' => true, 'currency_id' => $currency->id]);
        $zone->countries()->attach($country->id);

        $carrier = Carrier::query()->create(['name' => 'Kurir Toko', 'slug' => 'manual', 'driver' => 'manual', 'is_enabled' => true]);
        $zone->carriers()->attach($carrier->id);
        $this->option = CarrierOption::query()->create([
            'carrier_id' => $carrier->id,
            'zone_id' => $zone->id,
            'name' => 'Reguler',
            'price' => 15000,
            'is_enabled' => true,
        ]);

        $this->card = PaymentMethod::factory()->create(['title' => 'Kartu', 'slug' => 'kartu', 'driver' => 'cardpay', 'is_enabled' => true]);
        $zone->paymentMethods()->attach($this->card->id);

        $this->inventory = Inventory::factory()->create(['is_default' => true, 'country_id' => $country->id]);
    }

    public function test_stripe_is_registered_with_the_confirm_first_contract(): void
    {
        $this->assertInstanceOf(ConfirmFirstStripeDriver::class, Payment::driver('stripe'));
        $this->assertInstanceOf(ConfirmsBeforeOrder::class, Payment::driver('stripe'));
        $this->assertSame('stripe', Payment::driver('stripe')->code());
    }

    public function test_any_confirm_first_driver_runs_the_embedded_flow_without_checkout_edits(): void
    {
        Event::fake([OrderPaid::class]);
        $this->checkoutUntilPayment();

        $this->get(route('shop.checkout.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('paymentOptions.0.driver', 'cardpay')
                ->where('paymentOptions.0.confirm_in_page', true)
                ->where('paymentAction', null));

        $this->post(route('shop.checkout.prepare-payment'), ['payment_method_id' => $this->card->id])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('shop.checkout.index', ['step' => 3]));

        $this->assertSame([50000 + 15000], $this->driver->preparedAmounts);

        $this->get(route('shop.checkout.index', ['step' => 3]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('paymentAction.driver', 'cardpay')
                ->where('paymentAction.kind', 'client_secret')
                ->where('paymentAction.data.client_secret', 'cs_card_1')
                ->where('paymentAction.data.return_url', route('shop.checkout.confirm-return', ['driver' => 'cardpay'])));

        // The order is never created before the provider confirms.
        $this->post(route('shop.checkout.place-order'), ['payment_method_id' => $this->card->id])
            ->assertSessionHasErrors('payment');
        $this->assertSame(0, Order::query()->count());

        // A reference that is not the prepared one is refused.
        $this->get(route('shop.checkout.confirm-return', ['driver' => 'cardpay', 'ref' => 'card_other']))
            ->assertRedirect(route('shop.checkout.index'))
            ->assertSessionHasErrors('payment');
        $this->assertSame(0, Order::query()->count());

        $response = $this->get(route('shop.checkout.confirm-return', ['driver' => 'cardpay', 'ref' => 'card_1']));

        $order = Order::query()->sole();
        $response->assertRedirect(route('shop.checkout.success', ['order' => $order->id]));
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame([$order->id => 'card_1'], $this->driver->attached);
        Event::assertDispatchedTimes(OrderPaid::class, 1);

        // The provider redirecting twice does not create a second order.
        $this->get(route('shop.checkout.confirm-return', ['driver' => 'cardpay', 'ref' => 'card_1']));
        $this->assertSame(1, Order::query()->count());
    }

    public function test_an_unconfirmed_payment_sends_the_customer_back_to_the_payment_step(): void
    {
        $this->driver->status = null;
        $this->checkoutUntilPayment();
        $this->post(route('shop.checkout.prepare-payment'), ['payment_method_id' => $this->card->id]);

        $this->get(route('shop.checkout.confirm-return', ['driver' => 'cardpay', 'ref' => 'card_1']))
            ->assertRedirect(route('shop.checkout.index', ['step' => 3]))
            ->assertSessionHasErrors('payment');

        $this->assertSame(0, Order::query()->count());
    }

    private function checkoutUntilPayment(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->standard()->create(['published_at' => now()->subDay(), 'weight_value' => 0.5, 'weight_unit' => 'kg']);
        $product->mutateStock($this->inventory->id, 10);
        $currency = Currency::query()->where('code', shopper_currency())->first()
            ?? Currency::factory()->create(['code' => shopper_currency(), 'name' => shopper_currency(), 'symbol' => shopper_currency(), 'format' => '1,234.56']);
        Price::query()->create([
            'priceable_type' => $product->getMorphClass(),
            'priceable_id' => $product->id,
            'amount' => 50000,
            'currency_id' => $currency->id,
        ]);

        $cart = Cart::query()->create(['currency_code' => 'IDR', 'customer_id' => $user->id]);
        CartLine::query()->create([
            'cart_id' => $cart->id,
            'purchasable_type' => $product->getMorphClass(),
            'purchasable_id' => $product->id,
            'quantity' => 1,
            'unit_price_amount' => 50000,
        ]);

        $this->actingAs($user)->withSession([
            config('shopper.cart.session.key', 'shopper_cart') => $cart->id,
            'zone_country_code' => 'ID',
        ]);

        $this->post(route('shop.checkout.shipping-address'), [
            'first_name' => 'Budi',
            'last_name' => 'Santoso',
            'street_address' => 'Jl. Merdeka 1',
            'postal_code' => '45153',
            'city' => 'Cirebon',
            'phone_number' => '081234567890',
        ])->assertSessionHasNoErrors();

        $this->post(route('shop.checkout.shipping-option'), ['service_code' => (string) $this->option->id])
            ->assertSessionHasNoErrors();
    }
}

/**
 * An embedded card gateway as a third party would add it.
 */
final class CardPayDriver extends Driver implements ConfirmsBeforeOrder
{
    /** @var list<int> */
    public array $preparedAmounts = [];

    /** @var array<int, string> */
    public array $attached = [];

    public ?PaymentStatus $status = PaymentStatus::Paid;

    public function code(): string
    {
        return 'cardpay';
    }

    public function name(): string
    {
        return 'CardPay';
    }

    public function logo(): ?string
    {
        return null;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function supportsWebhooks(): bool
    {
        return false;
    }

    public function supportsRefunds(): bool
    {
        return false;
    }

    public function initiatePayment(int $amount, string $currency, array $context = []): PaymentResult
    {
        return new PaymentResult(success: true, status: 'pending', reference: 'card_1', clientSecret: 'cs_card_1', amount: $amount);
    }

    public function handleWebhook(array $payload, array $headers = []): WebhookResult
    {
        return WebhookResult::ignored();
    }

    public function prepareBeforeOrder(int $amount, string $currency, array $context = []): array
    {
        $this->preparedAmounts[] = $amount;

        return ['reference' => 'card_1', 'data' => ['client_secret' => 'cs_card_1']];
    }

    public function returnReference(Request $request): ?string
    {
        $ref = $request->query('ref');

        return is_string($ref) && $ref !== '' ? $ref : null;
    }

    public function confirmedStatus(string $reference): ?PaymentStatus
    {
        return $this->status;
    }

    public function attachOrder(Order $order, string $reference, PaymentStatus $status): void
    {
        $this->attached[$order->id] = $reference;

        PaymentTransaction::query()->create([
            'order_id' => $order->id,
            'payment_method_id' => $order->payment_method_id,
            'driver' => $this->code(),
            'type' => 'capture',
            'status' => 'success',
            'amount' => $order->price_amount,
            'currency_code' => $order->currency_code,
            'reference' => $reference,
        ]);
    }
}
