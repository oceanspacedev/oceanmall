<?php

declare(strict_types=1);

namespace App\Livewire\Shopper;

use App\Actions\Shipping\IssueOrderFulfillment;
use App\Actions\Shipping\NormalizeShipmentStatus;
use App\Actions\Shipping\RefreshShipmentTracking;
use App\Actions\Shipping\ReshipOrderShipment;
use App\Models\OrderShipment;
use App\Shipping\ShipmentMetadata;
use App\Shipping\ShippingCapabilities;
use App\Support\OrderPaymentType;
use App\Support\OrderShipmentOpsPresenter;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Shopper\Livewire\Components\Orders\Fulfillment as ShopperFulfillment;
use Shopper\Shipping\Contracts\ShippingDriver;
use Throwable;

/**
 * Shopper's fulfillment card, deciding per order: when the order's shipments
 * are fulfilled by a provider (FulfillsShipments), the provider issues the AWB
 * and Shopper's "create shipping label" form is replaced by an "issue AWB"
 * bar; every other order (manual carriers, zone options, COD) keeps Shopper's
 * own label flow, even while a provider is configured.
 */
final class OrderFulfillment extends ShopperFulfillment
{
    /**
     * The only reader is Shopper's fulfillment view ("create shipping label"
     * button). Side effect for provider orders: once shipped, Shopper's
     * "all items fulfilled" note shows even if a second warehouse shipment
     * has no AWB yet; the bar above still lists the AWBs that exist.
     */
    public function hasUnfulfilledItems(): bool
    {
        $capabilities = resolve(ShippingCapabilities::class);

        if ($capabilities->hasUnlabelledManualShipment($this->order)) {
            return true;
        }

        if ($this->fulfiller() === null) {
            return parent::hasUnfulfilledItems();
        }

        return false;
    }

    public function openShippingLabel(): void
    {
        $capabilities = resolve(ShippingCapabilities::class);
        $fulfiller = $this->fulfiller();

        if ($fulfiller === null || $capabilities->hasUnlabelledManualShipment($this->order)) {
            parent::openShippingLabel();

            return;
        }

        $this->issueProviderFulfillment();
    }

    public function issueProviderFulfillment(): void
    {
        $fulfiller = $this->fulfiller();

        if ($fulfiller === null) {
            parent::openShippingLabel();

            return;
        }

        Gate::authorize('manage-order-fulfillment', $this->order);
        $provider = $fulfiller->name();

        try {
            $result = resolve(IssueOrderFulfillment::class)->handle($this->order);
        } catch (RuntimeException $e) {
            $notification = Notification::make()->body($e->getMessage());

            if ($this->hasRegisteredShipment()) {
                $notification->title('Paket sedang dipacking kurir.')->warning();
            } else {
                $notification->title("Resi {$provider} belum terbit.")->danger();
            }

            $notification->send();

            return;
        } catch (Throwable $e) {
            report($e);

            Notification::make()
                ->title("Gagal menerbitkan resi {$provider}.")
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->order->refresh();
        $this->dispatch('order.shipping.created');
        $this->dispatch('order.updated');

        Notification::make()
            ->title("Resi {$provider} tercatat di Shopper.")
            ->body('AWB '.implode(', ', $result['awbs']).'. Shopper tidak membuat nomor resi sendiri.')
            ->success()
            ->send();

        if ($result['print_route'] !== null) {
            $this->redirect($result['print_route']);
        }
    }

    public function refreshShipmentTracking(int $shipmentId): void
    {
        Gate::authorize('manage-order-fulfillment', $this->order);

        $shipment = OrderShipment::query()
            ->where('order_id', $this->order->id)
            ->findOrFail($shipmentId);

        if (strtolower(trim((string) $shipment->status)) === NormalizeShipmentStatus::CANCELLED) {
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

        try {
            resolve(RefreshShipmentTracking::class)->handle($shipment);
            $this->order->refresh();
            $this->dispatch('order.updated');

            Notification::make()
                ->title('Status pelacakan kurir diperbarui.')
                ->success()
                ->send();
        } catch (Throwable $e) {
            Notification::make()
                ->title('Gagal melacak pengiriman.')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function reshipShipment(int $shipmentId): void
    {
        Gate::authorize('manage-order-fulfillment', $this->order);

        $shipment = OrderShipment::query()
            ->where('order_id', $this->order->id)
            ->findOrFail($shipmentId);

        try {
            $newShipment = resolve(ReshipOrderShipment::class)->handle($shipment, auth()->user());
            $this->order->refresh();
            $this->dispatch('order.updated');

            Notification::make()
                ->title("Pengiriman ulang #{$newShipment->id} dibuat.")
                ->success()
                ->send();
        } catch (Throwable $e) {
            Notification::make()
                ->title('Gagal membuat pengiriman ulang.')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function render(): View
    {
        $view = parent::render();
        $fulfiller = $this->fulfiller();

        $shipments = OrderShipment::query()
            ->where('order_id', $this->order->id)
            ->orderBy('id')
            ->get();

        $activeShipments = $shipments->reject(
            static fn (OrderShipment $s): bool => strtolower((string) $s->status) === NormalizeShipmentStatus::CANCELLED
                || ShipmentMetadata::isSupersededByReship($s, $shipments),
        );

        $allShipmentsCancelled = $shipments->isNotEmpty() && $activeShipments->isEmpty();

        if ($fulfiller === null && ! $allShipmentsCancelled) {
            return $view;
        }

        $capabilities = resolve(ShippingCapabilities::class);
        $providerShipments = $activeShipments->filter(
            static fn (OrderShipment $s): bool => $capabilities->fulfillerFor($s) !== null,
        );
        $providerAwbs = $providerShipments
            ->map(static fn (OrderShipment $shipment): string => trim((string) ($shipment->awb ?: $shipment->tracking_number)))
            ->filter()
            ->values()
            ->all();
        $allAwbs = $activeShipments
            ->map(static fn (OrderShipment $shipment): string => trim((string) ($shipment->awb ?: $shipment->tracking_number)))
            ->filter()
            ->values()
            ->all();
        $hasUnfulfilledProviderShipment = $providerShipments->contains(
            static fn (OrderShipment $shipment): bool => trim((string) ($shipment->awb ?: $shipment->tracking_number)) === '',
        );
        $hasProviderRegisteredShipment = $providerShipments->contains(
            static fn (OrderShipment $shipment): bool => filled($shipment->external_reference),
        );

        $presenter = resolve(OrderShipmentOpsPresenter::class);
        $shipmentCards = $presenter->shipments($this->order);
        $fulfillmentErrors = $fulfiller !== null
            ? $activeShipments
                ->filter(static fn (OrderShipment $s): bool => trim((string) ($s->awb ?: $s->tracking_number)) === '')
                ->map(static fn (OrderShipment $s): ?string => ShipmentMetadata::fulfillmentError($s))
                ->filter()
                ->values()
                ->all()
            : [];

        return view('shopper.livewire.order-fulfillment', [
            ...$view->getData(),
            'providerName' => $fulfiller?->name(),
            'orderIsPaid' => OrderPaymentType::canFulfill($this->order),
            'isCodOrder' => OrderPaymentType::isCod($this->order),
            'providerAwbs' => $providerAwbs,
            'hasUnfulfilledProviderShipment' => $fulfiller !== null && $hasUnfulfilledProviderShipment,
            'hasPickupRequested' => $fulfiller !== null && $hasUnfulfilledProviderShipment && $hasProviderRegisteredShipment,
            'allShipmentsCancelled' => $allShipmentsCancelled,
            'canPrintProviderLabel' => $fulfiller !== null && $allAwbs !== [] && $presenter->printableCount($shipmentCards) > 0,
            'printProviderLabelText' => ($providerAwbs !== [] && $fulfiller !== null)
                ? "Cetak Stiker {$fulfiller->name()}"
                : 'Cetak Stiker Resi',
            'printProviderLabelRoute' => route('shopper.orders.fulfillment.print-label', $this->order),
            'shipmentCards' => $shipmentCards,
            'fulfillmentErrors' => $fulfillmentErrors,
        ]);
    }

    private function fulfiller(): ?ShippingDriver
    {
        return resolve(ShippingCapabilities::class)->fulfillerForOrder($this->order);
    }

    private function hasRegisteredShipment(): bool
    {
        $shipments = OrderShipment::query()
            ->where('order_id', $this->order->id)
            ->get();

        return $shipments->contains(static function (OrderShipment $shipment) use ($shipments): bool {
            if (strtolower(trim((string) $shipment->status)) === NormalizeShipmentStatus::CANCELLED) {
                return false;
            }

            if (ShipmentMetadata::isSupersededByReship($shipment, $shipments)) {
                return false;
            }

            return filled($shipment->external_reference);
        });
    }
}
