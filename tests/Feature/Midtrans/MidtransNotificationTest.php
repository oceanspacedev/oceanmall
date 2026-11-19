<?php

declare(strict_types=1);

namespace Tests\Feature\Midtrans;

use App\Integrations\Midtrans\Exceptions\MidtransPaymentNeedsReview;
use App\Payment\PaymentActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Events\Orders\OrderPaid;
use Shopper\Payment\Enum\TransactionStatus;
use Shopper\Payment\Enum\TransactionType;
use Shopper\Payment\Models\PaymentTransaction;
use Tests\TestCase;

/**
 * POST webhooks/midtrans/notification: signature first, then the status is
 * re-read from the Core API and applied idempotently.
 */
final class MidtransNotificationTest extends TestCase
{
    use InteractsWithMidtrans;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withMidtrans();
    }

    public function test_settlement_marks_the_order_paid_once(): void
    {
        Event::fake([OrderPaid::class]);
        $order = $this->initiatedOrder();
        Http::fake([self::API.'ORD-2026-0001/status' => Http::response($this->statusBody('ORD-2026-0001', 'settlement'))]);

        $body = $this->statusBody('ORD-2026-0001', 'settlement');

        $this->postNotification($body)->assertOk()->assertJson(['status' => 'handled']);
        $this->postNotification($body)->assertOk()->assertJson(['status' => 'already_processed']);

        $order->refresh();
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame(OrderStatus::Processing, $order->status);

        $transaction = PaymentTransaction::query()->where('order_id', $order->id)->sole();
        $this->assertSame(TransactionType::Capture, $transaction->type);
        $this->assertSame(TransactionStatus::Success, $transaction->status);
        $this->assertSame('settlement', $transaction->metadata['transaction_status'] ?? null);

        Event::assertDispatchedTimes(OrderPaid::class, 1);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request->url() === self::API.'ORD-2026-0001/status');
        $this->assertNull(resolve(PaymentActions::class)->forOrder($order), 'Paid orders have no pay link.');
    }

    public function test_the_notification_body_is_never_trusted_without_the_status_api(): void
    {
        $order = $this->initiatedOrder();
        // Signed "settlement" but the Core API still says pending.
        Http::fake([self::API.'*' => Http::response($this->statusBody('ORD-2026-0001', 'pending'))]);

        $this->postNotification($this->statusBody('ORD-2026-0001', 'settlement'))
            ->assertOk()
            ->assertJson(['status' => 'not_paid']);

        $this->assertSame(PaymentStatus::Pending, $order->refresh()->payment_status);
    }

    public function test_an_invalid_signature_is_rejected_without_any_lookup(): void
    {
        $order = $this->initiatedOrder();
        Http::fake();

        $this->postNotification($this->statusBody('ORD-2026-0001', 'settlement'), signature: str_repeat('a', 128))
            ->assertUnauthorized()
            ->assertJson(['status' => 'invalid_signature']);

        $unsigned = $this->statusBody('ORD-2026-0001', 'settlement');
        $this->postJson('/webhooks/midtrans/notification', $unsigned)->assertUnauthorized();

        Http::assertNothingSent();
        $this->assertSame(PaymentStatus::Pending, $order->refresh()->payment_status);
    }

    public function test_an_amount_that_does_not_match_the_order_is_not_applied(): void
    {
        Exceptions::fake();
        $order = $this->initiatedOrder();
        Http::fake([self::API.'*' => Http::response($this->statusBody('ORD-2026-0001', 'settlement', '1000.00'))]);

        $this->postNotification($this->statusBody('ORD-2026-0001', 'settlement', '1000.00'))
            ->assertOk()
            ->assertJson(['status' => 'amount_mismatch']);

        $this->assertSame(PaymentStatus::Pending, $order->refresh()->payment_status);
        Exceptions::assertReported(fn (\RuntimeException $e): bool => str_contains($e->getMessage(), 'amount mismatch'));
    }

    public function test_card_capture_under_fraud_review_waits(): void
    {
        $order = $this->initiatedOrder();
        $body = $this->statusBody('ORD-2026-0001', 'capture', extra: ['fraud_status' => 'challenge', 'payment_type' => 'credit_card']);
        Http::fake([self::API.'*' => Http::response($body)]);

        $this->postNotification($body)->assertOk()->assertJson(['status' => 'not_paid']);

        $this->assertSame(PaymentStatus::Pending, $order->refresh()->payment_status);
    }

    public function test_expire_closes_the_attempt_and_offers_a_new_payment(): void
    {
        $order = $this->initiatedOrder();
        $body = $this->statusBody('ORD-2026-0001', 'expire');
        $body['status_code'] = '407';
        Http::fake([self::API.'*' => Http::response($body)]);

        $this->postNotification($body)->assertOk()->assertJson(['status' => 'failed']);

        $order->refresh();
        $this->assertSame(PaymentStatus::Pending, $order->payment_status, 'Unpaid handling is left to orders:expire-unpaid, as for Komerce.');
        $this->assertSame(OrderStatus::New, $order->status);
        $this->assertSame(TransactionStatus::Failed, PaymentTransaction::query()->where('order_id', $order->id)->value('status'));
        $this->assertNull(resolve(PaymentActions::class)->forOrder($order), 'The dead Snap link is no longer shown.');
        $this->assertTrue(resolve(PaymentActions::class)->canRetry($order));
    }

    public function test_a_status_lookup_failure_asks_midtrans_to_retry(): void
    {
        $order = $this->initiatedOrder();
        Http::fake([self::API.'*' => Http::response('upstream down', 502)]);

        $this->postNotification($this->statusBody('ORD-2026-0001', 'settlement'))
            ->assertStatus(503)
            ->assertJson(['status' => 'lookup_failed']);

        $this->assertSame(PaymentStatus::Pending, $order->refresh()->payment_status);
    }

    public function test_a_status_answer_without_a_transaction_status_is_a_failed_lookup_not_unpaid(): void
    {
        $order = $this->initiatedOrder();
        // Core API reports auth / server errors in the body with HTTP 200.
        Http::fake([self::API.'*' => Http::response(['status_code' => '401', 'status_message' => 'Unknown Merchant server_key/id'])]);

        $this->postNotification($this->statusBody('ORD-2026-0001', 'settlement'))
            ->assertStatus(503)
            ->assertJson(['status' => 'lookup_failed']);

        $this->assertSame(PaymentStatus::Pending, $order->refresh()->payment_status);
        $this->assertSame(TransactionStatus::Pending, PaymentTransaction::query()->where('order_id', $order->id)->value('status'));
    }

    public function test_a_concurrent_duplicate_of_the_paying_notification_is_not_reported_as_extra_money(): void
    {
        Exceptions::fake();
        Event::fake([OrderPaid::class]);
        $order = $this->initiatedOrder();
        // State a racing duplicate sees: the attempt was captured and the
        // order paid by the first request, while the row it read first was
        // still the (newer) pending Initiate one.
        PaymentTransaction::query()->where('order_id', $order->id)->update(['type' => TransactionType::Capture, 'status' => TransactionStatus::Success]);
        PaymentTransaction::query()->create([
            'order_id' => $order->id, 'driver' => 'midtrans', 'type' => TransactionType::Initiate,
            'status' => TransactionStatus::Pending, 'amount' => 150000, 'currency_code' => 'IDR', 'reference' => 'ORD-2026-0001',
        ]);
        $order->forceFill(['payment_status' => PaymentStatus::Paid, 'status' => OrderStatus::Processing])->save();
        Http::fake([self::API.'*' => Http::response($this->statusBody('ORD-2026-0001', 'settlement'))]);

        $this->postNotification($this->statusBody('ORD-2026-0001', 'settlement'))
            ->assertOk()
            ->assertJson(['status' => 'already_processed']);

        Exceptions::assertNotReported(MidtransPaymentNeedsReview::class);
        Event::assertNotDispatched(OrderPaid::class);
    }

    public function test_without_a_key_notifications_are_acknowledged_and_nothing_is_read(): void
    {
        $this->withoutMidtrans();
        $order = $this->initiatedOrder();
        Http::fake();

        $this->postNotification($this->statusBody('ORD-2026-0001', 'settlement'), signature: 'anything')
            ->assertOk()
            ->assertJson(['status' => 'disabled']);

        Http::assertNothingSent();
        $this->assertSame(PaymentStatus::Pending, $order->refresh()->payment_status);
    }

    public function test_unknown_order_ids_are_acknowledged_and_ignored(): void
    {
        Http::fake();

        $this->postNotification($this->statusBody('payment_notif_test_G123', 'settlement'))
            ->assertOk()
            ->assertJson(['status' => 'ignored']);

        Http::assertNothingSent();
    }

    public function test_money_for_a_cancelled_order_is_reported_and_never_revives_it(): void
    {
        Exceptions::fake();
        $order = $this->initiatedOrder(attributes: ['status' => OrderStatus::Cancelled, 'payment_status' => PaymentStatus::Voided]);
        Http::fake([self::API.'*' => Http::response($this->statusBody('ORD-2026-0001', 'settlement'))]);

        $this->postNotification($this->statusBody('ORD-2026-0001', 'settlement'))
            ->assertOk()
            ->assertJson(['status' => 'needs_review']);

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(PaymentStatus::Voided, $order->payment_status);
        Exceptions::assertReported(MidtransPaymentNeedsReview::class);
    }

    public function test_a_second_paid_attempt_is_reported_not_applied_twice(): void
    {
        Exceptions::fake();
        Event::fake([OrderPaid::class]);
        $order = $this->initiatedOrder('ORD-2026-0001~2');
        PaymentTransaction::query()->create([
            'order_id' => $order->id, 'driver' => 'midtrans', 'type' => TransactionType::Initiate,
            'status' => TransactionStatus::Failed, 'amount' => 150000, 'currency_code' => 'IDR', 'reference' => 'ORD-2026-0001',
        ]);
        Http::fake([self::API.'*' => Http::response($this->statusBody('ORD-2026-0001~2', 'settlement'))]);
        $this->postNotification($this->statusBody('ORD-2026-0001~2', 'settlement'))->assertJson(['status' => 'handled']);

        // The first (supposedly expired) attempt reports money too.
        Http::fake([self::API.'*' => Http::response($this->statusBody('ORD-2026-0001', 'settlement'))]);
        $this->postNotification($this->statusBody('ORD-2026-0001', 'settlement'))->assertJson(['status' => 'needs_review']);

        Event::assertDispatchedTimes(OrderPaid::class, 1);
        Exceptions::assertReported(MidtransPaymentNeedsReview::class);
    }

    public function test_a_retried_attempt_maps_back_to_its_order(): void
    {
        $order = $this->initiatedOrder('ORD-2026-0001~3');
        Http::fake([self::API.'ORD-2026-0001~3/status' => Http::response($this->statusBody('ORD-2026-0001~3', 'settlement'))]);

        $this->postNotification($this->statusBody('ORD-2026-0001~3', 'settlement'))->assertJson(['status' => 'handled']);

        $this->assertSame(PaymentStatus::Paid, $order->refresh()->payment_status);
    }

    public function test_with_a_key_but_the_operator_switch_off_notifications_are_still_applied(): void
    {
        config()->set('shopper.payment.drivers.midtrans.enabled', false);
        $order = $this->initiatedOrder();
        Http::fake([self::API.'*' => Http::response($this->statusBody('ORD-2026-0001', 'settlement'))]);

        $this->postNotification($this->statusBody('ORD-2026-0001', 'settlement'))->assertJson(['status' => 'handled']);

        $this->assertSame(PaymentStatus::Paid, $order->refresh()->payment_status);
    }
}
