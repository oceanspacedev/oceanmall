<?php

declare(strict_types=1);

namespace App\Actions\Shipping;

use App\Models\OrderShipment;
use App\Shipping\Contracts\NormalizesSelectedRates;
use App\Shipping\InventoryOrigins;
use App\Shipping\ShippingCapabilities;
use App\Support\EnabledCarriers;
use Illuminate\Support\Collection;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Shopper\Shipping\Contracts\ShippingDriver;
use Shopper\Shipping\Facades\Shipping;
use Throwable;

/**
 * Guarantee a warehouse shipment row exists for an order placed without an
 * allocation plan (legacy orders, Shopper's own order flows), so staff and
 * providers have something to ship. The row copies the order's own Shopper
 * shipping option / carrier (no provider default is invented); the carrier's
 * driver may normalize the rate into its fulfilment vocabulary
 * (NormalizesSelectedRates).
 */
final class EnsureOrderShipments
{
    /**
     * @return Collection<int, OrderShipment>
     */
    public function handle(Order $order): Collection
    {
        $existing = OrderShipment::query()->where('order_id', $order->id)->get();

        if ($existing->isNotEmpty()) {
            return $existing;
        }

        $capabilities = resolve(ShippingCapabilities::class);
        $originDriver = $capabilities->originDriverCode();

        $defaultInventory = Inventory::query()->where('is_default', true)->first()
            ?? ($originDriver !== null
                ? Inventory::query()->whereIn('id', resolve(InventoryOrigins::class)->inventoryIdsFor($originDriver))->first()
                : null)
            ?? Inventory::query()->first();

        if (! $defaultInventory) {
            return collect();
        }

        $order->loadMissing(['items', 'shippingOption.carrier']);

        $shippingOption = $order->shippingOption;
        $carrier = $shippingOption?->carrier;
        $carrierCode = $carrier !== null ? EnabledCarriers::courierCode($carrier) : null;
        $carrierName = $carrier?->name;
        $serviceName = $shippingOption?->name;
        $serviceCode = $shippingOption !== null
            ? strtoupper((string) (data_get($shippingOption->metadata, 'service_code') ?: ($shippingOption->name ?: $shippingOption->id)))
            : null;
        $shippingCost = (int) ($order->shipping_total ?? 0);

        if ($shippingOption !== null && $shippingCost === 0 && isset($shippingOption->price)) {
            $shippingCost = (int) $shippingOption->price;
        }

        $metadata = is_array($order->metadata)
            ? $order->metadata
            : json_decode((string) $order->metadata, true) ?? [];

        if (isset($metadata['shipping']) && is_array($metadata['shipping'])) {
            $shippingMeta = $metadata['shipping'];
            $carrierCode = self::string($shippingMeta['courier_code'] ?? $shippingMeta['courier'] ?? null) ?? $carrierCode;
            $carrierName = self::string($shippingMeta['courier_name'] ?? null) ?? $carrierName;
            $serviceCode = self::string($shippingMeta['service_code'] ?? $shippingMeta['service'] ?? null) ?? $serviceCode;
            $serviceName = self::string($shippingMeta['service_name'] ?? $shippingMeta['service_description'] ?? null) ?? $serviceName;

            if (! empty($shippingMeta['cost'])) {
                $shippingCost = (int) $shippingMeta['cost'];
            }
        }

        $carrierCode = $carrierCode !== null ? strtolower(trim($carrierCode)) : null;
        $serviceName ??= $serviceCode;

        // The Shopper carrier's driver; a plain zone option is 'manual'.
        $driverCode = $shippingOption !== null
            ? (filled($carrier?->driver) ? (string) $carrier->driver : 'manual')
            : null;

        $rate = array_filter([
            'carrier_code' => $carrierCode,
            'carrier_name' => $carrierName,
            'service_code' => $serviceCode,
            'service_name' => $serviceName,
            'amount' => $shippingCost,
        ], static fn (mixed $value): bool => $value !== null);

        $normalizer = $this->normalizer($driverCode, $order, $capabilities);

        if ($normalizer !== null) {
            $rate = $normalizer->normalizeSelectedRate($rate, $shippingCost);
            $carrierName = self::string($rate['shipping_name'] ?? null) ?? $carrierName;
            $serviceName = self::string($rate['service_name'] ?? null) ?? $serviceName;
        }

        $shipment = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $defaultInventory->id,
            'driver' => $driverCode,
            'carrier_id' => $carrier?->id,
            'status' => 'pending',
            'carrier_code' => $carrierCode,
            'carrier_name' => $carrierName,
            'service_code' => $serviceCode,
            'service_name' => $serviceName,
            'cost' => $shippingCost,
            'currency_code' => $order->currency_code ?? 'IDR',
            'metadata' => ['rate' => $rate],
        ]);

        foreach ($order->items as $item) {
            $shipment->lines()->create([
                'purchasable_type' => $item->product_type,
                'purchasable_id' => $item->product_id,
                'qty' => max(1, (int) $item->quantity),
            ]);
        }

        return collect([$shipment]);
    }

    /**
     * The driver that names this shipment's courier / service in its own
     * vocabulary: the carrier's driver, or, for an order with no Shopper
     * shipping option, the store's single fulfilling driver.
     *
     * @return (ShippingDriver&NormalizesSelectedRates)|null
     */
    private function normalizer(?string $driverCode, Order $order, ShippingCapabilities $capabilities): ?ShippingDriver
    {
        try {
            $driver = $driverCode !== null && $driverCode !== 'manual'
                ? Shipping::driver($driverCode)
                : ($driverCode === null ? $capabilities->fulfillerForOrder($order) : null);
        } catch (Throwable) {
            return null;
        }

        return $driver instanceof NormalizesSelectedRates ? $driver : null;
    }

    private static function string(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
