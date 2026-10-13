<?php

declare(strict_types=1);

namespace App\Actions\Checkout;

use App\Services\Komerce\ShippingDeliveryClient;
use App\Services\Shipping\DestinationCoordinateResolver;
use App\Support\CourierServiceExplainer;
use App\Support\KomerceCourierAssets;
use App\Support\RajaOngkirCouriers;
use App\Support\RajaOngkirQuoteContext;
use Illuminate\Support\Facades\Cache;
use Shopper\Cart\CartSessionManager;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\Inventory;
use Shopper\Shipping\DataTransferObjects\Package;
use Throwable;

/**
 * Checkout rates straight from Komerce Delivery `GET /tariff/api/v1/calculate`,
 * the same product that later creates the order and issues the AWB.
 *
 * The response already lists only couriers active for this account, with the
 * exact `shipping_cost` / `shipping_cashback` / `service_fee` that Store Order
 * expects, so no courier code list and no re-quote at fulfilment is needed.
 */
final class FetchKomerceDeliveryRates
{
    public const PROVIDER = 'shipping_delivery';

    /** Order Komerce buckets are shown in. */
    private const BUCKETS = ['calculate_reguler', 'calculate_cargo', 'calculate_instant'];

    public function __construct(
        private readonly ShippingDeliveryClient $delivery,
        private readonly DestinationCoordinateResolver $coordinates,
    ) {}

    /**
     * @param  array<string, mixed>  $shippingAddress
     * @param  array<int, Package>  $packages
     * @return array<int, array<string, mixed>>|null Null when Delivery cannot quote this request (caller falls back)
     */
    public function handle(array $shippingAddress, array $packages, ?int $originInventoryId = null, ?int $itemValue = null): ?array
    {
        if (! komerce_shipping_delivery_enabled()) {
            return null;
        }

        $inventory = $originInventoryId !== null
            ? Inventory::query()->find($originInventoryId)
            : (Inventory::query()->where('is_default', true)->first() ?? Inventory::query()->first());

        $originId = trim((string) $inventory?->getAttribute('rajaongkir_origin_id'));
        $originPin = $this->pinPoint($inventory?->getAttribute('latitude'), $inventory?->getAttribute('longitude'));
        $destinationId = trim((string) ($shippingAddress['rajaongkir_destination_id'] ?? $shippingAddress['destination_id'] ?? ''));

        if ($inventory === null || $originId === '' || $originPin === null || $destinationId === '') {
            return null;
        }

        $destinationPin = $this->coordinates->resolve($shippingAddress, $originPin);

        if ($destinationPin === null) {
            return null;
        }

        $weightKg = $this->weightKilograms($packages);
        // Komerce rejects item_value <= 0 ("Item value is required and must be greater than 0").
        $itemValue = max(1, $itemValue ?? $this->cartItemValue());
        $cacheKey = 'komerce_delivery_rates:'.sha1("{$originId}:{$destinationId}:{$originPin}:{$destinationPin}:{$weightKg}:{$itemValue}");

        try {
            $response = Cache::remember($cacheKey, now()->addMinutes(10), fn (): array => $this->delivery->calculate(
                $originId,
                $destinationId,
                $originPin,
                $destinationPin,
                $weightKg,
                $itemValue,
            ));
        } catch (Throwable $e) {
            report($e);
            resolve(RajaOngkirQuoteContext::class)->markFailure($e->getMessage());

            return [];
        }

        return $this->format($response);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<int, array<string, mixed>>
     */
    private function format(array $response): array
    {
        $carriers = Carrier::query()->get()->keyBy(static fn (Carrier $carrier): string => strtolower((string) $carrier->slug));
        $options = [];

        foreach (self::BUCKETS as $bucket) {
            foreach ((array) data_get($response, "data.{$bucket}", []) as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $shippingName = trim((string) ($row['shipping_name'] ?? ''));
                $serviceName = trim((string) ($row['service_name'] ?? ''));
                $cost = $row['shipping_cost'] ?? null;

                if ($shippingName === '' || $serviceName === '' || ! is_numeric($cost) || (int) $cost < 1) {
                    continue;
                }

                $carrierCode = RajaOngkirCouriers::codeFromKomerceName($shippingName) ?? strtolower($shippingName);
                $carrier = $carriers->get($carrierCode);

                // Couriers switched off in cpanel stay hidden even if the account can ship with them.
                if ($carrier instanceof Carrier && ! $carrier->is_enabled) {
                    continue;
                }

                $options[] = [
                    'service_code' => "{$carrierCode}:{$serviceName}",
                    'service_name' => $serviceName,
                    'amount' => (int) $cost,
                    'currency' => 'IDR',
                    'carrier_code' => $carrierCode,
                    'estimated_days' => $this->etd($row['etd'] ?? null),
                    'description' => CourierServiceExplainer::explain($carrierCode, $serviceName),
                    'carrier_name' => $carrier?->name ?? RajaOngkirCouriers::name($carrierCode) ?? $shippingName,
                    'carrier_logo' => data_get($carrier?->metadata, 'logo_url') ?? KomerceCourierAssets::logoUrl($carrierCode),
                    // Official Delivery tariff row, persisted as-is for Store Order.
                    'provider' => self::PROVIDER,
                    'group' => str_replace('calculate_', '', $bucket),
                    'shipping_name' => $shippingName,
                    'shipping_cost' => (int) $cost,
                    'shipping_cashback' => (int) ($row['shipping_cashback'] ?? 0),
                    'shipping_cost_net' => (int) ($row['shipping_cost_net'] ?? ((int) $cost - (int) ($row['shipping_cashback'] ?? 0))),
                    'service_fee' => (int) ($row['service_fee'] ?? 0),
                    'grandtotal' => (int) ($row['grandtotal'] ?? 0),
                    'is_cod' => (bool) ($row['is_cod'] ?? false),
                ];
            }
        }

        return $options;
    }

    /**
     * @param  array<int, Package>  $packages
     */
    private function weightKilograms(array $packages): float
    {
        $grams = 0.0;

        foreach ($packages as $package) {
            $weight = max(0.0, $package->weight);
            $grams += $package->isImperial() ? $weight * 453.59237 : $weight * 1000;
        }

        return round(max(0.01, $grams / 1000), 3);
    }

    /**
     * Legacy fallback for callers that did not pass an item value. Read-only:
     * never create a cart here — on the stateless API cartSession() would mint
     * a fresh empty cart for the user and hide their real one.
     */
    private function cartItemValue(): int
    {
        try {
            if (! request()->hasSession()) {
                return 0;
            }

            $cart = resolve(CartSessionManager::class)->current();
            if ($cart === null) {
                return 0;
            }

            $total = 0;

            foreach ($cart->lines as $line) {
                $total += (int) $line->unit_price_amount * max(1, (int) $line->quantity);
            }

            return $total;
        } catch (Throwable) {
            return 0;
        }
    }

    private function pinPoint(mixed $lat, mixed $lng): ?string
    {
        return is_numeric($lat) && is_numeric($lng) ? $lat.','.$lng : null;
    }

    /**
     * Komerce returns e.g. "1-2 day" / "-"; the storefront appends its own
     * "hari", so keep only the number range.
     */
    private function etd(mixed $etd): ?string
    {
        $value = is_scalar($etd) ? trim((string) $etd) : '';
        $value = trim((string) preg_replace('/\s*(hari|days?)\s*$/iu', '', $value));

        return $value !== '' && $value !== '-' ? $value : null;
    }
}
