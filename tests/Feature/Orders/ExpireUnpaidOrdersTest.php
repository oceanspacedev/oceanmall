<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Console\Commands\ExpireUnpaidOrders;
use App\Models\User;
use App\Payment\Contracts\ExpiresPayments;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Order;
use Shopper\Payment\DataTransferObjects\PaymentResult;
use Shopper\Payment\DataTransferObjects\WebhookResult;
use Shopper\Payment\Drivers\Driver;
use Shopper\Payment\Enum\TransactionStatus;
use Shopper\Payment\Enum\TransactionType;
use Shopper\Payment\Facades\Payment;
use Shopper\Payment\Models\PaymentTransaction;
use Tests\TestCase;

/**
 * orders:expire-unpaid follows the payment operator switch, and unpaid
 * orders of a driver that is switched off or lost its keys still expire
 * (locally, after a grace period past their stored expiry).
 */
final class ExpireUnpaidOrdersTest extends TestCase
{
    use RefreshDatabase;

    private ExpiringGatewayDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake();

        $this->driver = new ExpiringGatewayDriver;
        $driver = $this->driver;
        Payment::extend('expiring', static fn (): ExpiringGatewayDriver => $driver);
        config()->set('shopper.payment.drivers.expiring.enabled', true);
    }

    public function test_a_ready_driver_expires_its_orders_through_the_provider(): void
    {
        $order = $this->unpaidOrder(expiresAt: now()->subMinute());

        $this->artisan('orders:expire-unpaid')->assertSuccessful();

        $this->assertSame([$order->id], $this->driver->expired);
    }

    public function test_a_switched_off_driver_is_never_asked_and_its_orders_expire_locally_after_the_grace_period(): void
    {
        config()->set('shopper.payment.drivers.expiring.enabled', false);
        $recent = $this->unpaidOrder(expiresAt: now()->subHour());
        $old = $this->unpaidOrder(expiresAt: now()->subHours(ExpireUnpaidOrders::LOCAL_GRACE_HOURS + 1));

        Artisan::call('orders:expire-unpaid');
        $this->assertStringContainsString('Expired 1 order(s); skipped 1.', Artisan::output());

        $this->assertSame([], $this->driver->expired, 'A switched-off driver is not called.');
        $this->assertSame(OrderStatus::New, $recent->fresh()->status);
        $this->assertSame(OrderStatus::Cancelled, $old->fresh()->status);
        $this->assertSame(PaymentStatus::Voided, $old->fresh()->payment_status);
        Http::assertNothingSent();
    }

    public function test_an_unconfigured_driver_still_expires_its_orders_locally(): void
    {
        $this->driver->configured = false;
        $order = $this->unpaidOrder(expiresAt: now()->subHours(ExpireUnpaidOrders::LOCAL_GRACE_HOURS + 1));

        $this->artisan('orders:expire-unpaid')->assertSuccessful();

        $this->assertSame([], $this->driver->expired);
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }

    public function test_schedule_follows_the_operator_switch_and_pending_orders(): void
    {
        $this->assertTrue($this->scheduled()->filtersPass($this->app), 'Ready driver.');

        config()->set('shopper.payment.drivers.expiring.enabled', false);
        $this->assertFalse($this->scheduled()->filtersPass($this->app), 'Switched off, nothing pending.');

        $this->unpaidOrder(expiresAt: now()->subDay());
        $this->assertTrue($this->scheduled()->filtersPass($this->app), 'Switched off with unpaid orders to expire.');
    }

    private function unpaidOrder(CarbonInterface $expiresAt): Order
    {
        $order = Order::factory()->create([
            'customer_id' => User::factory()->create()->id,
            'status' => OrderStatus::New,
            'payment_status' => PaymentStatus::Pending,
            'price_amount' => 10000,
            'currency_code' => 'IDR',
            'created_at' => now()->subDays(3),
        ]);

        PaymentTransaction::query()->create([
            'order_id' => $order->id,
            'reference' => 'exp_'.$order->id,
            'driver' => 'expiring',
            'type' => TransactionType::Initiate,
            'amount' => 10000,
            'currency_code' => 'IDR',
            'status' => TransactionStatus::Pending,
            'metadata' => ['expires_at' => $expiresAt->toIso8601String()],
        ]);

        return $order;
    }

    private function scheduled(): ScheduledEvent
    {
        $event = collect(app(Schedule::class)->events())
            ->first(static fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'orders:expire-unpaid'));

        $this->assertInstanceOf(ScheduledEvent::class, $event);

        return $event;
    }
}

final class ExpiringGatewayDriver extends Driver implements ExpiresPayments
{
    public bool $configured = true;

    /** @var list<int> */
    public array $expired = [];

    public function code(): string
    {
        return 'expiring';
    }

    public function name(): string
    {
        return 'Expiring';
    }

    public function logo(): ?string
    {
        return null;
    }

    public function isConfigured(): bool
    {
        return $this->configured;
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
        return new PaymentResult(success: true, status: 'pending', amount: $amount);
    }

    public function handleWebhook(array $payload, array $headers = []): WebhookResult
    {
        return WebhookResult::ignored();
    }

    public function paymentExpiresAt(Order $order): ?CarbonInterface
    {
        $expiry = data_get(PaymentTransaction::query()->where('order_id', $order->id)->first()?->metadata, 'expires_at');

        return is_string($expiry) ? Carbon::parse($expiry) : null;
    }

    public function canExpireUnpaidOrder(Order $order): bool
    {
        return true;
    }

    public function expireUnpaidOrder(Order $order, string $reason = 'Payment expired'): Order
    {
        $this->expired[] = $order->id;

        return $order;
    }
}
