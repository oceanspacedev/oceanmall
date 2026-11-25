<?php

declare(strict_types=1);

namespace App\Livewire\Shopper\SlideOvers;

use App\Actions\Shipping\IssueOrderFulfillment;
use App\Models\OrderShipment;
use App\Shipping\ShippingCapabilities;
use App\Support\EnabledCarriers;
use Filament\Notifications\Notification;
use RuntimeException;
use Shopper\Core\Models\Carrier;
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
    public function mount(): void
    {
        parent::mount();

        $shipment = OrderShipment::query()
            ->where('order_id', $this->order->id)
            ->first();

        $carrierId = $this->order->shippingOption?->carrier_id;

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

    public function save(): void
    {
        $fulfiller = resolve(ShippingCapabilities::class)->fulfillerForOrder($this->order);

        if ($fulfiller === null) {
            parent::save();

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
