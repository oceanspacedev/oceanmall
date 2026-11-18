<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Payment\Contracts\ReconcilesPayments;
use App\Payment\PaymentActions;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Order;

/**
 * "Already paid? Check status": ask the order's own payment driver
 * (ReconcilesPayments) and settle the order when the provider confirms.
 */
final readonly class SyncOrderPayment
{
    public const ALREADY_PAID = 'already_paid';

    public const HANDLED = 'handled';

    public const NOT_PAID = 'not_paid';

    public const NO_PAYMENT = 'no_payment';

    public function __construct(private PaymentActions $payments) {}

    /**
     * @return self::ALREADY_PAID|self::HANDLED|self::NOT_PAID|self::NO_PAYMENT
     */
    public function handle(Order $order): string
    {
        $order->refresh();

        if ($order->payment_status === PaymentStatus::Paid) {
            return self::ALREADY_PAID;
        }

        $reconciler = $this->payments->reconciler($order);

        if ($reconciler === null) {
            return self::NO_PAYMENT;
        }

        return match ($reconciler->reconcilePayment($order)) {
            ReconcilesPayments::PAID => self::HANDLED,
            ReconcilesPayments::NO_PAYMENT => self::NO_PAYMENT,
            default => self::NOT_PAID,
        };
    }
}
