<?php

declare(strict_types=1);

namespace App\Livewire\Shopper\Pages;

use App\Actions\Orders\CancelOrderWithProviders;
use App\Actions\Orders\SyncOrderPayment;
use App\Actions\Shipping\NormalizeShipmentStatus;
use App\Models\OrderShipment;
use App\Payment\Contracts\ExpiresPayments;
use App\Payment\PaymentActions;
use App\Shipping\ShippingCapabilities;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Events\Orders\OrderCompleted;
use Shopper\Livewire\Pages\Order\Detail as ShopperOrderDetail;

/**
 * Shopper's order detail, deciding per order: an order whose payment driver
 * reconciles with its provider (App\Payment\Contracts\ReconcilesPayments) is
 * marked paid by asking that provider, and an order whose shipments are
 * fulfilled by a provider (FulfillsShipments) completes when the provider
 * reports delivery. Manual / COD orders keep Shopper's own actions even
 * while a provider is configured for other orders, except cancel / archive:
 * every order is cancelled through CancelOrderWithProviders.
 */
final class OrderDetail extends ShopperOrderDetail
{
    public function markPaidAction(): Action
    {
        $action = parent::markPaidAction();
        $reconciler = resolve(PaymentActions::class)->reconciler($this->order);

        if ($reconciler === null) {
            return $action;
        }

        $provider = $this->paymentProviderName();

        return $action
            ->label("Sinkronkan pembayaran {$provider}")
            ->action(function () use ($provider): void {
                $result = resolve(SyncOrderPayment::class)->handle($this->order);
                $this->order->refresh();
                $this->dispatch('order.updated');

                match ($result) {
                    SyncOrderPayment::HANDLED, SyncOrderPayment::ALREADY_PAID => Notification::make()
                        ->title("Pembayaran diselaraskan dari {$provider}.")
                        ->success()
                        ->send(),
                    SyncOrderPayment::NO_PAYMENT => Notification::make()
                        ->title("Tidak ada referensi pembayaran {$provider}.")
                        ->body("Jangan tandai lunas manual. Tunggu pembayaran atau webhook {$provider}.")
                        ->danger()
                        ->send(),
                    default => Notification::make()
                        ->title("{$provider} belum menandai pembayaran lunas.")
                        ->body("Status toko tidak diubah. Cek ulang di {$provider}.")
                        ->warning()
                        ->send(),
                };
            });
    }

    public function markCompleteAction(): Action
    {
        $action = parent::markCompleteAction();

        if (! $this->hasProviderShipments()) {
            return $action;
        }

        return $action
            ->visible(fn (): bool => $this->order->isProcessing()
                && $this->order->isPaid()
                && $this->allShipmentsDelivered())
            ->action(function (): void {
                if (! $this->allShipmentsDelivered()) {
                    Notification::make()
                        ->title('Pesanan belum selesai di penyedia pengiriman.')
                        ->body('Selesai hanya setelah resi berstatus delivered / customer konfirmasi diterima.')
                        ->danger()
                        ->send();

                    return;
                }

                $this->order->update(['status' => OrderStatus::Completed]);
                $this->order->refresh();
                $this->dispatch('order.updated');
                event(new OrderCompleted($this->order));

                Notification::make()
                    ->title('Pesanan diselesaikan setelah kurir menandai terkirim.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Every staff cancel, manual / COD included, goes through the
     * provider-neutral CancelOrderWithProviders: it voids an unpaid payment,
     * releases the reserved stock once, closes shipments, notifies the
     * customer and fires OrderCancelled. Shopper's own action only flips
     * the status, so its label, visibility and authorization are kept.
     */
    public function cancelOrderAction(): Action
    {
        $withProviders = $this->cancelsWithProviders();

        return parent::cancelOrderAction()->action(function () use ($withProviders): void {
            resolve(CancelOrderWithProviders::class)->handle($this->order);
            $this->order->refresh();
            $this->dispatch('order.updated');

            Notification::make()
                ->title($withProviders
                    ? 'Pesanan dibatalkan di toko dan di penyedia pembayaran/pengiriman.'
                    : __('shopper::pages/orders.notifications.cancelled'))
                ->success()
                ->send();
        });
    }

    public function capturePaymentAction(): Action
    {
        $action = parent::capturePaymentAction();

        // The provider captures; Shopper must not record a second capture.
        if (resolve(PaymentActions::class)->reconciler($this->order) !== null) {
            return $action->visible(false);
        }

        return $action;
    }

    public function archiveAction(): Action
    {
        $action = parent::archiveAction();

        if (! $this->cancelsWithProviders()) {
            // Archiving ends an unpaid order for good: while it could still be
            // cancelled and nothing left the warehouse, cancel it through the
            // same path as a staff cancel first (stock back once, payment
            // voided, shipments closed, customer told), then archive it. A
            // shipped or delivered order (e.g. COD cash not yet marked paid)
            // is only archived, as Shopper does.
            return $action->before(function (): void {
                $cancel = resolve(CancelOrderWithProviders::class);

                if ($this->order->status !== OrderStatus::Cancelled
                    && $this->order->canBeCancelled()
                    && ! $cancel->hasShipped($this->order)) {
                    $cancel->handle($this->order);
                }

                $this->order->refresh();
            });
        }

        return $action
            ->visible(false)
            ->action(function (): void {
                Notification::make()
                    ->title('Jangan arsip lewat Shopper.')
                    ->body('Batalkan pesanan agar pembayaran/pengiriman di penyedia ikut ditutup.')
                    ->danger()
                    ->send();
            });
    }

    private function paymentProviderName(): string
    {
        return resolve(PaymentActions::class)->driver($this->order)?->name() ?? 'penyedia pembayaran';
    }

    private function hasProviderShipments(): bool
    {
        return resolve(ShippingCapabilities::class)->fulfillerForOrder($this->order) !== null;
    }

    /**
     * Cancelling must also close something at a provider: the unpaid
     * order's pending payment, or the paid order's provider shipments.
     */
    private function cancelsWithProviders(): bool
    {
        if ($this->order->payment_status !== PaymentStatus::Paid) {
            $driver = resolve(PaymentActions::class)->driver($this->order);

            if ($driver instanceof ExpiresPayments && resolve(PaymentActions::class)->reconciler($this->order) !== null) {
                return true;
            }
        }

        return $this->hasProviderShipments();
    }

    private function allShipmentsDelivered(): bool
    {
        $shipments = OrderShipment::query()->where('order_id', $this->order->id)->get();

        if ($shipments->isEmpty()) {
            return false;
        }

        return $shipments->every(
            static fn (OrderShipment $shipment): bool => strtolower((string) $shipment->status) === NormalizeShipmentStatus::DELIVERED,
        );
    }
}
