<?php

declare(strict_types=1);

namespace App\Integrations\Xendit\Actions;

use App\Integrations\Xendit\Exceptions\XenditApiException;
use App\Integrations\Xendit\Payment\XenditDriver;
use App\Integrations\Xendit\Services\XenditClient;
use App\Integrations\Xendit\Support\XenditOrderPayment;
use App\Integrations\Xendit\Xendit;
use App\Payment\Exceptions\PaymentRetryRefused;
use App\Payment\PaymentReturnUrl;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Payment\Enum\TransactionStatus;
use Shopper\Payment\Facades\Payment;
use Shopper\Payment\Models\PaymentTransaction;
use Shopper\Payment\Services\PaymentProcessingService;

/**
 * Replace an unpaid order's Xendit invoice (expired, or never created because
 * initiation failed) with a new one, from the account order page / mobile
 * "pay again".
 *
 * The previous invoice is read back first: a paid one settles the order and
 * the retry is refused (already paid); a still pending one is expired at
 * Xendit before the next is created, so the customer can never pay twice.
 * The new invoice gets external_id "{number}-{attempt}" and its own Initiate
 * PaymentTransaction (through Shopper's PaymentProcessingService).
 */
final readonly class RetryXenditPayment
{
    public function __construct(
        private ConfirmXenditInvoice $confirm,
        private XenditClient $client,
        private PaymentProcessingService $payments,
    ) {}

    public function handle(Order $order): void
    {
        $lock = Cache::lock('xendit:retry:'.$order->id, 15);

        if (! $lock->get()) {
            throw PaymentRetryRefused::notEligible('Permintaan pembayaran lain sedang diproses. Tunggu sebentar.');
        }

        try {
            $order->refresh();

            if ($order->payment_status === PaymentStatus::Paid) {
                throw PaymentRetryRefused::alreadyPaid();
            }

            $order->loadMissing('paymentMethod');

            if (! $order->paymentMethod instanceof PaymentMethod || $order->paymentMethod->driver !== XenditDriver::CODE) {
                throw PaymentRetryRefused::notEligible('Order has no Xendit payment method.');
            }

            $this->closePrevious($order);

            if ($order->refresh()->payment_status === PaymentStatus::Paid) {
                throw PaymentRetryRefused::alreadyPaid();
            }

            $attempt = XenditOrderPayment::currentInvoiceId($order) === null ? 1 : XenditOrderPayment::attempt($order) + 1;
            $metadata = $order->paymentMethod->metadata;
            $metadata = is_string($metadata) ? json_decode($metadata, true) : $metadata;

            $result = $this->payments->initiate($order, array_filter([
                'order' => $order,
                'channel_code' => data_get(is_array($metadata) ? $metadata : [], 'channel_code'),
                'attempt' => $attempt,
                'return_url' => PaymentReturnUrl::get($order),
            ], static fn (mixed $value): bool => $value !== null && $value !== ''));

            if (! $result->success) {
                throw new RuntimeException($result->message ?? 'Unable to create a new Xendit invoice.');
            }

            Payment::driver(XenditDriver::CODE)->afterPaymentInitiated($order, $result);
        } finally {
            $lock->release();
        }
    }

    private function closePrevious(Order $order): void
    {
        $invoiceId = XenditOrderPayment::currentInvoiceId($order);

        if ($invoiceId === null) {
            return;
        }

        if (! XenditOrderPayment::modeMatches($order)) {
            // A live invoice can hold real money and this (test) key cannot
            // check or expire it: never issue a second payable invoice.
            if (XenditOrderPayment::mode($order) === Xendit::MODE_LIVE) {
                throw PaymentRetryRefused::notEligible('The previous Xendit invoice was created in live mode and cannot be checked with the configured key.');
            }

            // A test-mode invoice never carries real money: just replace it.
            $verdict = null;
        } else {
            ['verdict' => $verdict] = $this->confirm->handle($invoiceId);
        }

        match ($verdict) {
            ConfirmXenditInvoice::HANDLED,
            ConfirmXenditInvoice::ALREADY_PROCESSED => throw PaymentRetryRefused::alreadyPaid('The previous Xendit invoice is already paid.'),
            ConfirmXenditInvoice::AMOUNT_MISMATCH,
            ConfirmXenditInvoice::FOREIGN_INVOICE,
            ConfirmXenditInvoice::CANCELLED_ORDER,
            ConfirmXenditInvoice::PAID_AFTER_CANCEL => throw PaymentRetryRefused::notEligible('The previous Xendit invoice needs a manual review.'),
            ConfirmXenditInvoice::LOOKUP_FAILED => throw new RuntimeException("Could not read Xendit invoice {$invoiceId}; try again later."),
            ConfirmXenditInvoice::NOT_PAID => $this->expire($order, $invoiceId),
            default => null, // EXPIRED, SUPERSEDED, NO_TRANSACTION
        };

        PaymentTransaction::query()
            ->where('order_id', $order->id)
            ->where('driver', XenditDriver::CODE)
            ->where('reference', $invoiceId)
            ->where('status', '!=', TransactionStatus::Failed)
            ->get()
            ->each(static fn (PaymentTransaction $transaction): bool => $transaction->update(['status' => TransactionStatus::Failed]));
    }

    private function expire(Order $order, string $invoiceId): void
    {
        try {
            $this->client->expireInvoice($invoiceId);
        } catch (XenditApiException $e) {
            throw new RuntimeException("Could not expire Xendit invoice {$invoiceId} for order {$order->number}: {$e->getMessage()}", 0, $e);
        }

        XenditOrderPayment::write($order, ['status' => 'EXPIRED']);
    }
}
