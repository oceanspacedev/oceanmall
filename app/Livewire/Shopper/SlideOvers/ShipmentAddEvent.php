<?php

declare(strict_types=1);

namespace App\Livewire\Shopper\SlideOvers;

use App\Models\OrderShipment;
use App\Shipping\ShippingCapabilities;
use Filament\Notifications\Notification;
use Shopper\Livewire\SlideOvers\ShipmentAddEvent as ShopperShipmentAddEvent;

/**
 * Shopper's "add shipment event". A parcel tracked by its provider gets its
 * events from that provider only; manual parcels keep Shopper's form.
 */
final class ShipmentAddEvent extends ShopperShipmentAddEvent
{
    public function save(): void
    {
        $provider = $this->providerName();

        if ($provider !== null) {
            $this->authorize('edit_orders');

            Notification::make()
                ->title("Status kirim hanya dari {$provider}.")
                ->body('Jangan tambah event resi Shopper. Lacak AWB di penyedia pengiriman.')
                ->danger()
                ->send();

            return;
        }

        parent::save();
    }

    private function providerName(): ?string
    {
        $tracking = trim((string) $this->shipment->tracking_number);
        $capabilities = resolve(ShippingCapabilities::class);

        $shipments = OrderShipment::query()
            ->where('order_id', $this->shipment->order_id)
            ->when($tracking !== '', static fn ($query) => $query->where(
                static fn ($q) => $q->where('awb', $tracking)->orWhere('tracking_number', $tracking),
            ))
            ->get();

        foreach ($shipments as $orderShipment) {
            if (($driver = $capabilities->fulfillerFor($orderShipment)) !== null) {
                return $driver->name();
            }
        }

        return null;
    }
}
