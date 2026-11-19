<?php

declare(strict_types=1);

namespace App\Integrations\Xendit\Http\Middleware;

use App\Integrations\Xendit\Xendit;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While the module cannot act on callbacks (no usable secret key, or no
 * verification token) every Xendit webhook answers HTTP 200
 * `{status: disabled}` before anything is read: a 2xx stops Xendit's
 * retries, nothing is processed and no outbound call is made.
 *
 * The operator switch (PAYMENT_XENDIT_ENABLED) deliberately does NOT
 * disable webhooks: it takes Xendit out of checkout, but invoices already
 * issued can still be paid and their callbacks must settle those orders.
 */
final class AcknowledgeDisabledXenditWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Xendit::webhooksEnabled()) {
            return new JsonResponse(['status' => 'disabled']);
        }

        return $next($request);
    }
}
