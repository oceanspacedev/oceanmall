<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Shopper\Http\Middleware\DispatchShopper;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs inside Shopper's cpanel middleware stack AFTER StartSession and
 * AuthenticateSession, and is registered as persistent middleware on Livewire
 * updates. Storefront customers share the web guard, so an authenticated
 * shopper (or a demoted staff member on an open Livewire tab) is blocked here
 * with a real started session.
 */
final class DispatchShopperForStaffOnly extends DispatchShopper
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();

        if ($user !== null && ! EnsureCpanelIsStaffOnly::userIsStaff($user)) {
            if (($request->expectsJson() && ! $request->header('X-Inertia')) || $request->hasHeader('X-Livewire')) {
                return response()->json(['message' => 'Unauthorized'], Response::HTTP_FORBIDDEN);
            }

            return redirect()->route('dashboard');
        }

        return parent::handle($request, $next);
    }
}
