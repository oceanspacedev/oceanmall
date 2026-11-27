<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Orders\RetryOrderPayment;
use App\Http\Controllers\Controller;
use App\Payment\Exceptions\PaymentRetryRefused;
use Illuminate\Http\RedirectResponse;
use Shopper\Core\Models\Order;
use Throwable;

final class RetryOrderPaymentController extends Controller
{
    public function __invoke(Order $order): RedirectResponse
    {
        abort_unless($order->customer_id === auth()->id(), 403);

        try {
            resolve(RetryOrderPayment::class)->handle($order);
        } catch (Throwable $e) {
            report($e);

            $message = match ($e instanceof PaymentRetryRefused ? $e->reason : null) {
                PaymentRetryRefused::ALREADY_PAID => 'Pesanan ini sudah dibayar. Muat ulang halaman untuk memperbarui status.',
                PaymentRetryRefused::NOT_ELIGIBLE => 'Pembayaran pesanan ini tidak bisa diulang.',
                default => 'Gagal membuat ulang pembayaran. Coba lagi sebentar atau hubungi CS.',
            };

            return back()->withErrors([
                'payment' => $message,
            ]);
        }

        return redirect()
            ->route('account.orders.show', $order)
            ->with('success', 'Instruksi pembayaran baru sudah siap.');
    }
}
