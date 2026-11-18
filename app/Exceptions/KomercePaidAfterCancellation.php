<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Komerce confirmed a payment for an order we already cancelled (stock released,
 * customer notified). The money has to be refunded or the order restored by
 * hand, so this is reported at critical level (see bootstrap/app.php).
 */
final class KomercePaidAfterCancellation extends RuntimeException
{
    public function __construct(
        public readonly string $orderNumber,
        public readonly string $paymentId,
        public readonly string $provider,
    ) {
        parent::__construct(sprintf(
            'Komerce payment %s captured after order %s was cancelled — refund or restore manually.',
            $paymentId,
            $orderNumber,
        ));
    }

    /**
     * @return array<string, string>
     */
    public function context(): array
    {
        return [
            'order_number' => $this->orderNumber,
            'payment_id' => $this->paymentId,
            'provider' => $this->provider,
        ];
    }
}
