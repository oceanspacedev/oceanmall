<?php

declare(strict_types=1);

namespace App\Integrations\Xendit;

/**
 * Readiness and mode of the Xendit module. Module-internal: generic code asks
 * Shopper (`Payment::isConfigured('xendit')`), which reaches this through
 * XenditDriver::isConfigured().
 *
 * Xendit uses one API host for test and live mode; the secret key decides:
 * `xnd_development_...` = test mode, `xnd_production_...` = live. A key with
 * another shape is not used at all, and a development key is refused while
 * APP_ENV=production (unless explicitly allowed), so live checkout can never
 * end up in test mode without the operator saying so.
 */
final class Xendit
{
    public const MODE_TEST = 'test';

    public const MODE_LIVE = 'live';

    private const DEVELOPMENT_PREFIX = 'xnd_development_';

    private const PRODUCTION_PREFIX = 'xnd_production_';

    public static function secretKey(): string
    {
        return trim((string) config('xendit.secret_key', ''));
    }

    public static function callbackToken(): string
    {
        return trim((string) config('xendit.callback_token', ''));
    }

    /**
     * 'test' / 'live' from the key prefix, null without a recognisable key.
     */
    public static function mode(): ?string
    {
        $key = self::secretKey();

        return match (true) {
            str_starts_with($key, self::DEVELOPMENT_PREFIX) => self::MODE_TEST,
            str_starts_with($key, self::PRODUCTION_PREFIX) => self::MODE_LIVE,
            default => null,
        };
    }

    /**
     * Why the configured key cannot be used, or null when it can.
     */
    public static function refusal(): ?string
    {
        if (self::secretKey() === '') {
            return 'XENDIT_SECRET_KEY is empty.';
        }

        $mode = self::mode();

        if ($mode === null) {
            return 'XENDIT_SECRET_KEY is not a Xendit secret key (expected xnd_development_... or xnd_production_...).';
        }

        if ($mode === self::MODE_TEST
            && app()->environment('production')
            && ! (bool) config('xendit.allow_development_key_in_production', false)) {
            return 'A Xendit development (test mode) key is configured while APP_ENV=production; set a xnd_production_ key or XENDIT_ALLOW_DEVELOPMENT_KEY_IN_PRODUCTION=true.';
        }

        return null;
    }

    /**
     * The secret key is set and allowed in this environment.
     */
    public static function configured(): bool
    {
        return self::refusal() === null;
    }

    /**
     * Callbacks can be verified and acted on (usable key + verification token).
     */
    public static function webhooksEnabled(): bool
    {
        return self::configured() && self::callbackToken() !== '';
    }
}
