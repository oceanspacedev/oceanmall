<?php

declare(strict_types=1);

namespace App\Payment\Contracts;

use Shopper\Core\Models\Order;

/**
 * A payment driver that can pull an order's payment status from the provider
 * (for when a webhook was missed) and settle the order.
 */
interface ReconcilesPayments
{
    /** The provider confirmed payment; the order is (now) marked paid. */
    public const PAID = 'paid';

    /** The provider says the payment is still open. */
    public const PENDING = 'pending';

    /** The order has no payment with this provider. */
    public const NO_PAYMENT = 'no_payment';

    /** The provider could not be reached; try again later, do not cancel. */
    public const LOOKUP_FAILED = 'lookup_failed';

    /** Paid, but with an amount that does not match the order: a human decides. */
    public const AMOUNT_MISMATCH = 'amount_mismatch';

    /**
     * @return self::PAID|self::PENDING|self::NO_PAYMENT|self::LOOKUP_FAILED|self::AMOUNT_MISMATCH|string
     */
    public function reconcilePayment(Order $order): string;
}
