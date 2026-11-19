<?php

declare(strict_types=1);

namespace App\Integrations\Xendit\Actions;

use App\Actions\Orders\CancelOrderWithProviders;
use App\Integrations\Xendit\Exceptions\XenditApiException;
use App\Integrations\Xendit\Services\XenditClient;
use App\Integrations\Xendit\Support\XenditOrderPayment;
use App\Integrations\Xendit\Xendit;
use RuntimeException;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Order;

/**
 * Cancel an unpaid Xendit order (orders:expire-unpaid, staff cancel, or the
 * EXPIRED callback of its current invoice):
 *
 * 1. settle first: the current invoice is read back from Xendit and a
 *    PAID / SETTLED one marks the order paid instead (a transfer can land
 *    seconds before expiry);
 * 2. a still PENDING invoice is expired at Xendit so it cannot be paid
 *    after the order is gone;
 * 3. the order itself is cancelled by the generic CancelOrderWithProviders
 *    (stock release, customer notification, Shopper's OrderCancelled).
 *
 * When Xendit cannot be asked (lookup or expire fails) nothing is cancelled
 * and a RuntimeException is thrown, so the next run retries.
 */
final readonly class CancelUnpaidXenditOrder
{
    public function __construct(
        private ConfirmXenditInvoice $confirm,
        private XenditClient $client,
        private CancelOrderWithProviders $cancel,
    ) {}

    public function handle(Order $order, string $reason = 'Payment expired'): Order
    {
        if ($order->payment_status === PaymentStatus::Paid || $order->status === OrderStatus::Cancelled) {
            return $order;
        }

        $invoiceId = XenditOrderPayment::currentInvoiceId($order);

        // An invoice created with the other (test / live) key cannot be read
        // or expired with this one. orders:expire-unpaid never gets here for
        // it (canExpireUnpaidOrder() is false, it waits for the local grace
        // period); a staff cancel goes ahead locally, and a still payable
        // live invoice is reported so someone expires it in the dashboard.
        if ($invoiceId !== null && Xendit::configured() && ! XenditOrderPayment::modeMatches($order)) {
            if (XenditOrderPayment::mode($order) === Xendit::MODE_LIVE) {
                report(new RuntimeException("Order {$order->number} was cancelled without expiring its live Xendit invoice {$invoiceId} (a test key is configured): expire it in the Xendit Dashboard."));
            }
        } elseif ($invoiceId !== null && Xendit::configured()) {
            ['verdict' => $verdict] = $this->confirm->handle($invoiceId);

            if (in_array($verdict, [ConfirmXenditInvoice::LOOKUP_FAILED, ConfirmXenditInvoice::FOREIGN_INVOICE], true)) {
                throw new RuntimeException("Could not verify Xendit invoice {$invoiceId} for order {$order->number} ({$verdict}); not cancelling.");
            }

            // Paid now (or paid with the wrong amount: already reported, a human decides).
            if (in_array($verdict, [
                ConfirmXenditInvoice::HANDLED,
                ConfirmXenditInvoice::ALREADY_PROCESSED,
                ConfirmXenditInvoice::AMOUNT_MISMATCH,
            ], true)) {
                return $order->refresh();
            }

            if ($verdict === ConfirmXenditInvoice::NOT_PAID) {
                $this->expireRemote($order, $invoiceId);
            }
        }

        $order->refresh();

        if ($order->payment_status === PaymentStatus::Paid || $order->status === OrderStatus::Cancelled) {
            return $order;
        }

        $cancelled = $this->cancel->handle($order, throughPaymentProvider: false);

        if ($cancelled->status === OrderStatus::Cancelled) {
            XenditOrderPayment::write($cancelled, ['cancel_reason' => $reason]);
        }

        return $cancelled->refresh();
    }

    private function expireRemote(Order $order, string $invoiceId): void
    {
        try {
            $invoice = $this->client->expireInvoice($invoiceId);
        } catch (XenditApiException $e) {
            // Paid or expired in the meantime: Xendit refuses to expire it.
            // Read it again and settle instead of guessing.
            ['verdict' => $verdict] = $this->confirm->handle($invoiceId);

            // Expired: cancel below. Paid: the order is now paid and the
            // caller's re-check returns it untouched.
            if (in_array($verdict, [
                ConfirmXenditInvoice::EXPIRED,
                ConfirmXenditInvoice::SUPERSEDED,
                ConfirmXenditInvoice::HANDLED,
                ConfirmXenditInvoice::ALREADY_PROCESSED,
            ], true)) {
                return;
            }

            throw new RuntimeException("Could not expire Xendit invoice {$invoiceId} for order {$order->number}: {$e->getMessage()}", 0, $e);
        }

        XenditOrderPayment::write($order, ['status' => XenditOrderPayment::status($invoice) ?: 'EXPIRED']);
    }
}
