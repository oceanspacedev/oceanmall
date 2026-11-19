<?php

declare(strict_types=1);

namespace App\Integrations\Midtrans\Http\Middleware;

use App\Integrations\Midtrans\Midtrans;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While no Midtrans server key is set the module is unplugged: every
 * notification is answered HTTP 200 {status: disabled} before anything is
 * read, verified or fetched (a 2xx stops Midtrans' retries). The same
 * convention as the Komerce module's AcknowledgeDisabledWebhook.
 *
 * With a key set the notification is processed even when the operator
 * switch (PAYMENT_MIDTRANS_ENABLED) is off or the host choice is refused at
 * checkout: it belongs to a real order and must not be acknowledged and
 * dropped. A host mismatch then fails the status lookup (503) and Midtrans
 * retries once the configuration is fixed.
 */
final class AcknowledgeDisabledMidtransWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Midtrans::hasKey()) {
            return new JsonResponse(['status' => 'disabled']);
        }

        return $next($request);
    }
}
