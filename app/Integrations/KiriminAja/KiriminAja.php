<?php

declare(strict_types=1);

namespace App\Integrations\KiriminAja;

/**
 * Readiness of the KiriminAja module, read per call so a key change takes
 * effect without a reboot. Module-internal: generic store code asks
 * App\Shipping\ShippingCapabilities, which combines this (through the
 * driver's isConfigured()) with the operator switch
 * `shopper.shipping.drivers.kiriminaja.enabled`.
 */
final class KiriminAja
{
    /**
     * An API key AND a base URL are set. In production the base URL has no
     * default (config/kiriminaja.php), so a production store with only a key
     * stays unconfigured instead of shipping through the sandbox.
     */
    public static function configured(): bool
    {
        return self::apiKey() !== '' && self::baseUrl() !== '';
    }

    public static function apiKey(): string
    {
        return trim((string) config('kiriminaja.api_key', ''));
    }

    public static function baseUrl(): string
    {
        return rtrim(trim((string) config('kiriminaja.base_url', '')), '/');
    }

    public static function webhookSecret(): string
    {
        return trim((string) config('kiriminaja.webhook_secret', ''));
    }

    public static function isSandbox(): bool
    {
        return str_contains(self::baseUrl(), 'tdev.kiriminaja.com');
    }
}
