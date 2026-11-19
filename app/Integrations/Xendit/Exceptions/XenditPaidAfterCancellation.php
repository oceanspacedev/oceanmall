<?php

declare(strict_types=1);

namespace App\Integrations\Xendit\Exceptions;

use RuntimeException;

/**
 * Xendit confirmed an invoice as paid for an order we already cancelled
 * (stock released, customer notified). The money has to be refunded or the
 * order restored by hand, so XenditServiceProvider reports this at critical
 * level.
 */
final class XenditPaidAfterCancellation extends RuntimeException
{
    public function __construct(
        public readonly string $orderNumber,
        public readonly string $invoiceId,
    ) {
        parent::__construct(sprintf(
            'Xendit invoice %s paid after order %s was cancelled: refund or restore manually.',
            $invoiceId,
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
            'invoice_id' => $this->invoiceId,
        ];
    }
}
