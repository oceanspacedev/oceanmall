<?php

declare(strict_types=1);

namespace App\Integrations\Xendit\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Integrations\Xendit\Actions\CancelUnpaidXenditOrder;
use App\Integrations\Xendit\Actions\ConfirmXenditInvoice;
use App\Integrations\Xendit\Payment\XenditDriver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Order;
use Shopper\Payment\Exceptions\PaymentException;
use Shopper\Payment\Facades\Payment;
use Throwable;

/**
 * POST webhooks/xendit/invoice: Xendit Invoice callbacks (PAID, SETTLED,
 * EXPIRED).
 *
 * 1. `x-callback-token` must match XENDIT_CALLBACK_TOKEN (401 otherwise);
 * 2. the invoice is read back from the Xendit API (ConfirmXenditInvoice):
 *    PAID / SETTLED marks the order paid through MarkOrderPaid (once, amount
 *    checked), an EXPIRED current invoice cancels the unpaid order;
 * 3. 503 when Xendit could not be asked, so Xendit retries the callback.
 *
 * Duplicate callbacks are harmless: a paid or cancelled order is left as is.
 * A PAID callback for an invoice that was never recorded locally (create
 * timed out) is read back and adopted only when its metadata.order_id and
 * external_id both point at the order (ConfirmXenditInvoice).
 */
final class XenditInvoiceWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        ConfirmXenditInvoice $confirm,
        CancelUnpaidXenditOrder $cancel,
    ): JsonResponse {
        try {
            $result = Payment::driver(XenditDriver::CODE)->handleWebhook(
                payload: $request->json()->all(),
                headers: ['x-callback-token' => (string) $request->header('x-callback-token', '')],
            );
        } catch (PaymentException) {
            return response()->json(['status' => 'invalid_token'], 401);
        }

        if ($result->isIgnored() || $result->reference === null) {
            return response()->json(['status' => 'ignored']);
        }

        // A PAID callback for an invoice we never recorded (its create answer
        // was lost) is read back and settled when it really is ours.
        ['verdict' => $verdict, 'order' => $order] = $confirm->handle(
            $result->reference,
            adoptUnknownPaidInvoice: $result->action === 'captured',
        );

        if ($verdict === ConfirmXenditInvoice::LOOKUP_FAILED) {
            return response()->json(['status' => $verdict], 503);
        }

        if ($verdict === ConfirmXenditInvoice::EXPIRED && $order instanceof Order && $this->awaitingPayment($order)) {
            try {
                $cancel->handle($order, 'Payment expired');
            } catch (Throwable $e) {
                report($e);

                return response()->json(['status' => 'retry'], 503);
            }

            return response()->json(['status' => 'expired']);
        }

        return response()->json(['status' => $verdict]);
    }

    private function awaitingPayment(Order $order): bool
    {
        return $order->status !== OrderStatus::Cancelled
            && $order->payment_status !== PaymentStatus::Paid;
    }
}
