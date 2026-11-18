<?php

declare(strict_types=1);

namespace App\Payment\Exceptions;

use RuntimeException;

/**
 * A payment driver (RetriesPayments) refuses to replace an order's payment
 * for a reason the customer should see. Generic code maps $reason to copy;
 * any other exception from a driver is treated as a temporary failure.
 */
final class PaymentRetryRefused extends RuntimeException
{
    public const ALREADY_PAID = 'already_paid';

    public const NOT_ELIGIBLE = 'not_eligible';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function alreadyPaid(string $message = 'Order is already paid.'): self
    {
        return new self(self::ALREADY_PAID, $message);
    }

    public static function notEligible(string $message = 'Order is not eligible for payment retry.'): self
    {
        return new self(self::NOT_ELIGIBLE, $message);
    }
}
