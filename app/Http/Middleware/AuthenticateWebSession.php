<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Auth;

/**
 * Signs a web session out once the account's password changed elsewhere
 * (Laravel's AuthenticateSession), for session guards only.
 *
 * The framework middleware asks the DEFAULT guard, whatever it is: when a
 * request reaches a web route while a token guard is the default (a Sanctum
 * bearer token on a web route), it calls a method only session guards have
 * and the request fails. A token guard has no session to verify: skip it.
 */
final class AuthenticateWebSession extends AuthenticateSession
{
    public function handle($request, Closure $next)
    {
        if (! Auth::guard() instanceof SessionGuard) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
