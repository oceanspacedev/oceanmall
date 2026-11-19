<?php

declare(strict_types=1);

namespace Tests\Feature\Xendit;

use App\Actions\Orders\CancelOrderWithProviders;
use App\Actions\Orders\RetryOrderPayment;
use App\Console\Commands\ExpireUnpaidOrders;
use App\Integrations\Xendit\Payment\XenditDriver;
use App\Payment\Contracts\ReconcilesPayments;
use App\Payment\Exceptions\PaymentRetryRefused;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Payment\Enum\TransactionStatus;
use Shopper\Payment\Enum\TransactionType;
use Shopper\Payment\Facades\Payment;
use Shopper\Payment\Models\PaymentTransaction;
use Tests\Feature\Xendit\Concerns\FakesXendit;
use Tests\TestCase;

/**
 * Reconcile, expire (orders:expire-unpaid / staff cancel) and retry of a
 * Xendit invoice through the store's generic payment paths.
 */
final class XenditLifecycleTest extends TestCase
{
    use FakesXendit;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureXendit();
        $this->fakeXenditApi();
    }

    public function test_reconcile_pulls_the_invoice_status(): void
    {
        $order = $this->xenditOrder('inv_1');
        $this->putXenditInvoice('inv_1');
        $driver = Payment::driver(XenditDriver::CODE);

        $this->assertSame(ReconcilesPayments::PENDING, $driver->reconcilePayment($order));

        $this->xenditInvoices['inv_1']['status'] = 'PAID';
        $this->assertSame(ReconcilesPayments::PAID, $driver->reconcilePayment($order->refresh()));
        $this->assertSame(PaymentStatus::Paid, $order->refresh()->payment_status);

        Exceptions::fake();
        $this->xenditUnreachable[] = 'inv_x';
        $other = $this->xenditOrder('inv_x');
        $other->forceFill(['number' => 'ORD-1002'])->save();
        $this->assertSame(ReconcilesPayments::LOOKUP_FAILED, $driver->reconcilePayment($other));
    }

    public function test_expire_unpaid_expires_the_invoice_at_xendit_and_cancels_the_order(): void
    {
        $order = $this->xenditOrder('inv_1', 150000, ['expiry_date' => now()->subMinute()->toIso8601String()]);
        $this->putXenditInvoice('inv_1');

        $this->artisan('orders:expire-unpaid')->assertSuccessful();

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(PaymentStatus::Voided, $order->payment_status);
        $this->assertSame('EXPIRED', $this->xenditInvoices['inv_1']['status']);
        Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://api.xendit.co/invoices/inv_1/expire!');
    }

    public function test_expire_unpaid_settles_an_invoice_paid_just_before_expiry(): void
    {
        $order = $this->xenditOrder('inv_1', 150000, ['expiry_date' => now()->subMinute()->toIso8601String()]);
        $this->putXenditInvoice('inv_1', ['status' => 'PAID', 'paid_amount' => 150000]);

        $this->artisan('orders:expire-unpaid')->assertSuccessful();

        $order->refresh();
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertNotSame(OrderStatus::Cancelled, $order->status);
        Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), 'expire!'));
    }

    public function test_expire_unpaid_leaves_unexpired_invoices_and_unreachable_xendit_alone(): void
    {
        Exceptions::fake();
        $fresh = $this->xenditOrder('inv_1');
        $this->putXenditInvoice('inv_1');

        $this->artisan('orders:expire-unpaid')->assertSuccessful();
        $this->assertSame(OrderStatus::New, $fresh->refresh()->status);

        $fresh->forceFill(['metadata' => json_encode(['xendit' => ['invoice_id' => 'inv_1', 'expiry_date' => now()->subMinute()->toIso8601String()]])])->save();
        $this->xenditUnreachable[] = 'inv_1';

        $this->artisan('orders:expire-unpaid')->assertSuccessful();
        $this->assertSame(OrderStatus::New, $fresh->refresh()->status, 'Unverifiable: retried next run, never cancelled blind.');
    }

    public function test_without_a_key_old_orders_expire_locally_after_the_grace_period(): void
    {
        $order = $this->xenditOrder('inv_1', 150000, ['expiry_date' => now()->subHours(2)->toIso8601String()]);
        config()->set('xendit.secret_key', '');

        $this->artisan('orders:expire-unpaid')->assertSuccessful();
        $this->assertSame(OrderStatus::New, $order->refresh()->status);

        $this->travel(ExpireUnpaidOrders::LOCAL_GRACE_HOURS)->hours();
        $this->artisan('orders:expire-unpaid')->assertSuccessful();

        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);
        Http::assertNothingSent();
    }

    public function test_staff_cancel_expires_the_open_invoice_first(): void
    {
        $order = $this->xenditOrder('inv_1');
        $this->putXenditInvoice('inv_1');

        resolve(CancelOrderWithProviders::class)->handle($order);

        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);
        $this->assertSame('EXPIRED', $this->xenditInvoices['inv_1']['status']);
    }

    public function test_retry_replaces_an_expired_invoice_with_a_new_external_id(): void
    {
        $order = $this->xenditOrder('inv_1', 150000, ['expiry_date' => now()->subMinute()->toIso8601String()]);
        $this->putXenditInvoice('inv_1', ['status' => 'EXPIRED']);

        $action = resolve(RetryOrderPayment::class)->handle($order);

        $this->assertSame('redirect', $action['kind']);
        $this->assertSame('https://checkout-staging.xendit.co/web/inv_new_1', $action['data']['url']);
        $this->assertSame('ORD-1001-2', $this->xenditInvoices['inv_new_1']['external_id']);

        $state = json_decode((string) $order->refresh()->getAttribute('metadata'), true)['xendit'];
        $this->assertSame('inv_new_1', $state['invoice_id']);
        $this->assertSame(2, $state['attempt']);

        $this->assertSame(TransactionStatus::Failed, PaymentTransaction::query()->where('reference', 'inv_1')->sole()->status);
        $this->assertSame(2, PaymentTransaction::query()->where('order_id', $order->id)->where('type', TransactionType::Initiate)->count());
        Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api.xendit.co/v2/invoices'
            && $request->data()['payment_methods'] === ['BCA']);

        // A late payment of the new invoice settles the order through the webhook.
        $this->xenditInvoices['inv_new_1']['status'] = 'PAID';
        $this->postXenditCallback(['id' => 'inv_new_1', 'status' => 'PAID'])->assertJsonPath('status', 'handled');
        $this->assertSame(PaymentStatus::Paid, $order->refresh()->payment_status);
    }

    public function test_retry_is_refused_when_the_previous_invoice_was_paid(): void
    {
        $order = $this->xenditOrder('inv_1', 150000, ['expiry_date' => now()->subMinute()->toIso8601String(), 'status' => 'EXPIRED']);
        $this->putXenditInvoice('inv_1', ['status' => 'PAID', 'paid_amount' => 150000]);

        try {
            Payment::driver(XenditDriver::CODE)->retryPayment($order);
            $this->fail('A paid invoice must not be replaced.');
        } catch (PaymentRetryRefused $e) {
            $this->assertSame(PaymentRetryRefused::ALREADY_PAID, $e->reason);
        }

        $this->assertSame(PaymentStatus::Paid, $order->refresh()->payment_status);
        Http::assertNotSent(static fn (Request $request): bool => $request->method() === 'POST');
    }

    public function test_retry_is_refused_while_the_invoice_can_still_be_paid(): void
    {
        $order = $this->xenditOrder('inv_1');
        $this->putXenditInvoice('inv_1');

        $this->expectException(PaymentRetryRefused::class);

        Payment::driver(XenditDriver::CODE)->retryPayment($order);
    }
}
