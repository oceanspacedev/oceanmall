<?php

declare(strict_types=1);

namespace App\Payment;

use App\Payment\Contracts\ConfirmsBeforeOrder;
use App\Payment\Contracts\ProvidesPaymentAction;
use App\Payment\Contracts\ReconcilesPayments;
use App\Payment\Contracts\RetriesPayments;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Payment\Contracts\PaymentDriver;
use Shopper\Payment\DataTransferObjects\PaymentResult;
use Shopper\Payment\Enum\TransactionStatus;
use Shopper\Payment\Enum\TransactionType;
use Shopper\Payment\Facades\Payment;
use Shopper\Payment\Models\PaymentTransaction;
use Throwable;

/**
 * The one place generic code (checkout, account pages, mobile API, cpanel)
 * asks what the order's own payment driver can do. The driver is always the
 * order's payment method driver, never a global "provider is on" flag.
 *
 * Neutral payment action shape (web prop `paymentAction`, API `payment_action`):
 *
 *     {driver: string, kind: 'instructions'|'redirect'|'client_secret'|'qr'|'none', data: object}
 *
 * - instructions / qr: data is the driver's instruction payload (VA number, QR string, expiry...)
 * - redirect: data.url is the hosted payment page
 * - client_secret: data.client_secret for an embedded payment form
 * - none: nothing to do online (manual / COD); data carries the method's title,
 *   description and instructions from Shopper
 */
final class PaymentActions
{
    public const KINDS = ['instructions', 'redirect', 'client_secret', 'qr', 'none'];

    /** metadata key of the remembered action on Shopper's Initiate PaymentTransaction. */
    public const TRANSACTION_KEY = 'payment_action';

    /**
     * The payment driver that owns an order: the one that issued its latest
     * payment (Shopper's Initiate PaymentTransaction), else its payment
     * method's driver. Staff can switch a payment method to another driver
     * in cpanel; an order already paying through the old one keeps being
     * retried, checked and cancelled there, never by another module.
     */
    public function driverCode(Order $order): string
    {
        $issued = PaymentTransaction::query()
            ->where('order_id', $order->id)
            ->where('type', TransactionType::Initiate)
            ->whereNotNull('driver')
            ->where('driver', '!=', '')
            ->orderByDesc('id')
            ->value('driver');

        if (is_string($issued) && trim($issued) !== '') {
            return trim($issued);
        }

        $order->loadMissing('paymentMethod');
        $code = $order->paymentMethod?->driver;

        return is_string($code) && trim($code) !== '' ? trim($code) : 'manual';
    }

    public function driver(Order $order): ?PaymentDriver
    {
        try {
            return Payment::driver($this->driverCode($order));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The pending payment action of an unpaid order; null when the order
     * needs no payment action (paid, closed) or its setup failed.
     *
     * @return array{driver: string, kind: string, data: array<string, mixed>}|null
     */
    public function forOrder(Order $order): ?array
    {
        if (! $this->awaitingPayment($order)) {
            return null;
        }

        $driver = $this->driver($order);

        $code = $this->driverCode($order);

        if ($driver instanceof ProvidesPaymentAction) {
            return $this->wrap($code, $this->safeAction($driver, $order));
        }

        // Offline methods (Shopper's manual driver: transfer, COD) have
        // nothing to do online.
        if ($code === 'manual') {
            return $this->wrap($code, ['kind' => 'none', 'data' => $this->methodData($order)]);
        }

        // Any other gateway: the action recorded when its payment was
        // initiated (remember()), or null when none was (setup failed).
        return $this->remembered($order, $code);
    }

    /**
     * Keep the action of a just-initiated payment on Shopper's Initiate
     * PaymentTransaction (metadata.payment_action), for drivers that do not
     * implement ProvidesPaymentAction.
     *
     * @param  array{driver: string, kind: string, data: array<string, mixed>}|null  $action
     */
    public function remember(Order $order, ?array $action): void
    {
        if ($action === null || $action['kind'] === 'none') {
            return;
        }

        $transaction = $this->initiateTransaction($order, $action['driver']);

        if (! $transaction instanceof PaymentTransaction) {
            return;
        }

        $metadata = is_array($transaction->metadata) ? $transaction->metadata : [];
        $metadata[self::TRANSACTION_KEY] = $action;

        $transaction->forceFill(['metadata' => $metadata])->save();
    }

    /**
     * @return array{driver: string, kind: string, data: array<string, mixed>}|null
     */
    private function remembered(Order $order, string $code): ?array
    {
        $transaction = $this->initiateTransaction($order, $code);
        $metadata = $transaction instanceof PaymentTransaction && is_array($transaction->metadata) ? $transaction->metadata : [];
        $action = $metadata[self::TRANSACTION_KEY] ?? null;

        return is_array($action) ? $this->wrap($code, $action) : null;
    }

    private function initiateTransaction(Order $order, string $code): ?PaymentTransaction
    {
        return PaymentTransaction::query()
            ->where('order_id', $order->id)
            ->where('driver', $code)
            ->where('type', TransactionType::Initiate)
            ->where('status', TransactionStatus::Success)
            ->latest('id')
            ->first();
    }

    /**
     * The action right after PaymentProcessingService::initiate().
     *
     * @return array{driver: string, kind: string, data: array<string, mixed>}|null
     */
    public function fromResult(Order $order, PaymentResult $result): ?array
    {
        $driver = $this->driver($order);
        $code = $this->driverCode($order);

        if ($driver instanceof ProvidesPaymentAction) {
            return $this->wrap($code, $this->safeAction($driver, $order));
        }

        if (filled($result->clientSecret)) {
            return $this->wrap($code, ['kind' => 'client_secret', 'data' => ['client_secret' => $result->clientSecret]]);
        }

        if (filled($result->redirectUrl)) {
            return $this->wrap($code, ['kind' => 'redirect', 'data' => ['url' => $result->redirectUrl]]);
        }

        return $this->wrap($code, ['kind' => 'none', 'data' => $this->methodData($order)]);
    }

    /**
     * The order's payment driver can recreate its payment (expired VA...).
     */
    public function canRetry(Order $order): bool
    {
        if (! $this->awaitingPayment($order)) {
            return false;
        }

        $driver = $this->driver($order);

        if (! $driver instanceof RetriesPayments) {
            return false;
        }

        try {
            return $driver->isConfigured() && $driver->canRetryPayment($order);
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * The order's payment driver, when it can pull the payment status from
     * the provider (and so owns "mark paid"); null for manual / COD methods.
     */
    public function reconciler(Order $order): ?ReconcilesPayments
    {
        $driver = $this->driver($order);

        if (! $driver instanceof ReconcilesPayments) {
            return null;
        }

        try {
            return $driver->isConfigured() ? $driver : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The confirm-first payment driver for a code (ConfirmsBeforeOrder: the
     * customer confirms in an embedded form before the order exists), when it
     * is ready. Web checkout runs its flow; the mobile app cannot render an
     * embedded form, so the API neither lists nor accepts such methods.
     */
    public static function confirmFirstDriver(?string $code, bool $servicing = false): ?ConfirmsBeforeOrder
    {
        if (! is_string($code) || $code === '' || $code === 'manual') {
            return null;
        }

        // $servicing: the customer returns from a confirmation that was
        // already started. It is finished also when the store moved to
        // another gateway meanwhile (the card may be charged); no new
        // confirmation starts with a gateway that does not sell.
        $catalog = new PaymentMethodCatalog;

        if (! ($servicing ? $catalog->driverServiceable($code) : $catalog->driverReady($code))) {
            return null;
        }

        try {
            $driver = Payment::driver($code);
        } catch (Throwable) {
            return null;
        }

        return $driver instanceof ConfirmsBeforeOrder ? $driver : null;
    }

    /**
     * The pre-payment_action API `payment` field still read by mobile builds
     * already in the stores: the data of an instructions / qr action (VA /
     * QRIS payload), a redirect as {payment_url} (a shape those builds already
     * open as the hosted pay page), null for every other kind.
     *
     * @param  array<string, mixed>|null  $action
     * @return array<string, mixed>|null
     */
    public static function legacyInstructions(?array $action): ?array
    {
        if ($action === null) {
            return null;
        }

        $data = is_array($action['data'] ?? null) ? $action['data'] : null;

        if (($action['kind'] ?? null) === 'redirect') {
            $url = $data['url'] ?? null;

            return is_string($url) && $url !== '' ? ['payment_url' => $url] : null;
        }

        return in_array($action['kind'] ?? null, ['instructions', 'qr'], true) ? $data : null;
    }

    public function awaitingPayment(Order $order): bool
    {
        return $order->status !== OrderStatus::Cancelled
            && in_array($order->payment_status, [PaymentStatus::Pending, PaymentStatus::Authorized, null], true);
    }

    /**
     * @param  array<string, mixed>|null  $action
     * @return array{driver: string, kind: string, data: array<string, mixed>}|null
     */
    private function wrap(string $driver, ?array $action): ?array
    {
        if ($action === null) {
            return null;
        }

        $kind = is_string($action['kind'] ?? null) && in_array($action['kind'], self::KINDS, true)
            ? $action['kind']
            : 'none';
        $data = is_array($action['data'] ?? null) ? $action['data'] : [];

        return ['driver' => $driver, 'kind' => $kind, 'data' => $data];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function safeAction(ProvidesPaymentAction $driver, Order $order): ?array
    {
        try {
            return $driver->paymentAction($order);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function methodData(Order $order): array
    {
        $method = $order->paymentMethod;

        if (! $method instanceof PaymentMethod) {
            return [];
        }

        return [
            'title' => $method->title,
            'description' => $method->description,
            'instructions' => $method->instructions,
        ];
    }
}
