<?php

declare(strict_types=1);

namespace App\Actions\Checkout;

use App\Payment\Contracts\ProvidesPaymentAction;
use App\Payment\PaymentActions;
use RuntimeException;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Payment\DataTransferObjects\PaymentResult;
use Shopper\Payment\Services\PaymentProcessingService;

/**
 * Start the payment of a freshly created order, for every payment driver,
 * through Shopper's PaymentProcessingService (which records the Initiate
 * PaymentTransaction). Web checkout and the mobile API both call this, so a
 * gateway added with Payment::extend() works on both without new branches.
 */
final readonly class InitiateOrderPayment
{
    public function __construct(
        private PaymentProcessingService $payments,
        private PaymentActions $actions,
    ) {}

    /**
     * @param  array<string, mixed>  $selection  The checkout payment selection (FetchPaymentMethods row)
     * @return array{result: PaymentResult, action: array{driver: string, kind: string, data: array<string, mixed>}|null}
     *
     * @throws \Throwable when the driver cannot create the payment
     */
    public function handle(Order $order, array $selection = []): array
    {
        $order->loadMissing('paymentMethod');
        $method = $order->paymentMethod;

        if (! $method instanceof PaymentMethod) {
            throw new RuntimeException("Order {$order->number} has no payment method.");
        }

        $result = $this->payments->initiate($order, $this->context($order, $method, $selection));

        if (! $result->success) {
            return ['result' => $result, 'action' => null];
        }

        $driver = $this->actions->driver($order);

        if ($driver instanceof ProvidesPaymentAction) {
            $driver->afterPaymentInitiated($order, $result);
        }

        $action = $this->actions->fromResult($order->refresh(), $result);

        // A driver without ProvidesPaymentAction (e.g. a hosted-page gateway
        // added with Payment::extend()) keeps its action on the Initiate
        // transaction Shopper recorded, so account pages, the mobile app and
        // a success-page reload show the same pay link later.
        if (! $driver instanceof ProvidesPaymentAction) {
            $this->actions->remember($order, $action);
        }

        return ['result' => $result, 'action' => $action];
    }

    /**
     * @param  array<string, mixed>  $selection
     * @return array<string, mixed>
     */
    private function context(Order $order, PaymentMethod $method, array $selection): array
    {
        $methodMeta = $method->metadata;

        if (is_string($methodMeta)) {
            $decoded = json_decode($methodMeta, true);
            $methodMeta = is_array($decoded) ? $decoded : [];
        }

        $methodMeta = is_array($methodMeta) ? $methodMeta : [];

        return array_filter([
            'order' => $order,
            'customer_id' => $order->customer_id,
            'payment_method_id' => $method->id,
            'payment_type' => $selection['payment_type'] ?? $methodMeta['payment_type'] ?? null,
            'channel_code' => $selection['channel_code'] ?? $methodMeta['channel_code'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
