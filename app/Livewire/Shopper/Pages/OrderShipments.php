<?php

declare(strict_types=1);

namespace App\Livewire\Shopper\Pages;

use App\Actions\Shipping\NormalizeShipmentStatus;
use App\Actions\Shipping\RefreshShipmentTracking;
use App\Models\OrderShipment;
use App\Shipping\ShippingCapabilities;
use App\Support\OrderShipmentOpsPresenter;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Mckenziearts\Icons\Untitledui\Enums\Untitledui;
use Shopper\Core\Enum\OrderStatus;
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

    /** @var array<int, OrderShipment|null> */
    private array $matchedShipments = [];

    public function table(Table $table): Table
    {
        $table = parent::table($table);

        $shopperActions = array_map(function ($action) {
            if (! $action instanceof Action) {
                return $action;
            }

            return match ($action->getName()) {
                'markDelivered' => $action->visible(
                    fn (OrderShipping $record): bool => $record->canBeDelivered()
                        && ! $this->isCancelledRecord($record)
                        && $this->fulfillerFor($record) === null,
                ),
                'edit' => $action->visible(
                    fn (OrderShipping $record): bool => ! $this->isCancelledRecord($record)
                        && $this->fulfillerFor($record) === null,
                ),
                default => $action,
            };
        }, $table->getRecordActions());

        return $table->recordActions([
            Action::make('printProviderLabel')
                ->label('Cetak Stiker Resi')
                ->icon(Untitledui::Printer)
                ->color('primary')
                ->authorize('edit_orders')
                ->visible(function (OrderShipping $record): bool {
                    if ($this->isCancelledRecord($record)) {
                        return false;
                    }

                    $shipment = $this->orderShipmentFor($record);

                    if ($shipment === null) {
                        return false;
                    }

                    return resolve(OrderShipmentOpsPresenter::class)->canPrintLabel($shipment);
                })
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
                ->visible(function (OrderShipping $record): bool {
                    if (trim((string) $record->tracking_number) === '') {
                        return false;
                    }

                    $shipment = $this->orderShipmentFor($record);

                    if ($shipment === null || $this->isCancelled($shipment)) {
                        return false;
                    }

                    if (trim((string) $shipment->awb) === '' && trim((string) $shipment->tracking_number) === '') {
                        return false;
                    }

                    return resolve(ShippingCapabilities::class)->trackerFor($shipment) !== null;
                })
                ->action(function (OrderShipping $record): void {
                    $shipment = $this->orderShipmentFor($record);

                    if ($shipment === null) {
                        Notification::make()
                            ->title('Pengiriman tidak ditemukan.')
                            ->danger()
                            ->send();

                        return;
                    }

                    if ($this->isCancelled($shipment)) {
                        Notification::make()
                            ->title('Pengiriman yang sudah dibatalkan tidak dapat dilacak.')
                            ->danger()
                            ->send();

                        return;
                    }

                    if (trim((string) $shipment->awb) === '' && trim((string) $shipment->tracking_number) === '') {
                        Notification::make()
                            ->title('Nomor resi belum tersedia untuk paket ini.')
                            ->danger()
                            ->send();

                        return;
                    }

                    $provider = resolve(ShippingCapabilities::class)->trackerFor($shipment)?->name() ?? 'penyedia pengiriman';

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
        ])->toolbarActions([
            BulkAction::make('bulkPrintLabels')
                ->label('Cetak Label Terpilih')
                ->icon(Untitledui::Printer)
                ->action(function (Collection $records): void {
                    $orderIds = $records->pluck('order_id')->filter()->unique()->implode(',');

                    if ($orderIds !== '') {
                        $this->redirect(route('shopper.orders.warehouse.bulk-labels', ['orders' => $orderIds]));
                    }
                }),
            BulkAction::make('bulkPickList')
                ->label('Cetak Pick List')
                ->icon(Untitledui::ClipboardCheck)
                ->action(function (Collection $records): void {
                    $orderIds = $records->pluck('order_id')->filter()->unique()->implode(',');

                    if ($orderIds !== '') {
                        $this->redirect(route('shopper.orders.warehouse.pick-list', ['orders' => $orderIds]));
                    }
                }),
        ]);
    }

    private function isCancelled(OrderShipment $shipment): bool
    {
        return strtolower(trim((string) $shipment->status)) === NormalizeShipmentStatus::CANCELLED;
    }

    private function isCancelledRecord(OrderShipping $record): bool
    {
        if ($record->order?->status === OrderStatus::Cancelled) {
            return true;
        }

        $shipment = $this->orderShipmentFor($record);

        if ($shipment === null) {
            return false;
        }

        if ($this->isCancelled($shipment)) {
            if (trim((string) $record->tracking_number) !== '') {
                return true;
            }

            return ! $record->items()->exists();
        }

        if (
            trim((string) $record->tracking_number) === ''
            && ! $record->items()->exists()
            && ! $this->hasActiveBlankOrderShipment((int) $record->order_id)
            && $this->hasCancelledOrderShipment((int) $record->order_id)
        ) {
            return true;
        }

        return false;
    }

    private function hasActiveBlankOrderShipment(int $orderId): bool
    {
        return OrderShipment::query()
            ->where('order_id', $orderId)
            ->where(static function ($query): void {
                $query->whereNull('status')
                    ->orWhereRaw('lower(status) != ?', [NormalizeShipmentStatus::CANCELLED]);
            })
            ->where(static function ($query): void {
                $query->whereNull('awb')->orWhere('awb', '');
            })
            ->where(static function ($query): void {
                $query->whereNull('tracking_number')->orWhere('tracking_number', '');
            })
            ->exists();
    }

    private function hasCancelledOrderShipment(int $orderId): bool
    {
        return OrderShipment::query()
            ->where('order_id', $orderId)
            ->whereRaw('lower(status) = ?', [NormalizeShipmentStatus::CANCELLED])
            ->exists();
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
        if (array_key_exists($record->id, $this->matchedShipments)) {
            return $this->matchedShipments[$record->id];
        }

        $awb = trim((string) $record->tracking_number);

        $baseQuery = OrderShipment::query()
            ->where('order_id', $record->order_id)
            ->when($awb !== '', static function ($query) use ($awb): void {
                $query->where(static function ($q) use ($awb): void {
                    $q->where('awb', $awb)->orWhere('tracking_number', $awb);
                });
            })
            ->orderByDesc('id');

        $active = (clone $baseQuery)
            ->where(static function ($query): void {
                $query->whereNull('status')
                    ->orWhereRaw('lower(status) != ?', [NormalizeShipmentStatus::CANCELLED]);
            })
            ->first();

        return $this->matchedShipments[$record->id] = $active instanceof OrderShipment
            ? $active
            : $baseQuery->first();
    }
}
