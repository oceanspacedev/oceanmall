<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Actions\Orders\CancelOrderWithProviders;
use App\Integrations\Komerce\Support\KomerceCapabilities;
use App\Models\OrderShipment;
use App\Models\User;
use App\Payment\Contracts\RetriesPayments;
use App\Payment\Exceptions\PaymentRetryRefused;
use App\Shipping\Contracts\CancelsShipments;
use App\Shipping\Contracts\FulfillsShipments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Events\Orders\OrderPaid;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Payment\DataTransferObjects\PaymentResult;
use Shopper\Payment\DataTransferObjects\WebhookResult;
use Shopper\Payment\Drivers\Driver as PaymentDriver;
use Shopper\Payment\Enum\TransactionStatus;
use Shopper\Payment\Enum\TransactionType;
use Shopper\Payment\Facades\Payment;
use Shopper\Payment\Models\PaymentTransaction;
use Shopper\Shipping\DataTransferObjects\Address;
use Shopper\Shipping\DataTransferObjects\Shipment;
use Shopper\Shipping\Drivers\Driver as ShippingDriver;
use Shopper\Shipping\Facades\Shipping;
use Tests\TestCase;

/**
 * Payment confirmations, cancellations and retries behave the same for every
 * gateway: no provider-specific branches or message matching in generic code.
 */
final class GatewayNeutralityTest extends TestCase
{
    use RefreshDatabase;

    public function test_stripe_capture_webhook_goes_through_mark_order_paid_and_fires_order_paid_once(): void
    {
        Event::fake([OrderPaid::class]);
        $driver = new CapturingWebhookDriver;
        Payment::extend('stripe', static fn (): CapturingWebhookDriver => $driver);

        $order = Order::factory()->create([
            'currency_code' => 'IDR',
            'price_amount' => 90000,
            'status' => OrderStatus::New,
            'payment_status' => PaymentStatus::Pending,
        ]);
        PaymentTransaction::query()->create([
            'order_id' => $order->id,
            'driver' => 'stripe',
            'type' => TransactionType::Initiate,
            'status' => TransactionStatus::Pending,
            'amount' => 90000,
            'currency_code' => 'IDR',
            'reference' => 'pi_123',
        ]);
        $driver->result = new WebhookResult('captured', 'pi_123', 90000);

        $this->postJson('/webhooks/stripe')->assertOk()->assertJsonPath('status', 'handled');
        $this->postJson('/webhooks/stripe')->assertOk()->assertJsonPath('status', 'already_processed');

        Event::assertDispatchedTimes(OrderPaid::class, 1);
        $this->assertSame(PaymentStatus::Paid, $order->refresh()->payment_status);
        $this->assertDatabaseHas((new PaymentTransaction)->getTable(), [
            'order_id' => $order->id,
            'reference' => 'pi_123',
            'type' => TransactionType::Capture->value,
            'status' => TransactionStatus::Success->value,
        ]);
    }

    public function test_staff_cancel_cancels_each_provider_shipment_exactly_once(): void
    {
        $shipping = new CountingCancelDriver;
        Shipping::extend('cancelship', static fn (): CountingCancelDriver => $shipping);
        config()->set('shopper.shipping.drivers.cancelship.enabled', true);

        $order = Order::factory()->create([
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
        ]);
        $shipment = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => Inventory::factory()->create()->id,
            'driver' => 'cancelship',
            'external_reference' => 'CS-1',
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'status' => 'pending',
        ]);

        $cancelled = resolve(CancelOrderWithProviders::class)->handle($order);

        $this->assertSame(OrderStatus::Cancelled, $cancelled->status);
        $this->assertSame([$shipment->id], $shipping->cancelled);
    }

    public function test_retry_refusals_are_typed_not_matched_on_provider_messages(): void
    {
        $gateway = new RefusingRetryDriver;
        Payment::extend('refusepay', static fn (): RefusingRetryDriver => $gateway);
        config()->set('shopper.payment.drivers.refusepay.enabled', true);

        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $method = PaymentMethod::factory()->create(['driver' => 'refusepay', 'is_enabled' => true]);
        $order = Order::factory()->create([
            'customer_id' => $user->id,
            'currency_code' => 'IDR',
            'price_amount' => 90000,
            'status' => OrderStatus::New,
            'payment_status' => PaymentStatus::Pending,
            'payment_method_id' => $method->id,
        ]);

        $gateway->throw = PaymentRetryRefused::alreadyPaid('Gateway says: charge settled');
        $this->postJson("/api/v1/orders/{$order->number}/retry-payment")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Pesanan ini sudah dibayar. Tarik untuk memperbarui status.');

        $gateway->throw = PaymentRetryRefused::notEligible('Gateway says: nope');
        $this->postJson("/api/v1/orders/{$order->number}/retry-payment")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Pembayaran pesanan ini tidak bisa diulang.');

        // A message that merely mentions "already paid" is a provider failure, not a refusal.
        $gateway->throw = new RuntimeException('upstream 500: already paid?');
        $this->postJson("/api/v1/orders/{$order->number}/retry-payment")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Belum bisa membuat ulang pembayaran. Coba lagi sebentar.');
    }

    public function test_delivery_webhook_is_processed_when_the_key_is_set_but_the_plan_probe_says_no(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        config()->set('komerce.shipping_delivery_api_key', 'delivery-key');
        config()->set('komerce.webhook_secret', 'webhook-secret');
        resolve(KomerceCapabilities::class)->observe('shipping_delivery', 401, '{"meta":{"message":"Invalid API key"}}');
        $this->assertFalse(resolve(KomerceCapabilities::class)->entitled('shipping_delivery'));

        // Not acknowledged-and-dropped: the controller verifies the callback
        // (here: rejects the missing signature) so Komerce retries it.
        $response = $this->postJson('/webhooks/komerce/delivery', ['order_no' => 'KOM-1', 'status' => 'Diterima']);

        $this->assertNotSame('disabled', $response->json('status'));

        // With no key at all the product is off: uniform 200 acknowledgement.
        config()->set('komerce.shipping_delivery_api_key', '');
        $this->postJson('/webhooks/komerce/delivery', ['order_no' => 'KOM-1'])
            ->assertOk()
            ->assertJsonPath('status', 'disabled');
    }
}

final class CapturingWebhookDriver extends PaymentDriver
{
    public WebhookResult $result;

    public function code(): string
    {
        return 'stripe';
    }

    public function name(): string
    {
        return 'Stripe (test)';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function initiatePayment(int $amount, string $currency, array $context = []): PaymentResult
    {
        return new PaymentResult(success: true, status: 'pending', reference: 'pi_new');
    }

    public function handleWebhook(array $payload, array $headers = []): WebhookResult
    {
        return $this->result;
    }
}

final class RefusingRetryDriver extends PaymentDriver implements RetriesPayments
{
    public ?\Throwable $throw = null;

    public function code(): string
    {
        return 'refusepay';
    }

    public function name(): string
    {
        return 'Refuse Pay';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function initiatePayment(int $amount, string $currency, array $context = []): PaymentResult
    {
        return new PaymentResult(success: true, status: 'pending', reference: 'rp_1');
    }

    public function canRetryPayment(Order $order): bool
    {
        return true;
    }

    public function retryPayment(Order $order): void
    {
        if ($this->throw !== null) {
            throw $this->throw;
        }
    }
}

final class CountingCancelDriver extends ShippingDriver implements CancelsShipments, FulfillsShipments
{
    /** @var list<int> */
    public array $cancelled = [];

    public function code(): string
    {
        return 'cancelship';
    }

    public function name(): string
    {
        return 'Cancel Ship';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function calculateRates(Address $from, Address $to, array $packages): Collection
    {
        return collect();
    }

    public function createShipment(Address $from, Address $to, array $packages, string $serviceCode): Shipment
    {
        return new Shipment(trackingNumber: 'unused', carrierCode: 'cancelship', serviceCode: $serviceCode);
    }

    public function fulfillShipment(OrderShipment $shipment): void {}

    public function cancelShipment(OrderShipment $shipment): void
    {
        $this->cancelled[] = (int) $shipment->id;
    }
}
