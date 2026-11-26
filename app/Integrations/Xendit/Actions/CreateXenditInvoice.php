<?php

declare(strict_types=1);

namespace App\Integrations\Xendit\Actions;

use App\Integrations\Xendit\Services\XenditClient;
use App\Integrations\Xendit\Support\XenditChannels;
use App\Integrations\Xendit\Support\XenditOrderPayment;
use App\Payment\PaymentReturnUrl;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Shopper\Core\Models\Order;
use Shopper\Payment\DataTransferObjects\PaymentResult;

/**
 * Create a Xendit invoice (hosted checkout) for an order and describe it as
 * Shopper's PaymentResult: reference = invoice id, redirectUrl = invoice_url.
 *
 * The invoice is restricted (`payment_methods`) to the channel of the
 * Shopper payment method the customer picked (metadata.channel_code), so the
 * hosted page opens on that channel. Amounts are integer rupiah: IDR is a
 * zero-decimal currency in this store, nothing is scaled by 100.
 */
final readonly class CreateXenditInvoice
{
    public function __construct(private XenditClient $client) {}

    public function handle(Order $order, int $amount, string $currency, ?string $channelCode, int $attempt = 1, ?string $returnUrl = null): PaymentResult
    {
        if ($amount <= 0) {
            throw new RuntimeException('Xendit invoice requires a positive amount.');
        }

        $currency = strtoupper(trim($currency)) !== '' ? strtoupper(trim($currency)) : 'IDR';
        $externalId = XenditOrderPayment::externalId($order, $attempt);
        $channel = XenditChannels::normalize($channelCode);

        $payload = array_filter([
            'external_id' => $externalId,
            'amount' => $amount,
            'currency' => $currency,
            'description' => (string) __('Order :number', ['number' => $order->number]),
            'invoice_duration' => min(31536000, max(60, (int) config('xendit.invoice_duration', 86400))),
            'payment_methods' => $channel !== null ? [$channel] : null,
            'success_redirect_url' => $this->redirectUrl($order, 'success_redirect_url', $returnUrl),
            'failure_redirect_url' => $this->redirectUrl($order, 'failure_redirect_url', $returnUrl),
            'should_send_email' => false,
            'payer_email' => $this->customerEmail($order),
            'customer' => $this->customer($order),
            'metadata' => ['order_id' => (string) $order->id, 'order_number' => (string) $order->number],
        ], static fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');

        $invoice = $this->client->createInvoice($payload);
        $invoiceUrl = is_string($invoice['invoice_url'] ?? null) ? trim($invoice['invoice_url']) : '';

        if ($invoiceUrl === '') {
            throw new RuntimeException('Xendit created an invoice without an invoice_url.');
        }

        $invoiceAmount = XenditOrderPayment::paidAmount(['amount' => $invoice['amount'] ?? $amount]);

        return new PaymentResult(
            success: true,
            status: 'pending',
            reference: (string) $invoice['id'],
            redirectUrl: $invoiceUrl,
            amount: $invoiceAmount ?? $amount,
            data: [
                'invoice_id' => (string) $invoice['id'],
                'external_id' => (string) ($invoice['external_id'] ?? $externalId),
                'invoice_url' => $invoiceUrl,
                'expiry_date' => is_string($invoice['expiry_date'] ?? null) ? $invoice['expiry_date'] : null,
                'status' => XenditOrderPayment::status($invoice) !== '' ? XenditOrderPayment::status($invoice) : 'PENDING',
                'channel_code' => $channel,
                'attempt' => $attempt,
                'amount' => $invoiceAmount ?? $amount,
                'currency_code' => $currency,
            ],
        );
    }

    private function redirectUrl(Order $order, string $key, ?string $returnUrl = null): ?string
    {
        $override = is_string($returnUrl) ? trim($returnUrl) : '';
        if ($override !== '') {
            return $override;
        }

        $stored = PaymentReturnUrl::get($order);
        if ($stored !== null && $stored !== '') {
            return $stored;
        }

        $configured = config("xendit.{$key}");

        if (is_string($configured) && trim($configured) !== '') {
            return str_replace('{order}', (string) $order->id, trim($configured));
        }

        return Route::has('account.orders.show') ? route('account.orders.show', $order) : null;
    }

    private function customerEmail(Order $order): ?string
    {
        $email = trim((string) ($order->customer?->email ?? ''));

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    /**
     * @return array<string, string>|null
     */
    private function customer(Order $order): ?array
    {
        $customer = $order->customer;

        if ($customer === null) {
            return null;
        }

        return array_filter([
            'given_names' => trim((string) ($customer->first_name ?? '')),
            'surname' => trim((string) ($customer->last_name ?? '')),
            'email' => $this->customerEmail($order),
            'mobile_number' => $this->mobileNumber($order),
        ], static fn (?string $value): bool => $value !== null && $value !== '') ?: null;
    }

    /**
     * Xendit expects E.164 (+62...). Anything that cannot be normalised is
     * left out rather than failing the invoice.
     */
    private function mobileNumber(Order $order): ?string
    {
        $raw = (string) ($order->customer?->phone_number ?? '');

        if (trim($raw) === '') {
            $metadata = $order->getAttribute('metadata');
            $metadata = is_string($metadata) ? json_decode($metadata, true) : $metadata;
            $raw = (string) data_get(is_array($metadata) ? $metadata : [], 'shipping_address.phone_number', '');
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '0')) {
            $digits = '62'.substr($digits, 1);
        } elseif (str_starts_with($digits, '8')) {
            $digits = '62'.$digits;
        }

        return preg_match('/^62\d{8,13}$/', $digits) === 1 ? '+'.$digits : null;
    }
}
