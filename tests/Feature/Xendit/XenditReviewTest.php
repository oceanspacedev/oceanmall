<?php

declare(strict_types=1);

namespace Tests\Feature\Xendit;

use App\Actions\Orders\CancelOrderWithProviders;
use App\Integrations\Xendit\Payment\XenditDriver;
use App\Payment\Contracts\ReconcilesPayments;
use App\Payment\PaymentActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Events\Orders\OrderPaid;
use Shopper\Core\Models\Order;
use Shopper\Payment\Enum\TransactionStatus;
use Shopper\Payment\Enum\TransactionType;
use Shopper\Payment\Facades\Payment;
use Shopper\Payment\Models\PaymentTransaction;
use Tests\Feature\Xendit\Concerns\FakesXendit;
use Tests\TestCase;

/**
 * Edge cases: invoices whose create answer was lost (adopted from a PAID
 * callback), keys swapped between test and live mode, and a keyless store
 * never showing a pay link it could not confirm.
 */
final class XenditReviewTest extends TestCase
{
    use FakesXendit;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureXendit();
        $this->fakeXenditApi();
    }

    public function test_a_paid_invoice_whose_create_answer_was_lost_is_adopted_and_the_open_invoice_expired(): void
    {
        Event::fake([OrderPaid::class]);
        $order = $this->xenditOrder('inv_2', 150000, ['external_id' => 'ORD-1001-2', 'attempt' => 2]);
        $this->putXenditInvoice('inv_2', ['external_id' => 'ORD-1001-2']);
        $this->putXenditInvoice('inv_lost', [
            'status' => 'PAID',
            'paid_amount' => 150000,
            'metadata' => ['order_id' => (string) $order->id, 'order_number' => 'ORD-1001'],
        ]);

        $this->postXenditCallback(['id' => 'inv_lost', 'status' => 'PAID'])
            ->assertOk()
            ->assertJsonPath('status', 'handled');

        $this->assertSame(PaymentStatus::Paid, $order->refresh()->payment_status);
        Event::assertDispatchedTimes(OrderPaid::class, 1);
        $this->assertSame('EXPIRED', $this->xenditInvoices['inv_2']['status'], 'The other open invoice must not take a second payment.');

        $capture = PaymentTransaction::query()->where('reference', 'inv_lost')->sole();
        $this->assertSame(TransactionType::Capture, $capture->type);
        $this->assertSame(TransactionStatus::Success, $capture->status);

        // Duplicate callback: answered locally now that the invoice is recorded.
        $this->postXenditCallback(['id' => 'inv_lost', 'status' => 'PAID'])->assertJsonPath('status', 'already_processed');
    }

    public function test_an_unknown_paid_invoice_of_another_order_or_system_is_not_adopted(): void
    {
        $order = $this->xenditOrder('inv_1');
        $this->putXenditInvoice('inv_1');

        // external_id says ORD-1001 but metadata points elsewhere.
        $this->putXenditInvoice('inv_other', ['status' => 'PAID', 'paid_amount' => 150000, 'metadata' => ['order_id' => '999999']]);
        // No metadata at all (created by another system on the same account).
        $this->putXenditInvoice('inv_foreign', ['status' => 'PAID', 'paid_amount' => 150000]);
        // Metadata right, external_id of another order.
        $this->putXenditInvoice('inv_mixed', ['status' => 'PAID', 'paid_amount' => 150000, 'external_id' => 'ORD-7777', 'metadata' => ['order_id' => (string) $order->id]]);

        foreach (['inv_other', 'inv_foreign', 'inv_mixed'] as $id) {
            $this->postXenditCallback(['id' => $id, 'status' => 'PAID'])->assertOk()->assertJsonPath('status', 'no_transaction');
        }

        $this->assertSame(PaymentStatus::Pending, $order->refresh()->payment_status);
        Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), 'expire!'));
    }

    public function test_an_adopted_invoice_with_a_wrong_amount_is_never_marked_paid(): void
    {
        Exceptions::fake();
        $order = $this->xenditOrder('inv_1');
        $this->putXenditInvoice('inv_lost', ['status' => 'PAID', 'amount' => 1000, 'paid_amount' => 1000, 'metadata' => ['order_id' => (string) $order->id]]);

        $this->postXenditCallback(['id' => 'inv_lost', 'status' => 'PAID'])->assertJsonPath('status', 'amount_mismatch');

        $this->assertSame(PaymentStatus::Pending, $order->refresh()->payment_status);
    }

    public function test_a_second_paid_invoice_for_a_paid_order_is_reported(): void
    {
        Exceptions::fake();
        $order = $this->xenditOrder('inv_1');
        $order->forceFill(['payment_status' => PaymentStatus::Paid, 'status' => OrderStatus::Processing])->save();
        $this->putXenditInvoice('inv_lost', ['status' => 'PAID', 'paid_amount' => 150000, 'metadata' => ['order_id' => (string) $order->id]]);

        $this->postXenditCallback(['id' => 'inv_lost', 'status' => 'PAID'])->assertOk()->assertJsonPath('status', 'duplicate_payment');

        Exceptions::assertReported(static fn (\RuntimeException $e): bool => str_contains($e->getMessage(), 'already paid through another invoice'));
    }

    public function test_an_unknown_invoice_unknown_to_xendit_is_acknowledged(): void
    {
        $this->postXenditCallback(['id' => 'inv_missing', 'status' => 'PAID'])->assertOk()->assertJsonPath('status', 'no_transaction');
    }

    public function test_a_test_mode_invoice_after_switching_to_a_live_key_is_never_looked_up(): void
    {
        $this->configureXendit('xnd_production_secret');
        $order = $this->xenditOrder('inv_test', 150000, ['mode' => 'test', 'expiry_date' => now()->subMinute()->toIso8601String()]);
        $driver = Payment::driver(XenditDriver::CODE);

        $this->assertFalse($driver->canExpireUnpaidOrder($order), 'expire-unpaid falls back to its local grace period.');
        $this->assertNull($driver->paymentAction($order), 'A test-mode pay link is useless with a live key.');
        $this->assertSame(ReconcilesPayments::LOOKUP_FAILED, $driver->reconcilePayment($order));
        $this->assertTrue($driver->canRetryPayment($order));

        $this->artisan('orders:expire-unpaid')->assertSuccessful();
        $this->assertSame(OrderStatus::New, $order->refresh()->status);
        Http::assertNothingSent();

        // Pay again replaces it with a live invoice without touching the test one.
        $driver->retryPayment($order);

        $state = json_decode((string) $order->refresh()->getAttribute('metadata'), true)['xendit'];
        $this->assertSame('inv_new_1', $state['invoice_id']);
        $this->assertSame('live', $state['mode']);
        Http::assertSentCount(1);
        Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/v2/invoices'));
    }

    public function test_staff_can_cancel_an_order_whose_invoice_belongs_to_the_other_mode(): void
    {
        $this->configureXendit('xnd_production_secret');
        $order = $this->xenditOrder('inv_test', 150000, ['mode' => 'test']);

        resolve(CancelOrderWithProviders::class)->handle($order);

        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);
        Http::assertNothingSent();
    }

    public function test_a_live_invoice_seen_through_a_test_key_is_never_replaced_and_its_cancel_is_reported(): void
    {
        Exceptions::fake();
        $order = $this->xenditOrder('inv_live', 150000, ['mode' => 'live']);
        $driver = Payment::driver(XenditDriver::CODE);

        $this->assertFalse($driver->canRetryPayment($order));
        $this->assertFalse(resolve(PaymentActions::class)->canRetry($order));

        resolve(CancelOrderWithProviders::class)->handle($order);

        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);
        Exceptions::assertReported(static fn (\RuntimeException $e): bool => str_contains($e->getMessage(), 'live Xendit invoice inv_live'));
        Http::assertNothingSent();
    }

    public function test_without_a_key_no_pay_link_is_shown(): void
    {
        $order = $this->xenditOrder('inv_1');
        $this->assertNotNull(resolve(PaymentActions::class)->forOrder($order));

        config()->set('xendit.secret_key', '');

        $this->assertNull(resolve(PaymentActions::class)->forOrder($order->refresh()));
        $this->assertFalse(resolve(PaymentActions::class)->canRetry($order));
        $this->assertNull(resolve(PaymentActions::class)->reconciler($order));
    }

    public function test_the_invoice_duration_is_kept_within_xendit_limits(): void
    {
        config()->set('xendit.invoice_duration', 999999999);
        $order = Order::factory()->create([
            'price_amount' => 50000,
            'currency_code' => 'IDR',
            'status' => OrderStatus::New,
            'payment_status' => PaymentStatus::Pending,
            'payment_method_id' => $this->xenditPaymentMethod('QRIS')->id,
        ]);

        Payment::driver(XenditDriver::CODE)->initiatePayment(50000, 'IDR', ['order' => $order, 'channel_code' => 'QRIS']);

        Http::assertSent(static fn (Request $request): bool => $request->data()['invoice_duration'] === 31536000
            && $request->data()['payment_methods'] === ['QRIS']);
    }
}
