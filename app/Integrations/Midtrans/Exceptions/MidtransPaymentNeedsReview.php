<?php

declare(strict_types=1);

namespace App\Integrations\Midtrans\Exceptions;

use RuntimeException;

/**
 * Money reached Midtrans for an order that cannot take it: the order was
 * cancelled first, or another payment attempt already paid it. Never revives
 * or double-applies anything; reported at critical level
 * (MidtransServiceProvider) so a human refunds the customer.
 */
final class MidtransPaymentNeedsReview extends RuntimeException
{
    public function __construct(
        public readonly string $orderNumber,
        public readonly string $midtransOrderId,
        public readonly string $reason,
    ) {
        parent::__construct(sprintf(
            'Midtrans payment %s for order %s needs a manual refund review: %s.',
            $midtransOrderId,
            $orderNumber,
            $reason,
        ));
    }
}
