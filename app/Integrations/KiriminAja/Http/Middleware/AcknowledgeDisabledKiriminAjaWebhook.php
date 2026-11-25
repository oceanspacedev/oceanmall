<?php

declare(strict_types=1);

namespace App\Integrations\KiriminAja\Http\Middleware;

use App\Integrations\KiriminAja\KiriminAja;
use App\Integrations\KiriminAja\Shipping\KiriminAjaDriver;
use App\Support\OperatorSwitch;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While the module is not plugged in (no KIRIMINAJA_API_KEY / base URL, or
 * the operator switch `shopper.shipping.drivers.kiriminaja.enabled` is off)
 * a callback is acknowledged with HTTP 200 `{status: disabled}` before any
 * authentication or processing: nothing is read from the payload and no
 * outbound call is made. Same approach as the Komerce module.
 *
 * The switch alone decides (OperatorSwitch::switchedOn), not which shipping
 * provider the store sells through: parcels KiriminAja already holds keep
 * receiving their callbacks after a move to another provider.
 */
final class AcknowledgeDisabledKiriminAjaWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! KiriminAja::configured() || ! OperatorSwitch::switchedOn(OperatorSwitch::SHIPPING, KiriminAjaDriver::CODE)) {
            return new JsonResponse(['status' => 'disabled']);
        }

        return $next($request);
    }
}
