<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires confirmed 2FA for staff accessing /cpanel when enabled
 * (`shopper.auth.require_two_factor`). Livewire update endpoints and account /
 * logout routes remain reachable so staff can set up 2FA without a loop.
 */
final class EnsureStaffHasTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('shopper.auth.require_two_factor', false)) {
            return $next($request);
        }

        if (! $this->isCpanelRequest($request)) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user instanceof User || ! EnsureCpanelIsStaffOnly::userIsStaff($user)) {
            return $next($request);
        }

        if (filled($user->store_two_factor_secret) || filled($user->two_factor_confirmed_at)) {
            return $next($request);
        }

        if ($request->routeIs('shopper.profile', 'shopper.logout', 'shopper.login', 'livewire.*')
            || $request->hasHeader('X-Livewire')) {
            return $next($request);
        }

        return redirect()
            ->route('shopper.profile')
            ->with('warning', 'Aktifkan autentikasi dua faktor (2FA) terlebih dahulu sebelum mengelola toko.');
    }

    private function isCpanelRequest(Request $request): bool
    {
        $prefix = trim((string) config('shopper.admin.prefix', 'cpanel'), '/');

        return $request->is($prefix, $prefix.'/*');
    }
}
