<?php

declare(strict_types=1);

namespace App\Payment\Contracts;

use App\Payment\Exceptions\PaymentRetryRefused;
use Shopper\Core\Models\Order;

/**
 * A payment driver that can replace an unpaid order's payment (an expired VA,
 * a failed charge) so the customer can pay again from the order page.
 */
interface RetriesPayments
{
    public function canRetryPayment(Order $order): bool;

    /**
     * Close the previous provider payment when needed and initiate a new one.
     *
     * @throws PaymentRetryRefused when the order must not be retried (already paid, not eligible): shown to the customer
     * @throws \RuntimeException when the provider failed (shown as a temporary failure)
     */
    public function retryPayment(Order $order): void;
}
