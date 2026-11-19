<?php

declare(strict_types=1);

namespace Tests\Feature\Midtrans;

use App\Actions\Orders\CancelOrderWithProviders;
use App\Actions\Orders\RetryOrderPayment;
use App\Actions\Orders\SyncOrderPayment;
use App\Integrations\Midtrans\Actions\RetryMidtransPayment;
use App\Payment\Exceptions\PaymentRetryRefused;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Payment\Enum\TransactionStatus;
use Shopper\Payment\Enum\TransactionType;
use Shopper\Payment\Models\PaymentTransaction;
use Tests\TestCase;

/**
 * Reconcile ("already paid? check status"), retry ("pay again") and expiry
 * (orders:expire-unpaid / staff cancel) through the store's generic actions.
 */
final class MidtransLifecycleTest extends TestCase
{
    use InteractsWithMidtrans;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withMidtrans();
    }

    public function test_check_status_settles_a_paid_order(): void
    {
        $order = $this->initiatedOrder();
        Http::fake([self::API.'*' => Http::response($this->statusBody('ORD-2026-0001', 'settlement'))]);

        $this->assertSame(SyncOrderPayment::HANDLED, resolve(SyncOrderPayment::class)->handle($order));
        $this->assertSame(PaymentStatus::Paid, $order->refresh()->payment_status);
    }

    public function test_check_status_before_a_method_was_picked_is_still_pending(): void
    {
        $order = $this->initiatedOrder();
        Http::fake([self::API.'*' => Http::response($this->notFoundBody(), 404)]);

        $this->assertSame(SyncOrderPayment::NOT_PAID, resolve(SyncOrderPayment::class)->handle($order));
        $this->assertSame(PaymentStatus::Pending, $order->refresh()->payment_status);
    }

    public function test_retry_creates_a_new_attempt_after_the_previous_one_expired(): void
    {
        $order = $this->initiatedOrder(expiresAt: now()->subMinute()->toIso8601String());
        $expired = $this->statusBody('ORD-2026-0001', 'expire');
        $expired['status_code'] = '407';

        Http::fake([
            self::API.'ORD-2026-0001/status' => Http::response($expired),
            self::SNAP_URL => Http::response(['token' => 'snap-token-2', 'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/snap-token-2'], 201),
        ]);

        $action = resolve(RetryOrderPayment::class)->handle($order);

        $this->assertSame('redirect', $action['kind']);
        $this->assertSame('https://app.sandbox.midtrans.com/snap/v4/redirection/snap-token-2', $action['data']['url']);
        $this->assertSame('ORD-2026-0001~2', $action['data']['payment_id']);

        Http::assertSent(fn (Request $request): bool => $request->url() === self::SNAP_URL
            && $request->data()['transaction_details']['order_id'] === 'ORD-2026-0001~2'
            && $request->data()['enabled_payments'] === ['bca_va']);
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/expire'));

        $this->assertSame(
            ['ORD-2026-0001' => TransactionStatus::Failed, 'ORD-2026-0001~2' => TransactionStatus::Pending],
            PaymentTransaction::query()->where('order_id', $order->id)->orderBy('id')->get()
                ->mapWithKeys(static fn (PaymentTransaction $t): array => [$t->reference => $t->status])->all(),
        );
    }

    public function test_retry_closes_a_previous_attempt_midtrans_still_holds_open(): void
    {
        $order = $this->initiatedOrder(expiresAt: now()->subMinute()->toIso8601String());

        Http::fake([
            self::API.'ORD-2026-0001/status' => Http::response($this->statusBody('ORD-2026-0001', 'pending')),
            self::API.'ORD-2026-0001/expire' => Http::response($this->statusBody('ORD-2026-0001', 'expire', extra: ['status_code' => '407'])),
            self::SNAP_URL => Http::response(['token' => 't2', 'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/t2'], 201),
        ]);

        // expire answers 407 + transaction_status expire: closed.
        $this->assertNotNull(resolve(RetryOrderPayment::class)->handle($order));

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->url() === self::API.'ORD-2026-0001/expire');
    }

    public function test_retry_is_refused_when_the_previous_attempt_was_paid_after_all(): void
    {
        $order = $this->initiatedOrder(expiresAt: now()->subMinute()->toIso8601String());
        Http::fake([self::API.'*' => Http::response($this->statusBody('ORD-2026-0001', 'settlement'))]);

        try {
            resolve(RetryOrderPayment::class)->handle($order);
            $this->fail('Expected PaymentRetryRefused.');
        } catch (PaymentRetryRefused) {
            // refused: shown to the customer
        }

        $this->assertSame(PaymentStatus::Paid, $order->refresh()->payment_status);
        Http::assertNotSent(fn (Request $request): bool => $request->url() === self::SNAP_URL);
    }

    public function test_a_concurrent_retry_of_the_same_order_is_refused_without_calling_midtrans(): void
    {
        $order = $this->initiatedOrder(expiresAt: now()->subMinute()->toIso8601String());
        Http::fake();
        $held = Cache::lock('midtrans:retry:'.$order->id, 60);
        $this->assertTrue($held->get());

        try {
            resolve(RetryMidtransPayment::class)->handle($order);
            $this->fail('Expected PaymentRetryRefused.');
        } catch (PaymentRetryRefused) {
            // the first click is still creating the new payment
        } finally {
            $held->release();
        }

        Http::assertNothingSent();
        $this->assertSame(1, PaymentTransaction::query()->where('order_id', $order->id)->count());
    }

    public function test_a_status_lookup_without_a_transaction_status_never_cancels(): void
    {
        $order = $this->initiatedOrder(expiresAt: now()->subMinutes(5)->toIso8601String());
        Http::fake([self::API.'*' => Http::response(['status_code' => '500', 'status_message' => 'internal error'])]);

        $this->artisan('orders:expire-unpaid')->assertSuccessful();

        $order->refresh();
        $this->assertSame(OrderStatus::New, $order->status);
        $this->assertSame(PaymentStatus::Pending, $order->payment_status);
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/expire'));
    }

    public function test_retry_is_not_offered_while_the_snap_page_is_open(): void
    {
        $order = $this->initiatedOrder();
        Http::fake();

        $this->expectException(PaymentRetryRefused::class);

        try {
            resolve(RetryOrderPayment::class)->handle($order);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_expiry_schedule_cancels_an_unpaid_order_after_closing_its_attempt(): void
    {
        $order = $this->initiatedOrder(expiresAt: now()->subMinutes(5)->toIso8601String());

        Http::fake([
            self::API.'ORD-2026-0001/status' => Http::sequence()
                ->push($this->statusBody('ORD-2026-0001', 'pending'))
                ->push($this->statusBody('ORD-2026-0001', 'pending')),
            self::API.'ORD-2026-0001/expire' => Http::response($this->statusBody('ORD-2026-0001', 'expire', extra: ['status_code' => '200'])),
        ]);

        $this->artisan('orders:expire-unpaid')->assertSuccessful();

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(PaymentStatus::Voided, $order->payment_status);
        $this->assertSame(TransactionStatus::Failed, PaymentTransaction::query()->where('order_id', $order->id)->value('status'));
        Http::assertSent(fn (Request $request): bool => $request->url() === self::API.'ORD-2026-0001/expire');
    }

    public function test_expiry_schedule_leaves_orders_before_their_expiry(): void
    {
        $order = $this->initiatedOrder();
        Http::fake();

        $this->artisan('orders:expire-unpaid')->assertSuccessful();

        $this->assertSame(OrderStatus::New, $order->refresh()->status);
        Http::assertNothingSent();
    }

    public function test_expiry_settles_a_payment_that_landed_just_before(): void
    {
        $order = $this->initiatedOrder(expiresAt: now()->subMinutes(5)->toIso8601String());
        Http::fake([self::API.'*' => Http::response($this->statusBody('ORD-2026-0001', 'settlement'))]);

        $this->artisan('orders:expire-unpaid')->assertSuccessful();

        $order->refresh();
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertNotSame(OrderStatus::Cancelled, $order->status);
    }

    public function test_expiry_never_cancels_on_a_failed_lookup(): void
    {
        Exceptions::fake();
        $order = $this->initiatedOrder(expiresAt: now()->subMinutes(5)->toIso8601String());
        Http::fake([self::API.'*' => Http::response('down', 500)]);

        $this->artisan('orders:expire-unpaid')->assertSuccessful();

        $this->assertSame(OrderStatus::New, $order->refresh()->status);
        $this->assertSame(PaymentStatus::Pending, $order->payment_status);
    }

    public function test_staff_cancel_goes_through_midtrans_and_cancels_a_snap_page_without_transaction(): void
    {
        $order = $this->initiatedOrder();
        Http::fake([self::API.'*' => Http::response($this->notFoundBody(), 404)]);

        $cancelled = resolve(CancelOrderWithProviders::class)->handle($order);

        $this->assertSame(OrderStatus::Cancelled, $cancelled->status);
        $this->assertSame(PaymentStatus::Voided, $cancelled->payment_status);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public function test_keyless_orders_still_expire_locally_after_the_grace_period(): void
    {
        $order = $this->initiatedOrder(expiresAt: now()->subDays(2)->toIso8601String());
        config()->set('midtrans.server_key', '');
        Http::fake();

        $this->artisan('orders:expire-unpaid')->assertSuccessful();

        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);
        Http::assertNothingSent();
        $this->assertSame(TransactionType::Initiate, PaymentTransaction::query()->where('order_id', $order->id)->value('type'));
    }
}
