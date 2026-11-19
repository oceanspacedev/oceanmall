<?php

declare(strict_types=1);

namespace Tests\Feature\Xendit;

use App\Integrations\Xendit\Exceptions\XenditPaidAfterCancellation;
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
use Tests\Feature\Xendit\Concerns\FakesXendit;
use Tests\TestCase;

final class XenditWebhookTest extends TestCase
{
    use FakesXendit;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureXendit();
        $this->fakeXenditApi();
    }

    public function test_a_paid_callback_marks_the_order_paid_once_after_reading_the_invoice_back(): void
    {
        Event::fake([OrderPaid::class]);
        $order = $this->xenditOrder('inv_1');
        $this->putXenditInvoice('inv_1', ['status' => 'PAID', 'paid_amount' => 150000, 'payment_channel' => 'BCA', 'paid_at' => '2026-11-19T03:00:00.000Z']);

        $payload = ['id' => 'inv_1', 'external_id' => 'ORD-1001', 'status' => 'PAID', 'amount' => 150000, 'paid_amount' => 150000];

        $this->postXenditCallback($payload)->assertOk()->assertJsonPath('status', 'handled');
        $this->postXenditCallback($payload)->assertOk()->assertJsonPath('status', 'already_processed');
        $this->postXenditCallback([...$payload, 'status' => 'SETTLED'])->assertOk()->assertJsonPath('status', 'already_processed');

        $order->refresh();
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame(OrderStatus::Processing, $order->status);
        Event::assertDispatchedTimes(OrderPaid::class, 1);

        $transaction = PaymentTransaction::query()->where('order_id', $order->id)->sole();
        $this->assertSame(TransactionType::Capture, $transaction->type);
        $this->assertSame(TransactionStatus::Success, $transaction->status);
        $this->assertSame('BCA', json_decode((string) $order->getAttribute('metadata'), true)['xendit']['payment_channel']);

        // Only the first callback needed Xendit; duplicates are answered locally.
        Http::assertSentCount(1);
        Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api.xendit.co/v2/invoices/inv_1');
    }

    public function test_a_wrong_or_missing_callback_token_is_rejected_without_calling_xendit(): void
    {
        $order = $this->xenditOrder('inv_1');
        $this->putXenditInvoice('inv_1', ['status' => 'PAID']);

        $this->postXenditCallback(['id' => 'inv_1', 'status' => 'PAID'], 'wrong-token')
            ->assertUnauthorized()
            ->assertJsonPath('status', 'invalid_token');
        $this->postXenditCallback(['id' => 'inv_1', 'status' => 'PAID'], null)->assertUnauthorized();

        Http::assertNothingSent();
        $this->assertSame(PaymentStatus::Pending, $order->refresh()->payment_status);
    }

    public function test_the_callback_body_is_not_trusted_when_xendit_says_the_invoice_is_still_pending(): void
    {
        $order = $this->xenditOrder('inv_1');
        $this->putXenditInvoice('inv_1', ['status' => 'PENDING']);

        $this->postXenditCallback(['id' => 'inv_1', 'external_id' => 'ORD-1001', 'status' => 'PAID', 'amount' => 150000])
            ->assertOk()
            ->assertJsonPath('status', 'not_paid');

        $this->assertSame(PaymentStatus::Pending, $order->refresh()->payment_status);
    }

    public function test_an_amount_mismatch_never_marks_the_order_paid(): void
    {
        Exceptions::fake();
        $order = $this->xenditOrder('inv_1', 150000);
        $this->putXenditInvoice('inv_1', ['status' => 'PAID', 'amount' => 15000, 'paid_amount' => 15000]);

        $this->postXenditCallback(['id' => 'inv_1', 'status' => 'PAID'])
            ->assertOk()
            ->assertJsonPath('status', 'amount_mismatch');

        $this->assertSame(PaymentStatus::Pending, $order->refresh()->payment_status);
        Exceptions::assertReported(static fn (\RuntimeException $e): bool => str_contains($e->getMessage(), 'amount mismatch'));
    }

    public function test_a_short_paid_amount_on_a_correct_invoice_is_a_mismatch_too(): void
    {
        Exceptions::fake();
        $order = $this->xenditOrder('inv_1', 150000);
        $this->putXenditInvoice('inv_1', ['status' => 'PAID', 'amount' => 150000, 'paid_amount' => 149000]);

        $this->postXenditCallback(['id' => 'inv_1', 'status' => 'PAID'])->assertJsonPath('status', 'amount_mismatch');

        $this->assertSame(PaymentStatus::Pending, $order->refresh()->payment_status);
    }

    public function test_an_invoice_issued_for_another_order_is_refused(): void
    {
        Exceptions::fake();
        $order = $this->xenditOrder('inv_1');
        $this->putXenditInvoice('inv_1', ['status' => 'PAID', 'external_id' => 'ORD-9999']);

        $this->postXenditCallback(['id' => 'inv_1', 'status' => 'PAID'])->assertJsonPath('status', 'foreign_invoice');

        $this->assertSame(PaymentStatus::Pending, $order->refresh()->payment_status);
    }

    public function test_an_unknown_invoice_is_acknowledged_and_ignored(): void
    {
        $this->postXenditCallback(['id' => 'inv_unknown', 'status' => 'PAID'])
            ->assertOk()
            ->assertJsonPath('status', 'no_transaction');

        $this->postXenditCallback(['status' => 'PAID'])->assertOk()->assertJsonPath('status', 'ignored');
        $this->postXenditCallback(['id' => 'inv_1', 'status' => 'PENDING'])->assertOk()->assertJsonPath('status', 'ignored');

        // Only the unknown PAID callback is read back (404 here: not ours).
        Http::assertSentCount(1);
    }

    public function test_xendit_unreachable_answers_503_so_the_callback_is_retried(): void
    {
        Exceptions::fake();
        $order = $this->xenditOrder('inv_1');
        $this->xenditUnreachable[] = 'inv_1';

        $this->postXenditCallback(['id' => 'inv_1', 'status' => 'PAID'])
            ->assertStatus(503)
            ->assertJsonPath('status', 'lookup_failed');

        $this->assertSame(PaymentStatus::Pending, $order->refresh()->payment_status);
    }

    public function test_an_expired_current_invoice_cancels_the_unpaid_order(): void
    {
        $order = $this->xenditOrder('inv_1');
        $this->putXenditInvoice('inv_1', ['status' => 'EXPIRED']);

        $this->postXenditCallback(['id' => 'inv_1', 'status' => 'EXPIRED'])
            ->assertOk()
            ->assertJsonPath('status', 'expired');

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(PaymentStatus::Voided, $order->payment_status);

        // Duplicate EXPIRED callback: nothing more happens.
        $this->postXenditCallback(['id' => 'inv_1', 'status' => 'EXPIRED'])
            ->assertOk()
            ->assertJsonPath('status', 'cancelled_order');
    }

    public function test_an_expired_invoice_replaced_by_a_retry_leaves_the_order_alone(): void
    {
        $order = $this->xenditOrder('inv_2', 150000, ['external_id' => 'ORD-1001-2', 'attempt' => 2]);
        PaymentTransaction::query()->create([
            'order_id' => $order->id,
            'driver' => 'xendit',
            'type' => TransactionType::Initiate,
            'status' => TransactionStatus::Failed,
            'amount' => 150000,
            'currency_code' => 'IDR',
            'reference' => 'inv_1',
        ]);
        $this->putXenditInvoice('inv_1', ['status' => 'EXPIRED']);

        $this->postXenditCallback(['id' => 'inv_1', 'status' => 'EXPIRED'])
            ->assertOk()
            ->assertJsonPath('status', 'superseded');

        $this->assertSame(OrderStatus::New, $order->refresh()->status);
    }

    public function test_money_received_after_cancellation_is_reported_as_critical(): void
    {
        Exceptions::fake();
        $order = $this->xenditOrder('inv_1');
        $order->forceFill(['status' => OrderStatus::Cancelled, 'payment_status' => PaymentStatus::Voided])->save();
        $this->putXenditInvoice('inv_1', ['status' => 'PAID', 'paid_amount' => 150000]);

        $this->postXenditCallback(['id' => 'inv_1', 'status' => 'PAID'])
            ->assertOk()
            ->assertJsonPath('status', 'paid_after_cancel');

        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);
        Exceptions::assertReported(XenditPaidAfterCancellation::class);
    }
}
