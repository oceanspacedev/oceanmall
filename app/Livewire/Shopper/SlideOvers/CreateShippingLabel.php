<?php

declare(strict_types=1);

namespace App\Livewire\Shopper\SlideOvers;

use App\Actions\Shipping\IssueOrderFulfillment;
use App\Actions\Shipping\MirrorManualShopperShipping;
use App\Actions\Shipping\NormalizeShipmentStatus;
use App\Models\OrderShipment;
use App\Shipping\ShipmentMetadata;
use App\Shipping\ShippingCapabilities;
use App\Support\EnabledCarriers;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use RuntimeException;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\OrderItem;
use Shopper\Core\Models\OrderShipping;
use Shopper\Livewire\SlideOvers\CreateShippingLabel as BaseCreateShippingLabel;
use Throwable;

/**
 * Shopper's "create shipping label" slide-over. For an order whose shipments a
 * provider fulfils, the provider issues the AWB instead of the form; every
 * other order (manual carrier, zone option, COD) saves Shopper's own label,
 * which fires Shopper's OrderShipmentCreated.
 */
final class CreateShippingLabel extends BaseCreateShippingLabel
{
    #[Locked]
    public ?int $targetShipmentId = null;

    public function mount(): void
    {
        $this->authorize('edit_orders');

        $allShipments = OrderShipment::query()
            ->where('order_id', $this->order->id)
            ->orderBy('id')
            ->get();

        $activeShipments = $allShipments
            ->reject(static fn (OrderShipment $s): bool => strtolower(trim((string) $s->status)) === NormalizeShipmentStatus::CANCELLED
                || ShipmentMetadata::isSupersededByReship($s, $allShipments))
            ->values();

        $capabilities = resolve(ShippingCapabilities::class);
        $hasManualNeed = $capabilities->hasUnlabelledManualShipment($this->order);

        $shipment = $hasManualNeed
            ? ($activeShipments->first(
                static fn (OrderShipment $s): bool => $capabilities->fulfillerFor($s) === null
                    && trim((string) ($s->awb ?: $s->tracking_number)) === '',
            ) ?? $activeShipments->first())
            : ($activeShipments->first(
                static fn (OrderShipment $s): bool => trim((string) ($s->awb ?: $s->tracking_number)) === '',
            ) ?? $activeShipments->first());

        $this->targetShipmentId = $shipment?->id;

        parent::mount();

        $carrierId = $hasManualNeed
            ? ($shipment?->carrier_id ?: $this->order->shippingOption?->carrier_id)
            : $this->order->shippingOption?->carrier_id;

        // The shipment's own carrier first; else the carrier of that courier
        // owned by the shipment's driver. Several provider modules can each
        // own a "JNE" carrier, and staff cannot tell them apart in the select.
        if (! $carrierId && $shipment) {
            $carrierId = $shipment->carrier_id ?: null;
        }

        if (! $carrierId && $shipment) {
            $carrierCode = strtolower(trim((string) ($shipment->carrier_code ?: $shipment->carrier_name)));
            $driver = is_string($shipment->driver) ? trim($shipment->driver) : '';

            $carrierId = Carrier::query()
                ->when($driver !== '', static fn ($query) => $query->where('driver', $driver))
                ->get()
                ->first(static fn (Carrier $carrier): bool => $carrierCode !== ''
                    && (EnabledCarriers::courierCode($carrier) === $carrierCode || strtolower((string) $carrier->slug) === $carrierCode))
                ?->id;
        }

        if (! $carrierId) {
            $carrierId = Carrier::query()->where('is_enabled', true)->where('driver', 'manual')->value('id')
                ?? Carrier::query()->where('is_enabled', true)->value('id');
        }

        $awb = $shipment?->awb ?? $shipment?->tracking_number;

        $state = $this->form->getRawState();
        $state['carrier_id'] = $carrierId;
        if ($awb !== null && $awb !== '') {
            $state['tracking_number'] = $awb;
            $state['tracking_url'] = route('account.orders.show', $this->order);
        }

        $this->form->fill($state);
    }

    /** @return EloquentCollection<int, OrderItem> */
    #[Computed]
    public function unfulfilledItems(): EloquentCollection
    {
        $items = parent::unfulfilledItems();
        $capabilities = resolve(ShippingCapabilities::class);

        if (! $capabilities->hasUnlabelledManualShipment($this->order)) {
            return $items;
        }

        $allShipments = OrderShipment::query()
            ->with('lines')
            ->where('order_id', $this->order->id)
            ->orderBy('id')
            ->get();

        $manualShipments = $allShipments
            ->filter(fn (OrderShipment $s): bool => strtolower(trim((string) $s->status)) !== NormalizeShipmentStatus::CANCELLED
                && ! ShipmentMetadata::isSupersededByReship($s, $allShipments)
                && $capabilities->fulfillerFor($s) === null
                && trim((string) ($s->awb ?: $s->tracking_number)) === '')
            ->values();

        if ($manualShipments->isEmpty()) {
            return $items;
        }

        $targetShipment = $this->targetShipmentId !== null
            ? $manualShipments->firstWhere('id', $this->targetShipmentId)
            : null;

        $scopeShipments = ($targetShipment instanceof OrderShipment && $targetShipment->lines->isNotEmpty())
            ? collect([$targetShipment])
            : $manualShipments;

        $manualProductKeys = $scopeShipments
            ->flatMap(static fn (OrderShipment $s) => $s->lines)
            ->map(static fn ($line): string => $line->purchasable_type.'|'.(int) $line->purchasable_id)
            ->unique()
            ->all();

        if ($manualProductKeys === []) {
            if ($items->isNotEmpty()) {
                return $items;
            }

            /** @var EloquentCollection<int, OrderItem> */
            return $this->order->items()->with('product', 'product.media')->get();
        }

        $filtered = $items->filter(
            static fn ($item): bool => in_array($item->product_type.'|'.(int) $item->product_id, $manualProductKeys, true),
        )->values();

        if ($filtered->isNotEmpty()) {
            return $filtered;
        }

        // Split-stock single OrderItem or reshipped OrderItem: order_shipping_id is
        // already bound to an earlier parcel, so fall back to the order items
        // matching this unlabelled manual shipment's lines.
        /** @var EloquentCollection<int, OrderItem> $allMatching */
        $allMatching = $this->order->items()
            ->with('product', 'product.media')
            ->get()
            ->filter(
                static fn ($item): bool => in_array($item->product_type.'|'.(int) $item->product_id, $manualProductKeys, true),
            )
            ->values();

        return $allMatching->isNotEmpty() ? $allMatching : $items;
    }

    public function save(): void
    {
        $capabilities = resolve(ShippingCapabilities::class);
        $fulfiller = $capabilities->fulfillerForOrder($this->order);

        if ($fulfiller === null || $capabilities->hasUnlabelledManualShipment($this->order)) {
            MirrorManualShopperShipping::whileTargeting(
                $this->targetShipmentId,
                fn () => parent::save(),
            );

            $latestShipping = OrderShipping::query()
                ->where('order_id', $this->order->id)
                ->latest('id')
                ->first();

            if ($latestShipping instanceof OrderShipping) {
                resolve(MirrorManualShopperShipping::class)->handle($latestShipping, $this->targetShipmentId);
            }

            return;
        }

        $this->authorize('edit_orders');
        $provider = $fulfiller->name();

        try {
            $result = resolve(IssueOrderFulfillment::class)->handle($this->order);
        } catch (RuntimeException $e) {
            Notification::make()
                ->title("{$provider} belum bisa menerbitkan resi.")
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        } catch (Throwable $e) {
            report($e);

            Notification::make()
                ->title("Gagal menerbitkan AWB {$provider}.")
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title("Resi {$provider} tercatat di Shopper.")
            ->body('AWB '.implode(', ', $result['awbs']).'. Shopper tidak membuat nomor resi sendiri.')
            ->success()
            ->send();

        $this->dispatch('order.shipping.created');
        $this->closePanel();

        if ($result['print_route'] !== null) {
            $this->redirect($result['print_route']);
        }
    }
}
