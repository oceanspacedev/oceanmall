<?php

declare(strict_types=1);

namespace App\Livewire\Shopper\SlideOvers;

use App\Actions\Shipping\NormalizeShipmentStatus;
use App\Models\OrderShipment;
use App\Shipping\ShippingCapabilities;
use App\Support\OrderPaymentType;
use Filament\Notifications\Notification;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Enum\ShipmentStatus;
use Shopper\Core\Events\Orders\OrderShipmentDelivered;
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

        $fresh = $this->shipment->fresh(['order']);

        if ($fresh !== null && $fresh->status === ShipmentStatus::Delivered && $fresh->order !== null) {
            $order = $fresh->order;

            // Shopper's RecordShipmentEventAction marks Delivered -> Completed unconditionally.
            // Unpaid COD orders remain Processing until cash remittance is confirmed.
            if (OrderPaymentType::isCod($order) && $order->payment_status !== PaymentStatus::Paid && $order->status === OrderStatus::Completed) {
                $order->forceFill(['status' => OrderStatus::Processing])->save();
            }

            event(new OrderShipmentDelivered($order, $fresh));
        }
    }

    private function providerName(): ?string
    {
        $tracking = trim((string) $this->shipment->tracking_number);
        $capabilities = resolve(ShippingCapabilities::class);

        $shipments = OrderShipment::query()
            ->where('order_id', $this->shipment->order_id)
            ->where(static function ($query): void {
                $query->whereNull('status')
                    ->orWhereRaw('lower(status) != ?', [NormalizeShipmentStatus::CANCELLED]);
            })
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
