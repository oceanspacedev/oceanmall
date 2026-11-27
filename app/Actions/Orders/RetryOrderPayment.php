<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Payment\Contracts\RetriesPayments;
use App\Payment\Exceptions\PaymentRetryRefused;
use App\Payment\PaymentActions;
use App\Payment\PaymentReturnUrl;
use RuntimeException;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Order;

/**
 * Customer "pay again" for an unpaid order, routed to the order's own payment
 * driver (RetriesPayments). Manual / COD methods have nothing to retry.
 */
final readonly class RetryOrderPayment
{
    public function __construct(private PaymentActions $payments) {}

    /**
     * @param  array<string, mixed>  $extraContext
     * @return array{driver: string, kind: string, data: array<string, mixed>}|null The new payment action
     *
     * @throws RuntimeException when the order cannot be retried or the driver fails
     */
    public function handle(Order $order, array $extraContext = []): ?array
    {
        if ($order->payment_status === PaymentStatus::Paid) {
            throw PaymentRetryRefused::alreadyPaid();
        }

        $driver = $this->payments->driver($order);

        if (! $driver instanceof RetriesPayments || ! $this->payments->canRetry($order)) {
            throw PaymentRetryRefused::notEligible();
        }

        PaymentReturnUrl::remember(
            $order,
            is_string($extraContext['return_url'] ?? null) ? $extraContext['return_url'] : null,
        );

        try {
            $driver->retryPayment($order);
        } finally {
            PaymentReturnUrl::forget($order);
        }

        return $this->payments->forOrder($order->refresh());
    }
}
