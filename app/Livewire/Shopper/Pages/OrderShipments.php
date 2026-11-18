<?php

declare(strict_types=1);

namespace App\Livewire\Shopper\Pages;

use App\Actions\Shipping\RefreshShipmentTracking;
use App\Models\OrderShipment;
use App\Shipping\Contracts\PrintsLabels;
use App\Shipping\ShippingCapabilities;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Table;
use Mckenziearts\Icons\Untitledui\Enums\Untitledui;
use Shopper\Core\Models\OrderShipping;
use Shopper\Livewire\Pages\Order\Shipments as ShopperShipments;
use Shopper\Shipping\Contracts\ShippingDriver;
use Throwable;

/**
 * Shopper's shipments list, deciding per parcel: a parcel whose warehouse
 * shipment a provider fulfils (FulfillsShipments) gets the provider actions
 * (print label, refresh tracking) and loses Shopper's manual "mark delivered"
 * / edit-tracking actions, since its status comes from the provider. Manual
 * parcels keep Shopper's actions, even while a provider is configured.
 */
final class OrderShipments extends ShopperShipments
{
    /** @var array<int, ShippingDriver|null> */
    private array $fulfillers = [];

    public function table(Table $table): Table
    {
        $table = parent::table($table);

        $shopperActions = array_map(function ($action) {
            if (! $action instanceof Action) {
                return $action;
            }

            return match ($action->getName()) {
                'markDelivered' => $action->visible(
                    fn (OrderShipping $record): bool => $record->canBeDelivered() && $this->fulfillerFor($record) === null,
                ),
                'edit' => $action->visible(fn (OrderShipping $record): bool => $this->fulfillerFor($record) === null),
                default => $action,
            };
        }, $table->getRecordActions());

        return $table->recordActions([
            Action::make('printProviderLabel')
                ->label('Cetak Stiker Resi')
                ->icon(Untitledui::Printer)
                ->color('primary')
                ->authorize('edit_orders')
                ->visible(fn (OrderShipping $record): bool => $this->fulfillerFor($record) instanceof PrintsLabels)
                ->url(function (OrderShipping $record): ?string {
                    $shipment = $this->orderShipmentFor($record);

                    if ($shipment === null || $shipment->order === null) {
                        return null;
                    }

                    return route('shopper.orders.fulfillment.print-label', [
                        'order' => $shipment->order,
                        'shipment' => $shipment->id,
                    ]);
                }, shouldOpenInNewTab: true),
            Action::make('refreshProviderTracking')
                ->label('Perbarui pelacakan kurir')
                ->icon(Untitledui::RefreshCcw02)
                ->color('gray')
                ->authorize('edit_orders')
                ->visible(fn (OrderShipping $record): bool => filled($record->tracking_number) && $this->fulfillerFor($record) !== null)
                ->action(function (OrderShipping $record): void {
                    $shipment = $this->orderShipmentFor($record);
                    $provider = $this->fulfillerFor($record)?->name() ?? 'penyedia pengiriman';

                    if ($shipment === null) {
                        Notification::make()
                            ->title('Pengiriman tidak ditemukan.')
                            ->danger()
                            ->send();

                        return;
                    }

                    try {
                        resolve(RefreshShipmentTracking::class)->handle($shipment);
                    } catch (Throwable $e) {
                        report($e);

                        Notification::make()
                            ->title("Gagal memperbarui pelacakan {$provider}.")
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title("Status kurir diperbarui dari {$provider}.")
                        ->success()
                        ->send();
                }),
            ...$shopperActions,
        ]);
    }

    private function fulfillerFor(OrderShipping $record): ?ShippingDriver
    {
        if (! array_key_exists($record->id, $this->fulfillers)) {
            $shipment = $this->orderShipmentFor($record);

            $this->fulfillers[$record->id] = $shipment !== null
                ? resolve(ShippingCapabilities::class)->fulfillerFor($shipment)
                : null;
        }

        return $this->fulfillers[$record->id];
    }

    private function orderShipmentFor(OrderShipping $record): ?OrderShipment
    {
        $awb = trim((string) $record->tracking_number);

        if ($awb === '') {
            return OrderShipment::query()
                ->where('order_id', $record->order_id)
                ->orderByDesc('id')
                ->first();
        }

        return OrderShipment::query()
            ->where('order_id', $record->order_id)
            ->where(function ($query) use ($awb): void {
                $query->where('awb', $awb)->orWhere('tracking_number', $awb);
            })
            ->orderByDesc('id')
            ->first();
    }
}
