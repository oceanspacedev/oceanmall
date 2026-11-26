<?php

declare(strict_types=1);

namespace App\Payment\Drivers;

use App\Payment\Contracts\ConfirmsBeforeOrder;
use Illuminate\Http\Request;
use RuntimeException;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Order;
use Shopper\Payment\Contracts\PaymentDriver;
use Shopper\Payment\DataTransferObjects\PaymentResult;
use Shopper\Payment\DataTransferObjects\WebhookResult;
use Shopper\Payment\Enum\TransactionStatus;
use Shopper\Payment\Enum\TransactionType;
use Shopper\Payment\Models\PaymentTransaction;
use Stripe\StripeClient;
use Throwable;

/**
 * Shopper's Stripe driver (shopper/stripe, a final class) with the store's
 * confirm-first checkout contract: the PaymentIntent is created and confirmed
 * in the embedded Payment Element before the order exists. Every Shopper
 * driver call is delegated unchanged; registered over 'stripe' with
 * Payment::extend() in AppServiceProvider.
 */
final class ConfirmFirstStripeDriver implements ConfirmsBeforeOrder, PaymentDriver
{
    private ?StripeClient $client = null;

    public function __construct(
        private readonly PaymentDriver $stripe,
        private readonly string $secretKey,
        private readonly string $publishableKey,
    ) {}

    public static function toStripeAmount(int $amount, string $currency): int
    {
        return strtoupper(trim($currency)) === 'IDR' ? $amount * 100 : $amount;
    }

    public static function fromStripeAmount(int $stripeAmount, string $currency): ?int
    {
        if (strtoupper(trim($currency)) === 'IDR') {
            return $stripeAmount % 100 === 0 ? intdiv($stripeAmount, 100) : null;
        }

        return $stripeAmount;
    }

    public function prepareBeforeOrder(int $amount, string $currency, array $context = []): array
    {
        $result = $this->stripe->initiatePayment(self::toStripeAmount($amount, $currency), $currency, [
            'metadata' => array_filter([
                'cart_id' => $context['cart_id'] ?? null,
                'customer_id' => $context['customer_id'] ?? 0,
            ], static fn (mixed $value): bool => $value !== null),
        ]);

        if (! $result->success || ! is_string($result->clientSecret) || $result->clientSecret === '') {
            throw new RuntimeException($result->message ?? 'Stripe did not return a client secret.');
        }

        return [
            'reference' => (string) ($result->reference ?? explode('_secret_', $result->clientSecret)[0]),
            'data' => [
                'client_secret' => $result->clientSecret,
                'publishable_key' => $result->data['publishable_key'] ?? $this->publishableKey,
            ],
        ];
    }

    public function returnReference(Request $request): ?string
    {
        $intent = $request->query('payment_intent');

        return is_string($intent) && $intent !== '' ? $intent : null;
    }

    public function confirmedStatus(
        string $reference,
        ?int $expectedAmount = null,
        ?string $expectedCurrency = null,
    ): ?PaymentStatus {
        $intent = $this->retrieveIntent($reference);

        if ($intent === null) {
            return null;
        }

        $mapped = match ($intent->status ?? null) {
            'succeeded' => PaymentStatus::Paid,
            'requires_capture' => PaymentStatus::Authorized,
            'processing' => PaymentStatus::Pending,
            default => null,
        };

        if ($mapped === null) {
            return null;
        }

        $intentCurrency = strtoupper(trim((string) ($intent->currency ?? '')));

        if ($expectedCurrency !== null && $intentCurrency !== strtoupper(trim($expectedCurrency))) {
            report(new RuntimeException(sprintf(
                'Stripe PaymentIntent %s currency mismatch: expected %s, got %s.',
                $reference,
                $expectedCurrency,
                $intentCurrency,
            )));

            return null;
        }

        if ($expectedAmount !== null) {
            $currency = $expectedCurrency ?? ($intentCurrency !== '' ? $intentCurrency : 'IDR');
            $expectedStripeAmount = self::toStripeAmount($expectedAmount, $currency);
            $actualAmount = match ($mapped) {
                PaymentStatus::Paid => (int) ($intent->amount_received ?: $intent->amount),
                PaymentStatus::Authorized => (int) ($intent->amount_capturable ?: $intent->amount),
                default => (int) $intent->amount,
            };

            if ($actualAmount !== $expectedStripeAmount) {
                report(new RuntimeException(sprintf(
                    'Stripe PaymentIntent %s amount mismatch: expected %d (%s), got %d.',
                    $reference,
                    $expectedStripeAmount,
                    $currency,
                    $actualAmount,
                )));

                return null;
            }
        }

        return $mapped;
    }

    public function attachOrder(Order $order, string $reference, PaymentStatus $status): void
    {
        if ($this->secretKey !== '') {
            try {
                $this->client()->paymentIntents->update($reference, [
                    'metadata' => ['order_id' => $order->id, 'order_number' => $order->number],
                ]);
            } catch (Throwable) {
                // Non-blocking: the transaction below links the order.
            }
        }

        $stripeStatus = match ($status) {
            PaymentStatus::Paid => 'succeeded',
            PaymentStatus::Authorized => 'requires_capture',
            default => 'processing',
        };

        PaymentTransaction::query()->updateOrCreate(
            [
                'order_id' => $order->id,
                'reference' => $reference,
            ],
            [
                'payment_method_id' => $order->payment_method_id,
                'driver' => $this->code(),
                'type' => match ($status) {
                    PaymentStatus::Paid => TransactionType::Capture,
                    PaymentStatus::Authorized => TransactionType::Authorize,
                    default => TransactionType::Initiate,
                },
                'amount' => $order->price_amount,
                'currency_code' => $order->currency_code,
                'status' => in_array($status, [PaymentStatus::Paid, PaymentStatus::Authorized], true)
                    ? TransactionStatus::Success
                    : TransactionStatus::Pending,
                'metadata' => ['stripe_status' => $stripeStatus],
            ],
        );
    }

    public function publishableKey(): string
    {
        return $this->publishableKey;
    }

    public function code(): string
    {
        return $this->stripe->code();
    }

    public function name(): string
    {
        return $this->stripe->name();
    }

    public function logo(): ?string
    {
        return $this->stripe->logo();
    }

    public function isConfigured(): bool
    {
        return $this->stripe->isConfigured();
    }

    public function supportsWebhooks(): bool
    {
        return $this->stripe->supportsWebhooks();
    }

    public function supportsRefunds(): bool
    {
        return $this->stripe->supportsRefunds();
    }

    public function initiatePayment(int $amount, string $currency, array $context = []): PaymentResult
    {
        return $this->stripe->initiatePayment(self::toStripeAmount($amount, $currency), $currency, $context);
    }

    public function authorizePayment(string $reference, array $data = []): PaymentResult
    {
        return $this->stripe->authorizePayment($reference, $data);
    }

    public function capturePayment(string $reference, ?int $amount = null): PaymentResult
    {
        return $this->stripe->capturePayment($reference, $amount);
    }

    public function refundPayment(string $reference, int $amount, ?string $reason = null): PaymentResult
    {
        return $this->stripe->refundPayment($reference, $amount, $reason);
    }

    public function cancelPayment(string $reference): PaymentResult
    {
        return $this->stripe->cancelPayment($reference);
    }

    public function retrievePayment(string $reference): PaymentResult
    {
        return $this->stripe->retrievePayment($reference);
    }

    public function handleWebhook(array $payload, array $headers = []): WebhookResult
    {
        return $this->stripe->handleWebhook($payload, $headers);
    }

    private function retrieveIntent(string $reference): ?object
    {
        if ($this->secretKey === '') {
            return null;
        }

        try {
            return $this->client()->paymentIntents->retrieve($reference);
        } catch (Throwable) {
            return null;
        }
    }

    private function client(): StripeClient
    {
        return $this->client ??= new StripeClient($this->secretKey);
    }
}
