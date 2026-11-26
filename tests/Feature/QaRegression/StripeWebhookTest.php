<?php

declare(strict_types=1);

namespace Tests\Feature\QaRegression;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Events\Orders\OrderPaid;
use Shopper\Core\Models\Order;
use Shopper\Payment\Enum\TransactionStatus;
use Shopper\Payment\Enum\TransactionType;
use Shopper\Payment\Models\PaymentTransaction;
use Tests\TestCase;

/**
 * QA go-live: /webhooks/stripe accepted events signed with an empty secret
 * (the default, Stripe switched off) and settled any gateway's order by
 * reference. It must reject unless Stripe is on with a signing secret, verify
 * every Stripe-Signature, and only ever touch Stripe's own transactions.
 */
final class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_qa_regression';

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([OrderPaid::class]);
        $this->configureStripe(enabled: false, secret: null);
    }

    public function test_forged_event_is_rejected_while_stripe_is_off_and_has_no_secret(): void
    {
        $order = $this->unpaidOrder('midtrans', 'ORD-QA-1001', 'ORD-QA-1001');

        $this->forge('payment_intent.succeeded', 'ORD-QA-1001', '')
            ->assertNotFound()
            ->assertJsonPath('status', 'disabled');
        $this->forge('payment_intent.canceled', 'ORD-QA-1001', '')
            ->assertNotFound()
            ->assertJsonPath('status', 'disabled');

        $this->assertOrderUntouched($order);
    }

    public function test_forged_event_is_rejected_when_stripe_is_on_without_a_secret(): void
    {
        $this->configureStripe(enabled: true, secret: null);
        $order = $this->unpaidOrder('stripe', 'pi_qa_1', 'ORD-QA-1002');

        $this->forge('payment_intent.succeeded', 'pi_qa_1', '')
            ->assertNotFound()
            ->assertJsonPath('status', 'disabled');

        $this->assertOrderUntouched($order);
    }

    public function test_signed_event_is_rejected_while_stripe_is_switched_off(): void
    {
        $this->configureStripe(enabled: false, secret: self::SECRET);
        $order = $this->unpaidOrder('stripe', 'pi_qa_2', 'ORD-QA-1003');

        $this->forge('payment_intent.succeeded', 'pi_qa_2', self::SECRET)
            ->assertNotFound()
            ->assertJsonPath('status', 'disabled');

        $this->assertOrderUntouched($order);
    }

    public function test_event_signed_with_the_wrong_secret_answers_400_not_500(): void
    {
        $this->configureStripe(enabled: true, secret: self::SECRET);
        $order = $this->unpaidOrder('stripe', 'pi_qa_3', 'ORD-QA-1004');

        $this->forge('payment_intent.succeeded', 'pi_qa_3', '')
            ->assertStatus(400)
            ->assertJsonPath('status', 'invalid_signature');
        $this->forge('payment_intent.canceled', 'pi_qa_3', 'whsec_attacker')
            ->assertStatus(400)
            ->assertJsonPath('status', 'invalid_signature');

        $this->assertOrderUntouched($order);
    }

    public function test_event_without_a_signature_answers_400(): void
    {
        $this->configureStripe(enabled: true, secret: self::SECRET);
        $order = $this->unpaidOrder('stripe', 'pi_qa_4', 'ORD-QA-1005');

        $this->call('POST', '/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json'], $this->eventBody('payment_intent.succeeded', 'pi_qa_4'))
            ->assertStatus(400)
            ->assertJsonPath('status', 'invalid_signature');
        $this->call('POST', '/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json'], '')
            ->assertStatus(400);

        $this->assertOrderUntouched($order);
    }

    public function test_validly_signed_event_never_touches_another_gateways_order(): void
    {
        $this->configureStripe(enabled: true, secret: self::SECRET);
        $order = $this->unpaidOrder('midtrans', 'ORD-QA-1006', 'ORD-QA-1006');

        $this->forge('payment_intent.succeeded', 'ORD-QA-1006', self::SECRET)
            ->assertNotFound()
            ->assertJsonPath('status', 'no_transaction');
        $this->forge('payment_intent.canceled', 'ORD-QA-1006', self::SECRET)
            ->assertNotFound()
            ->assertJsonPath('status', 'no_transaction');

        $this->assertOrderUntouched($order);
    }

    public function test_validly_signed_capture_still_pays_the_stripe_order(): void
    {
        $this->configureStripe(enabled: true, secret: self::SECRET);
        $order = $this->unpaidOrder('stripe', 'pi_qa_7', 'ORD-QA-1007');

        $this->forge('payment_intent.succeeded', 'pi_qa_7', self::SECRET)
            ->assertOk()
            ->assertJsonPath('status', 'handled');

        Event::assertDispatchedTimes(OrderPaid::class, 1);
        $this->assertSame(PaymentStatus::Paid, $order->refresh()->payment_status);
    }

    private function configureStripe(bool $enabled, ?string $secret): void
    {
        config()->set('shopper.payment.drivers.stripe.enabled', $enabled);
        config()->set('shopper.payment.drivers.stripe.credentials.webhook_secret', $secret);
        config()->set('shopper.stripe.webhook_secret', $secret);
    }

    private function unpaidOrder(string $driver, string $reference, string $number): Order
    {
        $order = Order::factory()->create([
            'number' => $number,
            'currency_code' => 'IDR',
            'price_amount' => 1000,
            'status' => OrderStatus::New,
            'payment_status' => PaymentStatus::Pending,
        ]);

        PaymentTransaction::query()->create([
            'order_id' => $order->id,
            'driver' => $driver,
            'type' => TransactionType::Initiate,
            'status' => TransactionStatus::Pending,
            'amount' => 1000,
            'currency_code' => 'IDR',
            'reference' => $reference,
        ]);

        return $order;
    }

    private function eventBody(string $type, string $intentId): string
    {
        return (string) json_encode([
            'id' => 'evt_qa',
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => [
                'id' => $intentId,
                'object' => 'payment_intent',
                'amount' => 100000,
                'amount_received' => 100000,
            ]],
        ]);
    }

    /**
     * Posts an event signed exactly the way Stripe signs it, keyed by $secret
     * ('' is what an attacker can compute when no secret is configured).
     */
    private function forge(string $type, string $intentId, string $secret): TestResponse
    {
        $body = $this->eventBody($type, $intentId);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

        return $this->call('POST', '/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $body);
    }

    private function assertOrderUntouched(Order $order): void
    {
        $order->refresh();

        $this->assertSame(OrderStatus::New, $order->status);
        $this->assertSame(PaymentStatus::Pending, $order->payment_status);
        Event::assertNotDispatched(OrderPaid::class);
    }
}
