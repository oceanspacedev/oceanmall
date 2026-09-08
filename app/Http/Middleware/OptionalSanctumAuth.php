<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class OptionalSanctumAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()) {
            return $next($request);
        }

        $user = Auth::guard('sanctum')->user();
        if ($user) {
            $request->setUserResolver(static fn () => $user);
        }

        return $next($request);
    }
}
