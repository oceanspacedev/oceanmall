<?php

declare(strict_types=1);

namespace App\Payment;

use App\Payment\Contracts\ProvidesPaymentCatalog;
use App\Support\OperatorSwitch;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Payment\Facades\Payment;
use Throwable;

/**
 * Which enabled Shopper payment methods can really take a payment, for every
 * driver the same way (checkout and the storefront footer use this):
 *
 * - Shopper's manual driver always can;
 * - any other driver needs its Shopper switch
 *   (`shopper.payment.drivers.{code}.enabled`; no entry means off, as in
 *   Shopper's PaymentManager::configuredDrivers(), see
 *   App\Support\OperatorSwitch) and isConfigured(), and must be the ONE
 *   gateway the store sells through (App\Support\ActiveProvider:
 *   PAYMENT_PROVIDER, else the first configured one);
 * - a driver that ProvidesPaymentCatalog also decides per method
 *   (methodAvailable) and, when its catalog is reachable, keeps only methods
 *   the account offers (with the catalog's name, logo and amount limits).
 *
 * One instance memoizes each driver's catalog, so resolve it once per
 * request / listing.
 */
final class PaymentMethodCatalog
{
    /** @var array<string, list<array<string, mixed>>|null> */
    private array $catalogs = [];

    public function driverReady(string $code): bool
    {
        if ($code === 'manual') {
            return true;
        }

        if (! OperatorSwitch::on(OperatorSwitch::PAYMENT, $code)) {
            return false;
        }

        return Payment::isConfigured($code);
    }

    /**
     * The driver can still FINISH payments it issued (status re-check,
     * expire / cancel at the provider): switch on and configured, whichever
     * gateway the store sells through now (App\Support\ActiveProvider).
     */
    public function driverServiceable(string $code): bool
    {
        if ($code === 'manual') {
            return true;
        }

        if (! OperatorSwitch::switchedOn(OperatorSwitch::PAYMENT, $code)) {
            return false;
        }

        return Payment::isConfigured($code);
    }

    public function available(PaymentMethod $method): bool
    {
        $code = $this->code($method);

        if (! $this->driverReady($code)) {
            return false;
        }

        $driver = Payment::driver($code);

        if (! $driver instanceof ProvidesPaymentCatalog) {
            return true;
        }

        $meta = self::metadata($method);

        if (! $driver->methodAvailable($meta)) {
            return false;
        }

        $catalog = $this->catalogFor($code, $driver);

        // Catalog unreachable: trust the local configuration.
        return $catalog === null || $driver->catalogEntryFor($meta, $catalog) !== null;
    }

    /**
     * The provider catalog entry of a method, or null (no catalog / no match).
     *
     * @return array{display_name: string|null, logo_url: string|null, entry: array<string, mixed>}|null
     */
    public function entry(PaymentMethod $method): ?array
    {
        $code = $this->code($method);

        if (! $this->driverReady($code)) {
            return null;
        }

        $driver = Payment::driver($code);

        if (! $driver instanceof ProvidesPaymentCatalog) {
            return null;
        }

        $catalog = $this->catalogFor($code, $driver);

        return $catalog === null ? null : $driver->catalogEntryFor(self::metadata($method), $catalog);
    }

    /**
     * Cache signature of the ready drivers (footer cache keys).
     */
    public function signature(): string
    {
        return collect(Payment::availableDrivers())
            ->filter(fn (string $code): bool => $this->driverReady($code))
            ->sort()
            ->implode(',');
    }

    /**
     * @return array<string, mixed>
     */
    public static function metadata(PaymentMethod $method): array
    {
        $metadata = $method->metadata;

        if (is_string($metadata) && $metadata !== '') {
            $decoded = json_decode($metadata, true);
            $metadata = is_array($decoded) ? $decoded : [];
        }

        return is_array($metadata) ? $metadata : [];
    }

    private function code(PaymentMethod $method): string
    {
        $code = $method->driver;

        return is_string($code) && trim($code) !== '' ? trim($code) : 'manual';
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function catalogFor(string $code, ProvidesPaymentCatalog $driver): ?array
    {
        if (! array_key_exists($code, $this->catalogs)) {
            try {
                $this->catalogs[$code] = $driver->paymentCatalog();
            } catch (Throwable $e) {
                report($e);
                $this->catalogs[$code] = null;
            }
        }

        return $this->catalogs[$code];
    }
}
