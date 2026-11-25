<?php

declare(strict_types=1);

namespace App\Integrations\Biteship\Http\Middleware;

use App\Integrations\Biteship\Biteship;
use App\Support\OperatorSwitch;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While the Biteship module is off (no API key, a key refused for this
 * environment, or the operator switch shopper.shipping.drivers.biteship.enabled
 * is off) every Biteship webhook is answered HTTP 200 {status: disabled}
 * before any secret check or processing: nothing is read from the payload,
 * no API call is made, and Biteship stops retrying. Same approach as the
 * Komerce module's AcknowledgeDisabledWebhook.
 *
 * The switch alone decides (OperatorSwitch::switchedOn), not which shipping
 * provider the store sells through: parcels Biteship already holds keep
 * receiving their status / waybill webhooks after a move to another provider.
 */
final class AcknowledgeDisabledBiteshipWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Biteship::configured() || ! OperatorSwitch::switchedOn(OperatorSwitch::SHIPPING, Biteship::CODE)) {
            return new JsonResponse(['status' => 'disabled']);
        }

        return $next($request);
    }
}
