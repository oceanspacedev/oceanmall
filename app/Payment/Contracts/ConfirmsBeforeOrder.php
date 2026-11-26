<?php

declare(strict_types=1);

namespace App\Payment\Contracts;

use Illuminate\Http\Request;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Order;

/**
 * A payment driver whose customer confirms the payment in an embedded form
 * BEFORE the order exists (card element with a client secret: Stripe, Xendit
 * cards...). Web checkout handles every such driver the same way:
 *
 * 1. preparePayment: prepareBeforeOrder() for the cart total; the page gets
 *    paymentAction {driver, kind: 'client_secret', data} and renders the
 *    embedded form registered for `driver` (inPagePaymentForms in
 *    resources/js/pages/shop/checkout.vue), which confirms with
 *    data.return_url;
 * 2. the provider sends the customer back to /checkout/confirm/{driver}/return:
 *    returnReference() reads the payment reference from that request and it
 *    must equal the prepared one;
 * 3. confirmedStatus() asks the provider; Paid / Authorized / Pending create
 *    the order, null sends the customer back to the payment step;
 * 4. attachOrder() links the provider payment to the new order (provider
 *    metadata, Shopper PaymentTransaction). Checkout then sets the order's
 *    payment_status and fires Shopper's OrderPaid for Paid.
 *
 * Every other driver creates the order first and initiates the payment
 * through Shopper's PaymentProcessingService (InitiateOrderPayment).
 */
interface ConfirmsBeforeOrder
{
    /**
     * Create the provider payment for the cart total.
     *
     * @param  array<string, mixed>  $context  cart_id, customer_id
     * @return array{reference: string, data: array<string, mixed>} data: client_secret (+ publishable_key, ...) for the embedded form
     *
     * @throws \Throwable when the provider refuses
     */
    public function prepareBeforeOrder(int $amount, string $currency, array $context = []): array;

    /**
     * The payment reference the provider appends to the return URL.
     */
    public function returnReference(Request $request): ?string;

    /**
     * Status of the prepared payment at the provider: Paid, Authorized or
     * Pending (processing) once the customer confirmed it; null when it was
     * not completed (or cannot be read, or amount/currency mismatches).
     */
    public function confirmedStatus(
        string $reference,
        ?int $expectedAmount = null,
        ?string $expectedCurrency = null,
    ): ?PaymentStatus;

    /**
     * Link the provider payment to the order created for it.
     */
    public function attachOrder(Order $order, string $reference, PaymentStatus $status): void;
}
