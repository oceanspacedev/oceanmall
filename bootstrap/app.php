<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureCpanelIsStaffOnly;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\OptionalSanctumAuth;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Session\Middleware\AuthenticateSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Listeners are registered explicitly (AppServiceProvider and provider
    // modules), so a provider's listeners only exist where it is wired in.
    // Discovery would also register them a second time.
    ->withEvents(discover: false)
    ->withMiddleware(function (Middleware $middleware): void {
        // Trusted proxies come from config/trustedproxy.php (TRUSTED_PROXIES).
        // Reject spoofed Host headers (password-reset links, absolute URLs).
        // Laravel skips this check in the local environment and in tests.
        $middleware->trustHosts();

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
        ]);

        // Global: Shopper registers /cpanel outside the web group.
        $middleware->append(EnsureCpanelIsStaffOnly::class);

        $middleware->alias([
            'auth.optional' => OptionalSanctumAuth::class,
        ]);

        $middleware->web(append: [
            // A password change or reset signs out every other web session
            // (their stored password hash no longer matches).
            AuthenticateSession::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Provider modules set their own report levels (see app/Integrations/*).
    })->create();
