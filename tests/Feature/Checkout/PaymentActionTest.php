<?php

declare(strict_types=1);

namespace Tests\Feature\Checkout;

use App\Actions\Checkout\FetchPaymentMethods;
use App\Actions\Checkout\InitiateOrderPayment;
use App\Models\User;
use App\Payment\PaymentActions;
use App\Payment\PaymentMethodCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Country;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Core\Models\Zone;
use Shopper\Payment\DataTransferObjects\PaymentResult;
use Shopper\Payment\DataTransferObjects\WebhookResult;
use Shopper\Payment\Drivers\Driver;
use Shopper\Payment\Enum\TransactionType;
use Shopper\Payment\Facades\Payment;
use Shopper\Payment\Models\PaymentTransaction;
use Tests\TestCase;

/**
 * A gateway added with Payment::extend() works on web and mobile through the
 * shared InitiateOrderPayment step and the neutral payment action, with no
 * provider-specific branch.
 */
final class PaymentActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Payment::extend('hosted', static fn (): HostedGatewayDriver => new HostedGatewayDriver);
        // Shopper's convention: a driver is on only with its config entry.
        config()->set('shopper.payment.drivers.hosted.enabled', true);
    }

    public function test_any_driver_is_initiated_through_shoppers_payment_service(): void
    {
        $order = $this->order('hosted');

        ['result' => $result, 'action' => $action] = resolve(InitiateOrderPayment::class)->handle($order);

        $this->assertTrue($result->success);
        $this->assertSame([
            'driver' => 'hosted',
            'kind' => 'redirect',
            'data' => ['url' => 'https://pay.example.test/session/'.$order->number],
        ], $action);
        $this->assertDatabaseHas((new PaymentTransaction)->getTable(), [
            'order_id' => $order->id,
            'driver' => 'hosted',
            'type' => TransactionType::Initiate->value,
            'reference' => 'hosted_'.$order->number,
        ]);
    }

    public function test_manual_orders_get_a_none_action_with_the_method_instructions(): void
    {
        $order = $this->order('manual', ['instructions' => 'Bayar tunai ke kurir saat paket tiba.']);

        ['action' => $action] = resolve(InitiateOrderPayment::class)->handle($order);

        $this->assertSame('manual', $action['driver']);
        $this->assertSame('none', $action['kind']);
        $this->assertSame('Bayar tunai ke kurir saat paket tiba.', $action['data']['instructions']);
        $this->assertDatabaseHas((new PaymentTransaction)->getTable(), [
            'order_id' => $order->id,
            'driver' => 'manual',
            'type' => TransactionType::Initiate->value,
        ]);
        $this->assertNull(PaymentActions::legacyInstructions($action));
    }

    public function test_paid_or_cancelled_orders_have_no_pending_action(): void
    {
        $actions = resolve(PaymentActions::class);

        $this->assertNull($actions->forOrder($this->order('manual', [], ['payment_status' => PaymentStatus::Paid])));
        $this->assertNull($actions->forOrder($this->order('manual', [], ['status' => OrderStatus::Cancelled])));
        $this->assertFalse($actions->canRetry($this->order('manual')), 'Manual payments have nothing to retry.');
        $this->assertNull($actions->reconciler($this->order('manual')), 'Manual payments are marked paid by staff.');
    }

    public function test_mobile_api_order_detail_returns_payment_action_and_keeps_the_legacy_field(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $order = $this->order('manual', ['instructions' => 'Transfer ke rekening toko.'], ['customer_id' => $user->id]);

        $this->getJson("/api/v1/orders/{$order->number}")
            ->assertOk()
            ->assertJsonPath('data.payment_action.driver', 'manual')
            ->assertJsonPath('data.payment_action.kind', 'none')
            ->assertJsonPath('data.payment_action.data.instructions', 'Transfer ke rekening toko.')
            ->assertJsonPath('data.payment', null)
            ->assertJsonPath('data.can_retry_payment', false);

        $this->postJson("/api/v1/orders/{$order->number}/sync-payment")
            ->assertOk()
            ->assertJsonPath('data.sync', 'no_payment')
            ->assertJsonPath('data.payment_action.kind', 'none');

        $this->postJson("/api/v1/orders/{$order->number}/retry-payment")
            ->assertStatus(422);
    }

    public function test_payment_methods_follow_one_rule_for_every_driver(): void
    {
        $country = Country::factory()->create();
        $zone = Zone::factory()->create(['is_enabled' => true]);
        $zone->countries()->attach($country->id);

        $hosted = PaymentMethod::factory()->create(['driver' => 'hosted', 'is_enabled' => true]);
        $unknown = PaymentMethod::factory()->create(['driver' => 'not-registered', 'is_enabled' => true]);
        $komerce = PaymentMethod::factory()->create(['driver' => 'komerce', 'is_enabled' => true]);
        $zone->paymentMethods()->attach([$hosted->id, $unknown->id, $komerce->id]);

        $drivers = array_column(resolve(FetchPaymentMethods::class)->handle($country->id), 'driver');
        $this->assertSame(['hosted'], $drivers, 'Configured drivers only; Komerce without a key is not offered.');

        config()->set('shopper.payment.drivers.hosted.enabled', false);
        $this->assertFalse((new PaymentMethodCatalog)->driverReady('hosted'), 'The Shopper switch turns any driver off.');
    }

    public function test_a_redirect_gateway_keeps_its_pay_link_on_every_later_read(): void
    {
        $user = User::factory()->create();
        $order = $this->order('hosted', [], ['customer_id' => $user->id]);
        $url = 'https://pay.example.test/session/'.$order->number;

        resolve(InitiateOrderPayment::class)->handle($order);

        // Kept on the Initiate transaction Shopper recorded.
        $transaction = PaymentTransaction::query()->where('order_id', $order->id)->where('type', TransactionType::Initiate)->sole();
        $this->assertSame('redirect', data_get($transaction->metadata, 'payment_action.kind'));

        // Account page / success-page reload / mobile order screen read it back.
        $action = resolve(PaymentActions::class)->forOrder($order->refresh());
        $this->assertSame(['driver' => 'hosted', 'kind' => 'redirect', 'data' => ['url' => $url]], $action);

        Sanctum::actingAs($user);
        $this->getJson("/api/v1/orders/{$order->number}")
            ->assertOk()
            ->assertJsonPath('data.payment_action.kind', 'redirect')
            ->assertJsonPath('data.payment_action.data.url', $url);

        // Paid: nothing pending any more.
        $order->update(['payment_status' => PaymentStatus::Paid]);
        $this->assertNull(resolve(PaymentActions::class)->forOrder($order->refresh()));
    }

    public function test_an_online_gateway_without_a_recorded_action_is_not_reported_as_offline(): void
    {
        // Payment setup never happened (or failed): no 'none' / "pay offline"
        // action, so the success page falls back to the action just placed
        // and the app does not treat the order as an offline payment.
        $this->assertNull(resolve(PaymentActions::class)->forOrder($this->order('hosted')));
        $this->assertSame('none', resolve(PaymentActions::class)->forOrder($this->order('manual'))['kind']);
    }

    /**
     * @param  array<string, mixed>  $method
     * @param  array<string, mixed>  $order
     */
    private function order(string $driver, array $method = [], array $order = []): Order
    {
        $paymentMethod = PaymentMethod::factory()->create([
            'driver' => $driver,
            'is_enabled' => true,
            ...$method,
        ]);

        return Order::factory()->create([
            'currency_code' => 'IDR',
            'price_amount' => 90000,
            'status' => OrderStatus::New,
            'payment_status' => PaymentStatus::Pending,
            'payment_method_id' => $paymentMethod->id,
            ...$order,
        ]);
    }
}

/**
 * A hosted-page gateway as a third party would add it: a plain Shopper
 * payment driver, no app contract implemented.
 */
final class HostedGatewayDriver extends Driver
{
    public function code(): string
    {
        return 'hosted';
    }

    public function name(): string
    {
        return 'Hosted Gateway';
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
        return true;
    }

    public function supportsRefunds(): bool
    {
        return false;
    }

    public function initiatePayment(int $amount, string $currency, array $context = []): PaymentResult
    {
        return new PaymentResult(
            success: true,
            status: 'pending',
            reference: 'hosted_'.$context['order_number'],
            redirectUrl: 'https://pay.example.test/session/'.$context['order_number'],
            amount: $amount,
        );
    }

    public function handleWebhook(array $payload, array $headers = []): WebhookResult
    {
        return WebhookResult::ignored();
    }
}
