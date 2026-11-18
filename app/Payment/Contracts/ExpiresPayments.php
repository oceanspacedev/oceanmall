<?php

declare(strict_types=1);

namespace App\Payment\Contracts;

use Carbon\CarbonInterface;
use Shopper\Core\Models\Order;

/**
 * A payment driver whose pending payments expire (VA, QRIS...), so unpaid
 * orders can be cancelled and their stock released.
 */
interface ExpiresPayments
{
    /**
     * When the order's pending payment expires, or null when unknown.
     */
    public function paymentExpiresAt(Order $order): ?CarbonInterface;

    /**
     * Whether the provider can confirm right now that the order is still
     * unpaid (its product is configured). When false the order is left for
     * manual review: a transfer may have landed just before expiry.
     */
    public function canExpireUnpaidOrder(Order $order): bool;

    /**
     * Cancel an unpaid order whose payment expired (cancelling the remote
     * payment first). Must not cancel an order the provider reports as paid.
     */
    public function expireUnpaidOrder(Order $order, string $reason = 'Payment expired'): Order;
}
