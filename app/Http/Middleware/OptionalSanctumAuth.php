<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class OptionalSanctumAuth
{
    /**
     * @throws AuthenticationException when a Bearer token is sent but no longer resolves
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()) {
            return $next($request);
        }

        $user = Auth::guard('sanctum')->user();
        if ($user) {
            $request->setUserResolver(static fn () => $user);

            return $next($request);
        }

        // No token at all is a guest. A token that does not resolve (revoked by a login on another
        // device, a password change or a sign-out, or expired) is a session that ended: answer like
        // auth:sanctum does (401) so the app signs out and says so, instead of quietly serving the
        // guest cart as if the customer's items were gone.
        if ($request->bearerToken() !== null) {
            throw new AuthenticationException('Unauthenticated.', ['sanctum']);
        }

        return $next($request);
    }
}
