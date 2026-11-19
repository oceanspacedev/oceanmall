<?php

declare(strict_types=1);

namespace App\Integrations\Midtrans;

/**
 * Midtrans module readiness and hosts, read from the module config on every
 * call (no memo, so a config change in tests / Octane workers applies at once).
 *
 * Module-internal: generic store code never calls this; it asks the Shopper
 * driver (isConfigured()) through App\Payment\PaymentMethodCatalog.
 */
final class Midtrans
{
    /** Shopper payment driver code. */
    public const CODE = 'midtrans';

    /** Prefix only sandbox server keys carry. */
    private const SANDBOX_PREFIX = 'SB-';

    public static function serverKey(): string
    {
        return trim((string) config('midtrans.server_key', ''));
    }

    /**
     * A server key is filled in: the module is plugged in (webhooks are
     * processed, not acknowledged and dropped), even when a host mismatch
     * keeps checkout off.
     */
    public static function hasKey(): bool
    {
        return self::serverKey() !== '';
    }

    /**
     * Key present AND the host choice is explicit and consistent with it.
     */
    public static function configured(): bool
    {
        return self::hasKey() && self::configurationError() === null;
    }

    /**
     * Why the configured key must not be used, or null when it may.
     */
    public static function configurationError(): ?string
    {
        $key = self::serverKey();

        if ($key === '') {
            return 'MIDTRANS_SERVER_KEY is not set.';
        }

        $flag = config('midtrans.is_production');

        if ($flag === null && app()->environment('production')) {
            return 'MIDTRANS_IS_PRODUCTION must be set explicitly when APP_ENV=production.';
        }

        // Only the unambiguous mismatch is refused: an SB- key exists only in
        // the sandbox. A Mid-server- key is NOT refused in sandbox mode,
        // because Midtrans does not guarantee the SB- prefix for sandbox keys
        // (newer sandbox accounts may issue Mid-server- keys); a production
        // key sent to the sandbox host is simply rejected there (401), no
        // money can move, and the host choice itself is explicit
        // (MIDTRANS_IS_PRODUCTION, mandatory with APP_ENV=production).
        if (self::isProduction() && str_starts_with($key, self::SANDBOX_PREFIX)) {
            return 'A sandbox (SB-) server key is configured while MIDTRANS_IS_PRODUCTION=true.';
        }

        return null;
    }

    public static function isProduction(): bool
    {
        return config('midtrans.is_production') === true;
    }

    public static function snapBaseUrl(): string
    {
        return (string) config('midtrans.hosts.'.self::environment().'.snap');
    }

    public static function apiBaseUrl(): string
    {
        return (string) config('midtrans.hosts.'.self::environment().'.api');
    }

    public static function environment(): string
    {
        return self::isProduction() ? 'production' : 'sandbox';
    }
}
