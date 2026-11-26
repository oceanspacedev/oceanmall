<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Orders\CancelOrderWithProviders;
use App\Payment\Contracts\ExpiresPayments;
use App\Payment\PaymentMethodCatalog;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Order;
use Shopper\Payment\Contracts\PaymentDriver;
use Shopper\Payment\Enum\TransactionStatus;
use Shopper\Payment\Facades\Payment;
use Throwable;

/**
 * Cancel unpaid orders whose payment expired, through the payment driver
 * that issued the payment (ExpiresPayments).
 *
 * - Driver ready (operator switch on + configured) and able to verify the
 *   order: the driver re-checks the provider first, cancels the remote
 *   payment and releases stock, from the payment's own expiry.
 * - Driver switched off or unconfigured (keys removed), or unable to verify
 *   this order: nothing can confirm the order is still unpaid, so it is
 *   cancelled locally (stock released, no provider call) only once
 *   LOCAL_GRACE_HOURS have passed since its stored expiry, leaving time for
 *   a transfer made just before expiry to be settled by staff. Money that
 *   still arrives afterwards is reported as paid-after-cancel by the
 *   provider's webhook handling.
 *
 * Without any registered driver that expires payments this is a no-op.
 */
final class ExpireUnpaidOrders extends Command
{
    /** Hours past the stored payment expiry before an unverifiable order is cancelled locally. */
    public const LOCAL_GRACE_HOURS = 24;

    protected $signature = 'orders:expire-unpaid {--limit=100 : Max orders to expire}';

    /** @var list<string> */
    protected $aliases = ['komerce:expire-unpaid-orders'];

    protected $description = 'Cancel unpaid orders past their payment expiry and release stock';

    public function handle(CancelOrderWithProviders $cancel): int
    {
        $drivers = self::expiringDrivers();

        if ($drivers->isEmpty()) {
            $this->components->info('No payment driver expires payments; pending orders are left for manual review.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $now = now();
        $expired = 0;
        $skipped = 0;
        $catalog = new PaymentMethodCatalog;

        foreach ($drivers as $code => $driver) {
            // Finishing issued payments: also for a gateway the store no
            // longer sells through, while its key is set.
            $ready = $catalog->driverServiceable($code);
            $orders = self::pendingOrders($code)->orderBy('id')->limit($limit)->get();

            foreach ($orders as $order) {
                $expiresAt = $this->expiresAt($driver, $order);

                try {
                    if ($ready && $driver->canExpireUnpaidOrder($order)) {
                        if ($expiresAt === null || $expiresAt->gt($now)) {
                            $skipped++;

                            continue;
                        }

                        $driver->expireUnpaidOrder($order, 'Payment expired');
                        $expired++;

                        continue;
                    }

                    if ($expiresAt === null || $expiresAt->copy()->addHours(self::LOCAL_GRACE_HOURS)->gt($now)) {
                        $skipped++;

                        continue;
                    }

                    $cancel->handle($order, throughPaymentProvider: false);
                    $expired++;
                } catch (Throwable $e) {
                    report($e);
                    $this->warn("Order #{$order->id}: {$e->getMessage()}");
                }
            }

            // The gateway never issued a payment for these (it was down,
            // refused the amount, timed out): nothing can be paid, so there
            // is nothing to check at the provider and no grace period. A day
            // after the order was placed the customer has not retried: the
            // stock goes back on sale.
            foreach (self::neverInitiated($code)->orderBy('id')->limit($limit)->get() as $order) {
                $due = $order->created_at?->copy()->addDay();

                if ($due === null || $due->gt($now)) {
                    $skipped++;

                    continue;
                }

                try {
                    // The list was read a moment ago: a "pay again" that
                    // went through since gave the order a payment.
                    if ($order->paymentTransactions()->where('status', '!=', TransactionStatus::Failed->value)->exists()) {
                        $skipped++;

                        continue;
                    }

                    $cancel->handle($order, throughPaymentProvider: false);
                    $expired++;
                } catch (Throwable $e) {
                    report($e);
                    $this->warn("Order #{$order->id}: {$e->getMessage()}");
                }
            }
        }

        $this->info("Expired {$expired} order(s); skipped {$skipped}.");

        return self::SUCCESS;
    }

    /**
     * Schedule gate: a ready driver expires payments, or a registered one
     * (switched off / unconfigured) still has pending orders to expire
     * locally. A keyless store with no such orders schedules no work.
     */
    public static function hasWork(): bool
    {
        $catalog = new PaymentMethodCatalog;

        foreach (self::expiringDrivers() as $code => $driver) {
            if ($catalog->driverServiceable($code) || self::pendingOrders($code)->exists() || self::neverInitiated($code)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every registered driver that expires payments, whatever its switch or
     * keys: its existing unpaid orders still have to expire.
     *
     * @return Collection<string, PaymentDriver&ExpiresPayments>
     */
    private static function expiringDrivers(): Collection
    {
        $drivers = [];

        foreach (Payment::availableDrivers() as $code) {
            try {
                $driver = Payment::driver($code);

                if ($driver instanceof ExpiresPayments) {
                    $drivers[$code] = $driver;
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return collect($drivers);
    }

    /**
     * @return Builder<Order>
     */
    private static function pendingOrders(string $code): Builder
    {
        return Order::query()
            ->where('payment_status', PaymentStatus::Pending)
            ->where('status', OrderStatus::New)
            ->whereHas('paymentTransactions', static function ($query) use ($code): void {
                $query->where('driver', $code);
            });
    }

    /**
     * Unpaid orders picked to be paid through this driver for which no
     * payment was ever recorded: the initiation failed after the order (and
     * its stock reservation) was created.
     *
     * @return Builder<Order>
     */
    private static function neverInitiated(string $code): Builder
    {
        return Order::query()
            ->where('payment_status', PaymentStatus::Pending)
            ->where('status', OrderStatus::New)
            ->whereDoesntHave('paymentTransactions', static function ($query): void {
                $query->where('status', '!=', TransactionStatus::Failed->value);
            })
            ->whereHas('paymentMethod', static function ($query) use ($code): void {
                $query->where('driver', $code);
            });
    }

    /**
     * The payment's stored expiry (read from the order / transaction, no
     * provider call), else one day after the order was placed.
     */
    private function expiresAt(ExpiresPayments $driver, Order $order): ?CarbonInterface
    {
        try {
            $expiresAt = $driver->paymentExpiresAt($order);
        } catch (Throwable $e) {
            report($e);
            $expiresAt = null;
        }

        return $expiresAt ?? $order->created_at?->copy()->addDay();
    }
}
