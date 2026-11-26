<?php

declare(strict_types=1);

namespace App\Integrations\Xendit\Payment;

use App\Integrations\Xendit\Actions\CancelUnpaidXenditOrder;
use App\Integrations\Xendit\Actions\ConfirmXenditInvoice;
use App\Integrations\Xendit\Actions\CreateXenditInvoice;
use App\Integrations\Xendit\Actions\RetryXenditPayment;
use App\Integrations\Xendit\Services\XenditClient;
use App\Integrations\Xendit\Support\XenditOrderPayment;
use App\Integrations\Xendit\Xendit;
use App\Payment\Contracts\ExpiresPayments;
use App\Payment\Contracts\ProvidesPaymentAction;
use App\Payment\Contracts\ReconcilesPayments;
use App\Payment\Contracts\RetriesPayments;
use App\Payment\Exceptions\PaymentRetryRefused;
use App\Payment\PaymentActions;
use Carbon\CarbonInterface;
use RuntimeException;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Order;
use Shopper\Payment\DataTransferObjects\PaymentResult;
use Shopper\Payment\DataTransferObjects\WebhookResult;
use Shopper\Payment\Drivers\Driver;
use Shopper\Payment\Exceptions\PaymentException;

/**
 * Shopper payment driver for the Xendit Invoice API: a hosted checkout page
 * (redirect) restricted to the channel of the chosen payment method (QRIS,
 * VA per bank, e-wallets, cards).
 *
 * Plug and play: registered whenever the module is, but isConfigured() is
 * true only with a usable secret key (Xendit::configured()), and checkout
 * also needs the operator switch `shopper.payment.drivers.xendit.enabled`
 * (PaymentMethodCatalog). Without a key no Xendit method is offered and no
 * request is ever sent.
 *
 * - initiate: one invoice per order (external_id = order number), the
 *   storefront / app open `invoice_url`;
 * - ProvidesPaymentAction: the `redirect` action follows the current invoice
 *   (a retry swaps the link, an expired invoice has none);
 * - ReconcilesPayments / webhook: the invoice is always read back from
 *   Xendit before the order changes (ConfirmXenditInvoice);
 * - ExpiresPayments: orders:expire-unpaid expires the invoice at Xendit and
 *   cancels the order; RetriesPayments: a new invoice "{number}-{attempt}".
 */
final class XenditDriver extends Driver implements ExpiresPayments, ProvidesPaymentAction, ReconcilesPayments, RetriesPayments
{
    public const CODE = 'xendit';

    public function __construct(private readonly XenditClient $client) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function name(): string
    {
        return 'Xendit';
    }

    public function logo(): ?string
    {
        $path = public_path('images/payments/qris.svg');

        return is_file($path) ? asset('images/payments/qris.svg').'?v='.filemtime($path) : null;
    }

    public function isConfigured(): bool
    {
        return Xendit::configured();
    }

    public function supportsWebhooks(): bool
    {
        return true;
    }

    public function supportsRefunds(): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $context  order / order_id, channel_code (payment method metadata), attempt (retries)
     */
    public function initiatePayment(int $amount, string $currency, array $context = []): PaymentResult
    {
        if (! $this->isConfigured()) {
            throw PaymentException::notConfigured($this->code());
        }

        $attempt = is_numeric($context['attempt'] ?? null) ? max(1, (int) $context['attempt']) : 1;

        return resolve(CreateXenditInvoice::class)->handle(
            $this->resolveOrder($context),
            $amount,
            $currency,
            isset($context['channel_code']) ? (string) $context['channel_code'] : null,
            $attempt,
            is_string($context['return_url'] ?? null) ? trim($context['return_url']) : null,
        );
    }

    /**
     * Keep the new invoice as the order's current one (metadata.xendit) and on
     * Shopper's Initiate transaction (expiry for orders:expire-unpaid).
     */
    public function afterPaymentInitiated(Order $order, PaymentResult $result): void
    {
        if (! $result->success || $result->reference === null || $result->reference === '') {
            return;
        }

        $data = $result->data;
        $state = [
            'invoice_id' => $result->reference,
            'external_id' => $data['external_id'] ?? null,
            'invoice_url' => $result->redirectUrl,
            'expiry_date' => $data['expiry_date'] ?? null,
            'status' => $data['status'] ?? 'PENDING',
            'channel_code' => $data['channel_code'] ?? null,
            'attempt' => (int) ($data['attempt'] ?? 1),
            'mode' => Xendit::mode(),
        ];

        XenditOrderPayment::write($order, $state);

        $transaction = XenditOrderPayment::latestTransaction($order);

        if ($transaction !== null && $transaction->reference === $result->reference) {
            $metadata = is_array($transaction->metadata) ? $transaction->metadata : [];

            $transaction->forceFill(['metadata' => [
                ...$metadata,
                'expiry_date' => $state['expiry_date'],
                'invoice_url' => $state['invoice_url'],
                'external_id' => $state['external_id'],
                PaymentActions::TRANSACTION_KEY => [
                    'driver' => self::CODE,
                    'kind' => 'redirect',
                    'data' => ['url' => $state['invoice_url']],
                ],
            ]])->save();
        }
    }

    /**
     * `redirect` to the current invoice while it can still be paid; null
     * once it expired (the order page then offers "pay again").
     */
    public function paymentAction(Order $order): ?array
    {
        // Without a usable key nothing could confirm the payment (webhooks
        // answer `disabled`, reconcile cannot ask Xendit): no pay link.
        if (! $this->isConfigured()) {
            return null;
        }

        $state = XenditOrderPayment::read($order);
        $url = is_string($state['invoice_url'] ?? null) ? trim($state['invoice_url']) : '';

        if ($url === '' || $this->currentInvoiceClosed($order)) {
            return null;
        }

        return [
            'kind' => 'redirect',
            'data' => array_filter([
                'url' => $url,
                'expiry_date' => $state['expiry_date'] ?? null,
                'channel_code' => $state['channel_code'] ?? null,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        ];
    }

    /**
     * A new invoice can replace one that is expired (or that was never
     * created); a payable invoice is simply opened again.
     */
    public function canRetryPayment(Order $order): bool
    {
        if (! $this->isConfigured()
            || $order->status === OrderStatus::Cancelled
            || $order->payment_status === PaymentStatus::Paid) {
            return false;
        }

        $order->loadMissing('paymentMethod');

        if ($order->paymentMethod?->driver !== self::CODE) {
            return false;
        }

        // A live invoice seen through a test key cannot be checked: never replace it.
        if (! XenditOrderPayment::modeMatches($order) && XenditOrderPayment::mode($order) === Xendit::MODE_LIVE) {
            return false;
        }

        return XenditOrderPayment::currentInvoiceId($order) === null || $this->currentInvoiceClosed($order);
    }

    public function retryPayment(Order $order): void
    {
        if ($order->payment_status !== PaymentStatus::Paid && ! $this->canRetryPayment($order)) {
            throw PaymentRetryRefused::notEligible('The Xendit invoice of this order can still be paid.');
        }

        resolve(RetryXenditPayment::class)->handle($order);
    }

    public function reconcilePayment(Order $order): string
    {
        $invoiceId = XenditOrderPayment::currentInvoiceId($order);

        if ($invoiceId === null) {
            return self::NO_PAYMENT;
        }

        // Created with the other (test / live) key: this key cannot see it.
        if (! XenditOrderPayment::modeMatches($order)) {
            return self::LOOKUP_FAILED;
        }

        ['verdict' => $verdict] = resolve(ConfirmXenditInvoice::class)->handle($invoiceId);

        return match ($verdict) {
            ConfirmXenditInvoice::HANDLED, ConfirmXenditInvoice::ALREADY_PROCESSED => self::PAID,
            ConfirmXenditInvoice::NO_TRANSACTION, ConfirmXenditInvoice::NO_ORDER => self::NO_PAYMENT,
            ConfirmXenditInvoice::LOOKUP_FAILED => self::LOOKUP_FAILED,
            ConfirmXenditInvoice::AMOUNT_MISMATCH => self::AMOUNT_MISMATCH,
            ConfirmXenditInvoice::NOT_PAID, ConfirmXenditInvoice::EXPIRED, ConfirmXenditInvoice::SUPERSEDED => self::PENDING,
            default => $verdict,
        };
    }

    public function paymentExpiresAt(Order $order): ?CarbonInterface
    {
        return XenditOrderPayment::expiresAt($order);
    }

    /**
     * False for an invoice created with the other (test / live) key: Xendit
     * cannot be asked about it, so orders:expire-unpaid falls back to its
     * local cancel after the grace period instead of failing every run.
     */
    public function canExpireUnpaidOrder(Order $order): bool
    {
        return $this->isConfigured() && XenditOrderPayment::modeMatches($order);
    }

    public function expireUnpaidOrder(Order $order, string $reason = 'Payment expired'): Order
    {
        return resolve(CancelUnpaidXenditOrder::class)->handle($order, $reason);
    }

    public function retrievePayment(string $reference): PaymentResult
    {
        $reference = trim($reference);

        if ($reference === '') {
            throw PaymentException::apiError($this->code(), 'Invoice id is required.');
        }

        if (! $this->isConfigured()) {
            throw PaymentException::notConfigured($this->code());
        }

        $invoice = $this->client->getInvoice($reference);
        $status = XenditOrderPayment::neutralStatus(XenditOrderPayment::status($invoice));

        return new PaymentResult(
            success: $status !== 'failed',
            status: $status,
            reference: $reference,
            redirectUrl: is_string($invoice['invoice_url'] ?? null) ? $invoice['invoice_url'] : null,
            amount: XenditOrderPayment::paidAmount($invoice),
            data: ['xendit_status' => XenditOrderPayment::status($invoice), 'raw_response' => $invoice],
        );
    }

    /**
     * Expire a PENDING invoice at Xendit.
     */
    public function cancelPayment(string $reference): PaymentResult
    {
        $reference = trim($reference);

        if ($reference === '') {
            throw PaymentException::apiError($this->code(), 'Invoice id is required.');
        }

        if (! $this->isConfigured()) {
            throw PaymentException::notConfigured($this->code());
        }

        $invoice = $this->client->expireInvoice($reference);
        $xenditStatus = XenditOrderPayment::status($invoice);

        return new PaymentResult(
            success: $xenditStatus === 'EXPIRED',
            status: $xenditStatus === 'EXPIRED' ? 'canceled' : XenditOrderPayment::neutralStatus($xenditStatus),
            reference: $reference,
            data: ['xendit_status' => $xenditStatus, 'raw_response' => $invoice],
        );
    }

    /**
     * Verify an invoice callback: the `x-callback-token` header must equal
     * XENDIT_CALLBACK_TOKEN (constant-time). The body only says which
     * invoice to look at; the order changes after ConfirmXenditInvoice read
     * it back from the API.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $headers
     */
    public function handleWebhook(array $payload, array $headers = []): WebhookResult
    {
        $expected = Xendit::callbackToken();
        $given = $headers['x-callback-token'] ?? null;
        $given = is_array($given) ? ($given[0] ?? null) : $given;

        if ($expected === '' || ! is_string($given) || ! hash_equals($expected, $given)) {
            throw PaymentException::webhookVerificationFailed($this->code());
        }

        $invoiceId = is_string($payload['id'] ?? null) ? trim($payload['id']) : '';
        $status = strtoupper(trim((string) ($payload['status'] ?? '')));

        if ($invoiceId === '') {
            return WebhookResult::ignored();
        }

        $data = [
            'external_id' => $payload['external_id'] ?? null,
            'xendit_status' => $status,
        ];
        $amount = XenditOrderPayment::paidAmount($payload);

        return match ($status) {
            'PAID', 'SETTLED' => new WebhookResult(action: 'captured', reference: $invoiceId, amount: $amount, data: $data),
            'EXPIRED' => new WebhookResult(action: 'failed', reference: $invoiceId, amount: $amount, data: $data),
            default => WebhookResult::ignored(),
        };
    }

    /**
     * The current invoice cannot be paid any more: Xendit said EXPIRED, its
     * expiry passed, or it was created with the other (test / live) key.
     */
    private function currentInvoiceClosed(Order $order): bool
    {
        if (! XenditOrderPayment::modeMatches($order)) {
            return true;
        }

        $state = XenditOrderPayment::read($order);

        if (strtoupper((string) ($state['status'] ?? '')) === 'EXPIRED') {
            return true;
        }

        $expiresAt = XenditOrderPayment::expiresAt($order);

        return $expiresAt !== null && $expiresAt->isPast();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function resolveOrder(array $context): Order
    {
        if (($context['order'] ?? null) instanceof Order) {
            return $context['order'];
        }

        $orderId = $context['order_id'] ?? null;
        $order = is_numeric($orderId) ? Order::query()->find((int) $orderId) : null;

        if (! $order instanceof Order) {
            throw new RuntimeException('Xendit payment requires a Shopper order.');
        }

        return $order;
    }
}
