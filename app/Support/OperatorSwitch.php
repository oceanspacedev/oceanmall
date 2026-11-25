<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The operator switch of a Shopper shipping / payment driver:
 * `shopper.{section}.drivers.{code}.enabled`.
 *
 * Same convention as Shopper's own ShippingManager / PaymentManager
 * ::configuredDrivers(): a driver without an `enabled` entry is OFF, so a
 * driver added with Shipping::extend() / Payment::extend() needs its config
 * entry (README "Adding a shipping provider"). Values are parsed as booleans
 * the way .env files are usually written: true / 1 / on / yes are on, and
 * false / 0 / off / no / empty are off (env('X', true) returns the raw
 * string for "0" or "off", which a plain truthiness check would read as on).
 *
 * Shopper's built-in 'manual' driver has no switch and is always on.
 */
final class OperatorSwitch
{
    public const SHIPPING = 'shipping';

    public const PAYMENT = 'payment';

    /**
     * The driver may take NEW business (offer payment methods, quote
     * couriers, sync carriers, be the store's destination vocabulary): its
     * switch is on AND it belongs to the one provider the store sells
     * through (App\Support\ActiveProvider).
     */
    public static function on(string $section, string $code): bool
    {
        if ($code === 'manual') {
            return true;
        }

        return self::state($section, $code) === true && ActiveProvider::allows($section, $code);
    }

    /**
     * The switch alone, whichever provider is selected: the driver may still
     * FINISH what it already holds after the store moved to another provider
     * (status and expiry of issued payments, registering paid parcels sold
     * with it, tracking, cancel, labels, shipping webhooks). Setting the
     * switch to false stops that too. Payment webhooks of the provider
     * modules do not even ask the switch: a paid callback for an issued
     * invoice is applied while the key is set.
     */
    public static function switchedOn(string $section, string $code): bool
    {
        return $code === 'manual' || self::state($section, $code) === true;
    }

    /**
     * The parsed switch: true / false, or null when the driver has no
     * `enabled` entry at all.
     */
    public static function state(string $section, string $code): ?bool
    {
        $key = "shopper.{$section}.drivers.{$code}";
        $entry = config($key);

        if (! is_array($entry) || ! array_key_exists('enabled', $entry)) {
            return null;
        }

        return self::parse($entry['enabled']);
    }

    public static function parse(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === null) {
            return false;
        }

        if (is_int($value) || is_float($value)) {
            return $value !== 0 && $value !== 0.0;
        }

        if (! is_string($value)) {
            return false;
        }

        return filter_var(trim(trim($value), '()'), FILTER_VALIDATE_BOOLEAN);
    }
}
