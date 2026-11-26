<?php

declare(strict_types=1);

namespace App\Support;

use App\Actions\Shipping\NormalizeShipmentStatus;
use App\Models\OrderShipment;
use App\Shipping\Contracts\PrintsLabels;
use App\Shipping\InventoryOrigins;
use App\Shipping\ShipmentMetadata;
use App\Shipping\ShippingCapabilities;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;

/**
 * Shared shipment / inventory payloads for the cpanel order panels (allocation
 * override, label printing and provider fulfilment cards).
 */
final class OrderShipmentOpsPresenter
{
    /**
     * @return list<array<string, mixed>>
     */
    public function shipments(Order $order): array
    {
        return OrderShipment::query()
            ->with(['lines.purchasable', 'inventory', 'order.shippingAddress', 'order.customer'])
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->get()
            ->map(function (OrderShipment $shipment) use ($order): array {
                $deliveryOrderNo = $this->deliveryOrderNo($shipment);
                $canPrint = $this->canPrintLabel($shipment);
                $needsPickup = $this->needsPickup($shipment);
                $canOverride = ! $this->isLockedForOverride($shipment);
                $hasPickup = ShipmentMetadata::pickupRequested($shipment);
                $providerFulfilled = $deliveryOrderNo !== null
                    || resolve(ShippingCapabilities::class)->fulfillerFor($shipment) !== null;

                $inventory = $shipment->inventory;
                $shipperAddress = implode(', ', array_filter([
                    $inventory?->street_address,
                    $inventory?->street_address_plus,
                    $inventory?->city,
                    $inventory?->postal_code,
                ], static fn (mixed $p): bool => is_scalar($p) && trim((string) $p) !== ''));

                if ($shipperAddress === '') {
                    $shipperAddress = implode(', ', array_filter([
                        shopper_setting('street_address'),
                        shopper_setting('city'),
                        shopper_setting('postal_code'),
                    ], static fn (mixed $p): bool => is_scalar($p) && trim((string) $p) !== ''));
                }

                $shipperPhone = trim((string) ($inventory?->phone_number ?? shopper_setting('phone_number') ?? '-')) ?: '-';

                $shippingAddress = $order->shippingAddress;
                $receiverName = trim(implode(' ', array_filter([
                    $shippingAddress?->first_name,
                    $shippingAddress?->last_name,
                ], static fn (mixed $p): bool => is_scalar($p) && trim((string) $p) !== '')));
                if ($receiverName === '') {
                    $receiverName = (string) ($order->customer?->full_name ?? 'Pelanggan');
                }

                $receiverAddress = implode(', ', array_filter([
                    $shippingAddress?->street_address,
                    $shippingAddress?->street_address_plus,
                    $shippingAddress?->city,
                    $shippingAddress?->postal_code,
                ], static fn (mixed $p): bool => is_scalar($p) && trim((string) $p) !== ''));

                $receiverPhone = (string) ($shippingAddress?->phone_number ?? $shippingAddress?->phone ?? '-');

                $carrier = (string) ($shipment->carrier_name ?: $shipment->carrier_code);
                $service = (string) ($shipment->service_name ?: $shipment->service_code);

                if (str_contains($service, ':')) {
                    $parts = explode(':', $service);
                    $service = end($parts);
                }

                if ($carrier === $service || in_array(strtoupper($carrier), ['STD', 'EZ', 'REG', 'EXPRESS', 'SIUNT'], true)) {
                    $carrier = strtoupper((string) ($shipment->carrier_code ?: $carrier));
                }

                $carrierCode = strtolower(trim((string) ($shipment->carrier_code ?: $shipment->carrier_name)));
                $serviceCode = strtoupper(trim($service));
                $serviceDesc = CourierServiceExplainer::explain($carrierCode, $serviceCode);

                $carrierLogo = resolve(CarrierLogos::class)->forCode($carrierCode);

                $carrierNameDisplay = match ($carrierCode) {
                    'jnt', 'j&t' => 'J&T Express',
                    'jne' => 'JNE Express',
                    'sicepat' => 'SiCepat Ekspres',
                    'anteraja' => 'Anteraja',
                    'pos' => 'POS Indonesia',
                    'tiki' => 'TIKI',
                    'lion' => 'Lion Parcel',
                    'ninja' => 'Ninja Xpress',
                    'ide', 'idexpress' => 'ID Express',
                    'wahana' => 'Wahana Express',
                    default => strtoupper($carrier),
                };

                $isCodOrder = OrderPaymentType::isCod($order);
                $codAmount = $isCodOrder
                    ? (OrderShipment::query()->where('order_id', $order->id)->count() === 1
                        ? (int) $order->price_amount
                        : (ShipmentItemValue::forOrder($order, $shipment->lines) + (int) $shipment->cost))
                    : 0;
                $canReship = in_array(
                    strtolower(trim((string) $shipment->status)),
                    [NormalizeShipmentStatus::RETURNED, NormalizeShipmentStatus::DELIVERY_FAILED],
                    true,
                ) && empty(data_get($shipment->metadata, 'reshipped_to_shipment_id'));

                return [
                    'id' => $shipment->id,
                    'inventory_id' => $shipment->inventory_id,
                    'inventory_name' => $shipment->inventory?->name,
                    'driver' => $shipment->driver,
                    'status' => $shipment->status,
                    'status_label' => $this->statusLabel(is_string($shipment->status) ? $shipment->status : null),
                    'awb' => $shipment->awb,
                    'tracking_number' => $shipment->tracking_number,
                    'carrier_code' => $carrierCode,
                    'carrier' => $carrier,
                    'carrier_name_display' => $carrierNameDisplay,
                    'service' => $serviceCode,
                    'service_description' => $serviceDesc,
                    'carrier_logo' => $carrierLogo,
                    'cost' => (int) $shipment->cost,
                    'currency' => $shipment->currency_code,
                    'delivery_order_no' => $deliveryOrderNo,
                    'fulfillment_error' => ShipmentMetadata::fulfillmentError($shipment),
                    'can_track' => resolve(ShippingCapabilities::class)->trackerFor($shipment) !== null
                        && ($this->filled($shipment->awb) || $this->filled($shipment->tracking_number) || $deliveryOrderNo !== null),
                    'can_reship' => $canReship,
                    'can_print_label' => $canPrint,
                    'needs_pickup' => $needsPickup,
                    'print_hint' => $this->printHint($canPrint, $hasPickup, $needsPickup, $providerFulfilled),
                    'can_override' => $canOverride,
                    'cod_amount' => $codAmount,
                    'shipper_name' => $inventory?->name ?? (shopper_setting('name') ?: config('app.name', 'OceanMall')),
                    'shipper_address' => $shipperAddress ?: '-',
                    'shipper_phone' => $shipperPhone,
                    'receiver_name' => $receiverName,
                    'receiver_address' => $receiverAddress ?: '-',
                    'receiver_phone' => $receiverPhone,
                    'lines' => $shipment->lines->map(static function ($line): array {
                        $purchasable = $line->purchasable;

                        return [
                            'id' => $line->id,
                            'name' => $purchasable instanceof Model
                                ? (string) ($purchasable->getAttribute('name') ?? class_basename($purchasable))
                                : 'Item',
                            'sku' => $purchasable instanceof Model
                                ? (string) ($purchasable->getAttribute('sku') ?? '')
                                : '',
                            'purchasable_type' => $line->purchasable_type,
                            'purchasable_id' => $line->purchasable_id,
                            'qty' => (int) $line->qty,
                        ];
                    })->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function inventories(?string $originDriver = null): array
    {
        $inventories = Inventory::query()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'name', 'is_default']);

        // Origins are keyed by the driver whose destination vocabulary they
        // use: the caller's (a provider panel passes its own), otherwise the
        // configured destination driver. Without one, every warehouse is
        // ready: Shopper's manual shipping needs no provider origin.
        $originDriver ??= resolve(ShippingCapabilities::class)->originDriverCode();
        $origins = $originDriver !== null
            ? resolve(InventoryOrigins::class)->forInventories($inventories->modelKeys(), $originDriver)
            : collect();

        return $inventories
            ->map(static function (Inventory $inventory) use ($origins, $originDriver): array {
                $originId = $origins->get($inventory->id);

                return [
                    'id' => $inventory->id,
                    'name' => $inventory->name,
                    'is_default' => (bool) $inventory->is_default,
                    'shipping_origin_id' => $originId,
                    'ready_for_shipping' => $originDriver === null || filled($originId),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $shipments
     */
    public function printableCount(array $shipments): int
    {
        return Collection::make($shipments)->where('can_print_label', true)->count();
    }

    /**
     * The provider order number of a shipment registered by any
     * FulfillsShipments driver (order_shipments.external_reference).
     */
    public function deliveryOrderNo(OrderShipment $shipment): ?string
    {
        return ShipmentMetadata::externalReference($shipment);
    }

    public function isLockedForOverride(OrderShipment $shipment): bool
    {
        $order = $shipment->order;

        if ($order instanceof Order) {
            if (in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Completed, OrderStatus::Archived], true)) {
                return true;
            }

            $orderMeta = $order->getAttribute('metadata');
            if (is_string($orderMeta) && trim($orderMeta) !== '') {
                $orderMeta = json_decode($orderMeta, true);
            }

            if (is_array($orderMeta) && filled($orderMeta['stock_released_at'] ?? null)) {
                return true;
            }
        }

        if (! in_array($shipment->status, ['pending', 'ready'], true)) {
            return true;
        }

        if ($this->filled($shipment->awb) || $this->filled($shipment->tracking_number)) {
            return true;
        }

        return $this->deliveryOrderNo($shipment) !== null;
    }

    public function needsPickup(OrderShipment $shipment): bool
    {
        if ($this->filled($shipment->awb) || $this->filled($shipment->tracking_number)) {
            return false;
        }

        if (ShipmentMetadata::pickupRequested($shipment)) {
            return false;
        }

        return $this->deliveryOrderNo($shipment) !== null;
    }

    public function canPrintLabel(OrderShipment $shipment): bool
    {
        if (strtolower((string) $shipment->status) === NormalizeShipmentStatus::CANCELLED) {
            return false;
        }

        if ($this->filled($shipment->awb) || $this->filled($shipment->tracking_number)) {
            $printer = resolve(ShippingCapabilities::class)->forShipmentSupporting($shipment, PrintsLabels::class);

            return $printer === null || $this->deliveryOrderNo($shipment) !== null;
        }

        return false;
    }

    public function statusLabel(?string $status): string
    {
        return match ($status) {
            'pending', 'ready' => 'Menunggu resi',
            'labeled' => 'Resi terbit',
            'picked_up' => 'Sudah di-pickup',
            'in_transit' => 'Dalam pengiriman',
            'delivery_failed' => 'Gagal kirim',
            'returned' => 'Retur (RTS)',
            'delivered' => 'Terkirim',
            'cancelled' => 'Dibatalkan',
            default => $status ? str_replace('_', ' ', ucfirst($status)) : 'Tidak diketahui',
        };
    }

    private function printHint(bool $canPrint, bool $hasPickup, bool $needsPickup, bool $providerFulfilled): ?string
    {
        return match (true) {
            $canPrint => null,
            $hasPickup => 'Pickup sudah diminta. Menunggu nomor resi terbit dari kurir.',
            $needsPickup => 'Request pickup dulu. Stiker resi baru bisa dicetak setelah pickup berhasil.',
            $providerFulfilled => 'Daftarkan paket ke layanan pengiriman dulu, lalu request pickup. Setelah nomor resi terbit, stiker bisa dicetak.',
            default => 'Pengiriman manual: isi nomor resi dari kartu pengiriman pesanan, lalu cetak label dari sana.',
        };
    }

    private function filled(mixed $value): bool
    {
        return is_scalar($value) && trim((string) $value) !== '';
    }
}
