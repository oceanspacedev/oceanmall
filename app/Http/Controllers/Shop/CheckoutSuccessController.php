<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shop;

use App\CheckoutSession;
use App\Http\Controllers\Controller;
use App\Payment\PaymentActions;
use Inertia\Inertia;
use Inertia\Response;
use Shopper\Core\Models\Order;

final class CheckoutSuccessController extends Controller
{
    public function __invoke(Order $order): Response
    {
        abort_unless($order->customer_id === auth()->id(), 403);

        $order->refresh();

        // The action of the order just placed, else whatever the order's own
        // payment driver reports now (e.g. after a reload or a status poll).
        $placed = session()->pull(CheckoutController::PAYMENT_ACTION_SESSION);
        $actions = resolve(PaymentActions::class);
        $paymentAction = $actions->awaitingPayment($order)
            ? $actions->forOrder($order) ?? (is_array($placed) ? $placed : null)
            : null;

        // Clear leftover checkout/payment session only — do NOT wipe the live cart.
        // Cart is already completed (or forgotten) at place-order time. Re-forgetting
        // here would destroy items added while the success page is still open (polls).
        session()->forget([
            ...CheckoutController::CONFIRM_PAYMENT_KEYS,
            'stripe_order_number',
            'checkout_cart_id',
            CheckoutSession::KEY,
        ]);

        return Inertia::render('shop/checkout-success', [
            'order' => $order->only([
                'id',
                'number',
                'price_amount',
                'currency_code',
                'status',
                'payment_status',
            ]),
            'paymentAction' => $paymentAction,
        ]);
    }
}
