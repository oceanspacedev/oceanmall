<?php

declare(strict_types=1);

namespace App\Integrations\Biteship;

/**
 * Readiness of the Biteship module, read per call from config('biteship.*')
 * so a key change takes effect without a reboot. Module-internal: generic
 * code asks App\Shipping\ShippingCapabilities, which asks the driver's
 * isConfigured().
 *
 * Biteship serves test and live traffic from the same host; the key prefix
 * is the environment. configured() is honest about it:
 *
 *  - no key, or no base URL: not configured;
 *  - a `biteship_test.` key in production: not configured, unless the
 *    operator opts in with BITESHIP_ALLOW_TEST_KEY=true;
 *  - a `biteship_live.` key in the `local` / `testing` environments: not
 *    configured, unless BITESHIP_ALLOW_LIVE_KEY=true (no real pickups from a
 *    developer machine or a test run);
 *  - a key without a known prefix is accepted as the operator gave it.
 */
final class Biteship
{
    public const CODE = 'biteship';

    public const TEST_PREFIX = 'biteship_test.';

    public const LIVE_PREFIX = 'biteship_live.';

    public static function apiKey(): string
    {
        return trim((string) config('biteship.api_key', ''));
    }

    public static function hasKey(): bool
    {
        return self::apiKey() !== '';
    }

    public static function baseUrl(): string
    {
        return rtrim(trim((string) config('biteship.base_url', '')), '/');
    }

    public static function isTestKey(): bool
    {
        return str_starts_with(self::apiKey(), self::TEST_PREFIX);
    }

    public static function isLiveKey(): bool
    {
        return str_starts_with(self::apiKey(), self::LIVE_PREFIX);
    }

    public static function configured(): bool
    {
        return self::hasKey() && self::baseUrl() !== '' && self::keyMatchesEnvironment();
    }

    /**
     * Why the module is off while a key is present; null when it is usable
     * or simply keyless.
     */
    public static function refusal(): ?string
    {
        if (! self::hasKey()) {
            return null;
        }

        if (self::baseUrl() === '') {
            return 'BITESHIP_BASE_URL is empty.';
        }

        if (self::isTestKey() && app()->environment('production') && ! self::allowTestKey()) {
            return 'A biteship_test. key is refused in production (set BITESHIP_ALLOW_TEST_KEY=true to override).';
        }

        if (self::isLiveKey() && app()->environment(['local', 'testing']) && ! self::allowLiveKey()) {
            return 'A biteship_live. key is refused in the local / testing environment (set BITESHIP_ALLOW_LIVE_KEY=true to override).';
        }

        return null;
    }

    public static function webhookSecret(): string
    {
        return (string) config('biteship.webhook_secret', '');
    }

    public static function webhookHeader(): string
    {
        $header = trim((string) config('biteship.webhook_header', ''));

        return $header !== '' ? $header : 'X-Biteship-Secret';
    }

    private static function keyMatchesEnvironment(): bool
    {
        return self::refusal() === null;
    }

    private static function allowTestKey(): bool
    {
        return filter_var(config('biteship.allow_test_key_in_production', false), FILTER_VALIDATE_BOOLEAN);
    }

    private static function allowLiveKey(): bool
    {
        return filter_var(config('biteship.allow_live_key_outside_production', false), FILTER_VALIDATE_BOOLEAN);
    }
}
