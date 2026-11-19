<?php

declare(strict_types=1);

namespace App\Integrations\Midtrans\Payment;

use App\Integrations\Midtrans\Actions\CloseMidtransAttempt;
use App\Integrations\Midtrans\Actions\ExpireMidtransOrder;
use App\Integrations\Midtrans\Actions\RetryMidtransPayment;
use App\Integrations\Midtrans\Actions\SyncMidtransPayment;
use App\Integrations\Midtrans\Exceptions\MidtransApiException;
use App\Integrations\Midtrans\Midtrans;
use App\Integrations\Midtrans\Services\MidtransClient;
use App\Integrations\Midtrans\Support\MidtransOrderState;
use App\Integrations\Midtrans\Support\MidtransSignature;
use App\Integrations\Midtrans\Support\MidtransSnapPayload;
use App\Integrations\Midtrans\Support\MidtransTransactionStatus;
use App\Payment\Contracts\ExpiresPayments;
use App\Payment\Contracts\ProvidesPaymentAction;
use App\Payment\Contracts\ReconcilesPayments;
use App\Payment\Contracts\RetriesPayments;
use App\Payment\PaymentActions;
use Carbon\CarbonInterface;
use RuntimeException;
use Shopper\Core\Models\Order;
use Shopper\Payment\DataTransferObjects\PaymentResult;
use Shopper\Payment\DataTransferObjects\WebhookResult;
use Shopper\Payment\Drivers\Driver;
use Shopper\Payment\Enum\TransactionStatus;
use Shopper\Payment\Enum\TransactionType;
use Shopper\Payment\Exceptions\PaymentException;
use Shopper\Payment\Models\PaymentTransaction;
use Throwable;

/**
 * Shopper payment driver for Midtrans Snap (hosted checkout, redirect).
 *
 * Flow: checkout creates the order, Shopper's PaymentProcessingService calls
 * initiatePayment(), which creates a Snap transaction for the order total on
 * the chosen channel and returns its redirect_url; the storefront / app open
 * it through the neutral `redirect` payment action. Midtrans then posts an
 * HTTP notification (webhooks/midtrans/notification); the module verifies
 * its signature, re-reads the status from the Core API and settles the order
 * through the provider-neutral MarkOrderPaid (Shopper's OrderPaid).
 *
 * Contracts: ProvidesPaymentAction (the pay link disappears once the attempt
 * expired or failed, so the order page offers "pay again"), RetriesPayments
 * (a new Snap transaction, order_id {number}~{attempt}), ReconcilesPayments
 * ("already paid? check status") and ExpiresPayments (orders:expire-unpaid).
 */
final class MidtransDriver extends Driver implements ExpiresPayments, ProvidesPaymentAction, ReconcilesPayments, RetriesPayments
{
    public const CODE = Midtrans::CODE;

    /** Snap order_id collisions tolerated before giving up. */
    private const MAX_ORDER_ID_BUMPS = 3;

    public function __construct(private readonly MidtransClient $client) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function name(): string
    {
        return 'Midtrans';
    }

    public function isConfigured(): bool
    {
        return Midtrans::configured();
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
     * @param  array<string, mixed>  $context  order (or order_id), channel_code (Snap enabled_payments code), midtrans_attempt
     */
    public function initiatePayment(int $amount, string $currency, array $context = []): PaymentResult
    {
        if (! $this->isConfigured()) {
            throw PaymentException::notConfigured($this->code());
        }

        if (strtoupper($currency) !== 'IDR') {
            throw new RuntimeException('Midtrans only takes IDR payments.');
        }

        if ($amount < 1) {
            throw new RuntimeException('Midtrans payment requires a positive amount.');
        }

        $order = $this->resolveOrder($context);
        $channel = is_string($context['channel_code'] ?? null) ? trim($context['channel_code']) : null;
        $attempt = is_numeric($context['midtrans_attempt'] ?? null)
            ? max(1, (int) $context['midtrans_attempt'])
            : MidtransOrderState::nextAttempt($order);
        $expiryMinutes = (int) config('midtrans.expiry_minutes', 1440);

        for ($bump = 0; ; $bump++) {
            $midtransOrderId = MidtransOrderState::orderIdFor($order, $attempt);
            $startsAt = now();

            try {
                $snap = $this->client->createSnapTransaction(
                    MidtransSnapPayload::build($order, $midtransOrderId, $amount, $channel, $startsAt, $expiryMinutes),
                );

                break;
            } catch (MidtransApiException $e) {
                // An id Midtrans already knows (e.g. a timed-out earlier
                // request that did reach Snap): take the next attempt number.
                if ($e->orderIdTaken() && $bump < self::MAX_ORDER_ID_BUMPS) {
                    $attempt++;

                    continue;
                }

                throw $e;
            }
        }

        $expiresAt = $startsAt->copy()->addMinutes($expiryMinutes)->toIso8601String();

        return new PaymentResult(
            success: true,
            status: 'pending',
            reference: $midtransOrderId,
            redirectUrl: $snap['redirect_url'],
            amount: $amount,
            data: [
                'midtrans_order_id' => $midtransOrderId,
                'attempt' => $attempt,
                'token' => $snap['token'],
                'redirect_url' => $snap['redirect_url'],
                'channel_code' => $channel,
                'expires_at' => $expiresAt,
                'environment' => Midtrans::environment(),
            ],
        );
    }

    /**
     * Keep the attempt on the order (pay link, expiry) and turn Shopper's
     * Initiate transaction into the attempt's pending transaction.
     */
    public function afterPaymentInitiated(Order $order, PaymentResult $result): void
    {
        if (! $result->success || ! is_string($result->reference) || $result->reference === '') {
            return;
        }

        $data = $result->data;

        MidtransOrderState::write($order, [
            'order_id' => $result->reference,
            'attempt' => (int) ($data['attempt'] ?? MidtransOrderState::attemptOf($result->reference)),
            'token' => $data['token'] ?? null,
            'redirect_url' => $result->redirectUrl,
            'channel_code' => $data['channel_code'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'environment' => $data['environment'] ?? Midtrans::environment(),
        ]);

        PaymentTransaction::query()->updateOrCreate(
            [
                'order_id' => $order->id,
                'driver' => self::CODE,
                'reference' => $result->reference,
            ],
            [
                'payment_method_id' => $order->payment_method_id,
                'type' => TransactionType::Initiate,
                'status' => TransactionStatus::Pending,
                'amount' => (int) $order->price_amount,
                'currency_code' => $order->currency_code,
                'metadata' => [
                    'midtrans_order_id' => $result->reference,
                    'attempt' => $data['attempt'] ?? null,
                    'channel_code' => $data['channel_code'] ?? null,
                    'expires_at' => $data['expires_at'] ?? null,
                    'environment' => $data['environment'] ?? null,
                ],
            ],
        );
    }

    /**
     * The Snap page of the current attempt while it can still be paid.
     */
    public function paymentAction(Order $order): ?array
    {
        $state = MidtransOrderState::read($order);
        $url = is_string($state['redirect_url'] ?? null) ? trim($state['redirect_url']) : '';

        if ($url === '' || $this->attemptIsOver($order)) {
            return null;
        }

        $expiresAt = MidtransOrderState::expiresAt($order);

        return [
            'kind' => 'redirect',
            'data' => [
                'url' => $url,
                'payment_url' => $url,
                'payment_id' => $state['order_id'] ?? null,
                'expiry_date' => $expiresAt?->toIso8601String(),
                'amount' => (int) $order->price_amount,
                'currency_code' => $order->currency_code,
            ],
        ];
    }

    /**
     * A new Snap transaction is offered only once the current one can no
     * longer be paid (expired by time, or Midtrans reported it failed /
     * cancelled), or when none was created. A Snap page that is still open
     * cannot be revoked by the API, so replacing it earlier could let the
     * customer pay twice.
     */
    public function canRetryPayment(Order $order): bool
    {
        if (! $this->isConfigured() || ! resolve(PaymentActions::class)->awaitingPayment($order)) {
            return false;
        }

        $order->loadMissing('paymentMethod');

        if (($order->paymentMethod?->driver ?? null) !== self::CODE) {
            return false;
        }

        return MidtransOrderState::currentOrderId($order) === null || $this->attemptIsOver($order);
    }

    public function retryPayment(Order $order): void
    {
        resolve(RetryMidtransPayment::class)->handle($order);
    }

    public function reconcilePayment(Order $order): string
    {
        return match (resolve(SyncMidtransPayment::class)->reconcile($order)) {
            SyncMidtransPayment::HANDLED, SyncMidtransPayment::ALREADY_PROCESSED => self::PAID,
            SyncMidtransPayment::NO_ATTEMPT, SyncMidtransPayment::NO_ORDER => self::NO_PAYMENT,
            SyncMidtransPayment::LOOKUP_FAILED => self::LOOKUP_FAILED,
            SyncMidtransPayment::AMOUNT_MISMATCH => self::AMOUNT_MISMATCH,
            default => self::PENDING,
        };
    }

    public function paymentExpiresAt(Order $order): ?CarbonInterface
    {
        return MidtransOrderState::expiresAt($order);
    }

    public function canExpireUnpaidOrder(Order $order): bool
    {
        return $this->isConfigured();
    }

    public function expireUnpaidOrder(Order $order, string $reason = 'Payment expired'): Order
    {
        return resolve(ExpireMidtransOrder::class)->handle($order, $reason);
    }

    /**
     * Status of one attempt (reference = Midtrans order_id), mapped onto
     * Shopper's vocabulary: captured / pending / failed / canceled / refunded.
     */
    public function retrievePayment(string $reference): PaymentResult
    {
        $status = $this->fetchStatus($reference);

        return new PaymentResult(
            success: ! $status->isClosedUnpaid(),
            status: match ($status->state) {
                MidtransTransactionStatus::PAID => 'captured',
                MidtransTransactionStatus::FAILED => 'failed',
                MidtransTransactionStatus::CANCELED => 'canceled',
                MidtransTransactionStatus::REFUNDED => 'refunded',
                default => 'pending',
            },
            reference: $reference,
            amount: $status->amount,
            data: [
                'state' => $status->state,
                'transaction_status' => $status->transactionStatus,
                'fraud_status' => $status->fraudStatus,
                'raw_response' => $status->raw,
            ],
        );
    }

    /**
     * Close a pending attempt (expire, or cancel a card under review). A
     * Snap page where no method was picked yet has nothing to close.
     */
    public function cancelPayment(string $reference): PaymentResult
    {
        if (! $this->isConfigured()) {
            throw PaymentException::notConfigured($this->code());
        }

        try {
            $status = resolve(CloseMidtransAttempt::class)->handle($reference);
        } catch (Throwable $e) {
            throw PaymentException::apiError($this->code(), $e->getMessage());
        }

        return new PaymentResult(
            success: ! $status->isPaid(),
            status: $status->isPaid() ? 'captured' : 'canceled',
            reference: $reference,
            amount: $status->amount,
            data: ['state' => $status->state, 'raw_response' => $status->raw],
        );
    }

    /**
     * Verify a notification body (signature_key) and classify it. The body is
     * only a signal: the webhook controller re-reads the status from the Core
     * API before changing anything.
     *
     * @param  array<string, mixed>  $payload  The decoded notification JSON
     *
     * @throws PaymentException when the signature does not match
     */
    public function handleWebhook(array $payload, array $headers = []): WebhookResult
    {
        $orderId = is_scalar($payload['order_id'] ?? null) ? (string) $payload['order_id'] : '';
        $statusCode = is_scalar($payload['status_code'] ?? null) ? (string) $payload['status_code'] : '';
        $grossAmount = is_scalar($payload['gross_amount'] ?? null) ? (string) $payload['gross_amount'] : '';
        $signature = is_string($payload['signature_key'] ?? null) ? $payload['signature_key'] : '';

        if (! MidtransSignature::isValid($orderId, $statusCode, $grossAmount, Midtrans::serverKey(), $signature)) {
            throw PaymentException::webhookVerificationFailed($this->code());
        }

        $status = MidtransTransactionStatus::fromResponse($payload);
        $action = match ($status->state) {
            MidtransTransactionStatus::PAID => 'captured',
            MidtransTransactionStatus::FAILED => 'failed',
            MidtransTransactionStatus::CANCELED => 'canceled',
            default => null,
        };

        if ($action === null) {
            return new WebhookResult(action: 'ignored', reference: $orderId, data: ['transaction_status' => $status->transactionStatus]);
        }

        return new WebhookResult(
            action: $action,
            reference: $orderId,
            amount: $status->amount,
            data: ['transaction_status' => $status->transactionStatus, 'fraud_status' => $status->fraudStatus],
        );
    }

    public function fetchStatus(string $reference): MidtransTransactionStatus
    {
        if (! $this->isConfigured()) {
            throw PaymentException::notConfigured($this->code());
        }

        return MidtransTransactionStatus::fromResponse($this->client->status($reference));
    }

    /**
     * The current attempt can no longer be paid: past its expiry, or its
     * transaction was closed (failed / cancelled notification).
     */
    private function attemptIsOver(Order $order): bool
    {
        $expiresAt = MidtransOrderState::expiresAt($order);

        if ($expiresAt !== null && $expiresAt->isPast()) {
            return true;
        }

        $current = MidtransOrderState::currentOrderId($order);

        if ($current === null) {
            return true;
        }

        $transaction = PaymentTransaction::query()
            ->where('order_id', $order->id)
            ->where('driver', self::CODE)
            ->where('reference', $current)
            ->latest('id')
            ->first();

        return $transaction instanceof PaymentTransaction && $transaction->status === TransactionStatus::Failed;
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

        if (is_numeric($orderId)) {
            $order = Order::query()->find((int) $orderId);

            if ($order instanceof Order) {
                return $order;
            }
        }

        throw new RuntimeException('Midtrans payment requires a Shopper order.');
    }
}
