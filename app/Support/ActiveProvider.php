<?php

declare(strict_types=1);

namespace App\Support;

use App\Shipping\ShippingCapabilities;
use Shopper\Payment\Facades\Payment;
use Shopper\Shipping\Facades\Shipping;
use Throwable;

/**
 * One provider per section. A store SELLS through at most one payment
 * provider and one shipping provider at a time, however many modules are
 * installed and keyed: two gateways never list their methods side by side,
 * two shipping providers never quote the same courier twice. Shopper's
 * built-in 'manual' driver is not a provider and is always allowed.
 *
 * Which one:
 *
 * - the store setting `shopper.{payment|shipping}.provider`
 *   (PAYMENT_PROVIDER / SHIPPING_PROVIDER): a driver code of the provider
 *   ('komerce', 'xendit', 'midtrans', 'biteship', 'kiriminaja', ...).
 *   'none' / 'manual' means no provider at all. A provider that is named but
 *   not configured (no key) is NOT replaced by another one: the store then
 *   runs on Shopper's manual payment / zone options until it is fixed;
 * - left empty: the first registered provider (bootstrap/providers.php
 *   order; Shopper's own drivers such as Stripe come last) whose operator
 *   switch is on and whose driver isConfigured(). For a provider whose
 *   isConfigured() follows a runtime plan check, name it explicitly on a
 *   store with several keys, so a rejected call never moves the default. With a single keyed provider that is simply that
 *   provider, so nothing has to be set.
 *
 * A provider is a FAMILY of driver codes: a driver that hands its quotes or
 * fulfilment to another one (DelegatesQuotes / DelegatesFulfillment) is the
 * same provider, see ShippingCapabilities::providerFamily(). Payment drivers
 * are each their own family.
 *
 * This only decides who takes NEW business (OperatorSwitch::on()). Orders a
 * previously selected provider already holds are still finished by it while
 * its key is set (OperatorSwitch::switchedOn()): webhooks, payment status,
 * tracking, cancel, labels.
 *
 * Computed per call: a key, plan or setting change applies without a reboot.
 */
final class ActiveProvider
{
    /**
     * Drivers that ship with Shopper itself (not provider modules).
     */
    private const SHOPPER_DRIVERS = ['stripe', 'paypal', 'ups', 'fedex', 'usps', 'canada_post', 'purolator'];

    /**
     * Families of every registered driver of a section (diagnostics).
     *
     * @return list<string>
     */
    public static function knownFamilies(string $section): array
    {
        return array_values(array_unique(array_map(
            static fn (string $code): string => self::familyOf($section, $code),
            self::driverCodes($section),
        )));
    }

    /**
     * The selected provider family of a section; null when no provider is
     * configured at all (nothing to choose between), 'manual' when the
     * setting says no provider.
     */
    public static function family(string $section): ?string
    {
        $explicit = self::setting($section);

        if ($explicit !== null) {
            return in_array($explicit, ['none', 'manual'], true)
                ? 'manual'
                : self::familyOf($section, $explicit);
        }

        return self::configuredFamilies($section)[0] ?? null;
    }

    /**
     * A driver may take new business: it is 'manual', no provider is
     * configured (so there is nothing to exclude), or it belongs to the
     * selected provider.
     */
    public static function allows(string $section, string $code): bool
    {
        if ($code === 'manual') {
            return true;
        }

        $selected = self::family($section);

        return $selected === null || self::familyOf($section, $code) === $selected;
    }

    /**
     * The store setting, lower-cased; null when left empty.
     */
    public static function setting(string $section): ?string
    {
        $value = config("shopper.{$section}.provider");

        // PAYMENT_PROVIDER=false reaches config as a boolean.
        if ($value === false) {
            return 'none';
        }

        if (! is_string($value) || ($value = strtolower(trim($value))) === '') {
            return null;
        }

        return in_array($value, ['false', 'off', 'no', '0'], true) ? 'none' : $value;
    }

    /**
     * Provider families whose switch is on and whose driver is configured,
     * in registration order (the first one is the default selection).
     *
     * @return list<string>
     */
    public static function configuredFamilies(string $section): array
    {
        $families = [];

        foreach (self::driverCodes($section) as $code) {
            if (OperatorSwitch::state($section, $code) !== true || ! self::driverConfigured($section, $code)) {
                continue;
            }

            $family = self::familyOf($section, $code);

            if (! in_array($family, $families, true)) {
                $families[] = $family;
            }
        }

        return $families;
    }

    public static function familyOf(string $section, string $code): string
    {
        $code = strtolower(trim($code));

        return $section === OperatorSwitch::SHIPPING
            ? resolve(ShippingCapabilities::class)->providerFamily($code)
            : ($code === '' ? 'manual' : $code);
    }

    /**
     * @return list<string>
     */
    private static function driverCodes(string $section): array
    {
        try {
            $codes = $section === OperatorSwitch::SHIPPING
                ? Shipping::availableDrivers()
                : Payment::availableDrivers();
        } catch (Throwable $e) {
            report($e);

            return [];
        }

        $codes = array_values(array_filter(
            $codes,
            static fn (mixed $code): bool => is_string($code) && $code !== '' && $code !== 'manual',
        ));

        // The managers list Shopper's own drivers first whatever the order
        // in bootstrap/providers.php: as a DEFAULT they come last, so an
        // enabled Stripe never takes the slot from a keyed provider module.
        return [
            ...array_values(array_diff($codes, self::SHOPPER_DRIVERS)),
            ...array_values(array_intersect($codes, self::SHOPPER_DRIVERS)),
        ];
    }

    /**
     * Asked on the driver itself, never through ShippingCapabilities /
     * PaymentMethodCatalog: those ask OperatorSwitch::on(), which asks here.
     */
    private static function driverConfigured(string $section, string $code): bool
    {
        try {
            return $section === OperatorSwitch::SHIPPING
                ? Shipping::driver($code)->isConfigured()
                : Payment::driver($code)->isConfigured();
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
