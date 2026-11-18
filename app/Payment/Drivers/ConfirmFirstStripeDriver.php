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

    public function prepareBeforeOrder(int $amount, string $currency, array $context = []): array
    {
        $result = $this->stripe->initiatePayment($amount, $currency, [
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

    public function confirmedStatus(string $reference): ?PaymentStatus
    {
        $status = $this->intentStatus($reference);

        return match ($status) {
            'succeeded' => PaymentStatus::Paid,
            'requires_capture' => PaymentStatus::Authorized,
            'processing' => PaymentStatus::Pending,
            default => null,
        };
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
                'status' => $status === PaymentStatus::Paid ? TransactionStatus::Success : TransactionStatus::Pending,
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
        return $this->stripe->initiatePayment($amount, $currency, $context);
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

    private function intentStatus(string $reference): ?string
    {
        if ($this->secretKey === '') {
            return null;
        }

        try {
            return $this->client()->paymentIntents->retrieve($reference)->status;
        } catch (Throwable) {
            return null;
        }
    }

    private function client(): StripeClient
    {
        return $this->client ??= new StripeClient($this->secretKey);
    }
}
