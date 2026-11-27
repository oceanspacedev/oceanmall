<?php

declare(strict_types=1);

namespace App\Livewire\Shopper\Pages;

use App\Actions\Orders\CancelOrderWithProviders;
use App\Actions\Orders\MarkOrderPaid;
use App\Actions\Orders\RemitCodOrder;
use App\Actions\Orders\SyncOrderPayment;
use App\Actions\Shipping\NormalizeShipmentStatus;
use App\Models\OrderNote;
use App\Models\OrderShipment;
use App\Models\User;
use App\Payment\Contracts\ExpiresPayments;
use App\Payment\PaymentActions;
use App\Shipping\ShipmentMetadata;
use App\Shipping\ShippingCapabilities;
use App\Support\OrderPaymentType;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Shopper\Core\Enum\OrderRefundStatus;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Events\Orders\OrderCompleted;
use Shopper\Core\Models\OrderRefund;
use Shopper\Core\Models\OrderShipping;
use Shopper\Livewire\Pages\Order\Detail as ShopperOrderDetail;
use Throwable;

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
    public function startProcessingAction(): Action
    {
        return parent::startProcessingAction()
            ->authorize('manage-order-fulfillment');
    }

    public function markPaidAction(): Action
    {
        $action = parent::markPaidAction()->authorize('orders-mark-paid');
        $reconciler = resolve(PaymentActions::class)->reconciler($this->order);

        if ($reconciler === null) {
            if (OrderPaymentType::isCod($this->order)) {
                return $action
                    ->label('Konfirmasi Dana COD Diterima')
                    ->requiresConfirmation()
                    ->schema([
                        TextInput::make('reference')
                            ->label('Referensi Setoran COD')
                            ->default('cod-remit-'.$this->order->number),
                        Textarea::make('notes')
                            ->label('Catatan Verifikasi')
                            ->rows(2),
                    ])
                    ->action(function (array $data): void {
                        $actor = Auth::user();
                        if ($actor instanceof User) {
                            resolve(RemitCodOrder::class)->handle(
                                $this->order,
                                $actor,
                                $data['reference'] ?? null,
                                $data['notes'] ?? null,
                            );
                            $this->order->refresh();
                            $this->dispatch('order.updated');
                        }

                        Notification::make()
                            ->title('Dana COD dikonfirmasi lunas.')
                            ->success()
                            ->send();
                    });
            }

            return $action
                ->requiresConfirmation()
                ->schema([
                    TextInput::make('verified_amount')
                        ->label('Nominal Diterima')
                        ->numeric()
                        ->default((int) $this->order->price_amount),
                    TextInput::make('bank_reference')
                        ->label('Nomor Referensi Mutasi')
                        ->default('manual-'.$this->order->number),
                    Textarea::make('notes')
                        ->label('Catatan Verifikasi Finance')
                        ->rows(2),
                ])
                ->action(function (array $data): void {
                    $actor = Auth::user();
                    $amount = isset($data['verified_amount']) && is_numeric($data['verified_amount'])
                        ? (int) $data['verified_amount']
                        : (int) $this->order->price_amount;
                    $reference = filled($data['bank_reference'] ?? null)
                        ? trim((string) $data['bank_reference'])
                        : ('manual-'.$this->order->number);

                    resolve(MarkOrderPaid::class)->handle(
                        order: $this->order,
                        driver: 'manual',
                        reference: $reference,
                        confirmedAmount: $amount,
                        transactionMetadata: array_filter([
                            'verified_by' => $actor?->id,
                            'notes' => $data['notes'] ?? null,
                        ], static fn (mixed $v): bool => $v !== null && $v !== ''),
                    );

                    OrderNote::query()->create([
                        'order_id' => $this->order->id,
                        'user_id' => $actor?->id,
                        'type' => OrderNote::TYPE_AUDIT,
                        'body' => sprintf('Pembayaran manual dikonfirmasi lunas (Ref: %s).', $reference),
                    ]);

                    $this->order->refresh();
                    $this->dispatch('order.updated');

                    Notification::make()
                        ->title(__('shopper::pages/orders.notifications.paid'))
                        ->success()
                        ->send();
                });
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
        $action = parent::markCompleteAction()->authorize('manage-order-fulfillment');

        if (! $this->hasProviderShipments()) {
            return $action->visible(
                fn (): bool => $this->order->isProcessing()
                    && $this->order->isPaid()
                    && ! $this->hasOnlyCancelledShipmentsWithoutManualLabel(),
            );
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

        return parent::cancelOrderAction()
            ->authorize('orders-cancel')
            ->requiresConfirmation()
            ->schema([
                TextInput::make('reason')
                    ->label('Alasan Pembatalan')
                    ->default('Cancelled by staff'),
                Checkbox::make('create_refund_ticket')
                    ->label('Buat tiket refund untuk pesanan sudah dibayar')
                    ->default($this->order->payment_status === PaymentStatus::Paid),
            ])
            ->action(function (array $data) use ($withProviders): void {
                $wasPaid = $this->order->payment_status === PaymentStatus::Paid;
                $reason = filled($data['reason'] ?? null)
                    ? trim((string) $data['reason'])
                    : 'Cancelled by staff';
                $actor = Auth::user() instanceof User ? Auth::user() : null;

                try {
                    resolve(CancelOrderWithProviders::class)->handle(
                        order: $this->order,
                        throughPaymentProvider: true,
                        reason: $reason,
                        actor: $actor,
                    );
                } catch (Throwable $e) {
                    report($e);

                    Notification::make()
                        ->title('Gagal membatalkan pesanan.')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                if ($wasPaid && ($data['create_refund_ticket'] ?? false)) {
                    OrderRefund::query()->firstOrCreate(
                        [
                            'order_id' => $this->order->id,
                            'status' => OrderRefundStatus::Awaiting,
                        ],
                        [
                            'user_id' => $this->order->customer_id,
                            'amount' => (int) $this->order->price_amount,
                            'currency' => $this->order->currency_code,
                            'reason' => 'Tiket refund otomatis pembatalan: '.$reason,
                            'refunded_by' => $actor?->id,
                        ],
                    );
                }

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
        $action = parent::capturePaymentAction()->authorize('orders-mark-paid');

        // The provider captures; Shopper must not record a second capture.
        if (resolve(PaymentActions::class)->reconciler($this->order) !== null) {
            return $action->visible(false);
        }

        return $action;
    }

    public function archiveAction(): Action
    {
        $action = parent::archiveAction()->authorize('orders-cancel');

        if (! $this->cancelsWithProviders()) {
            // Archiving ends an unpaid order for good: while it could still be
            // cancelled and nothing left the warehouse, cancel it through the
            // same path as a staff cancel first (stock back once, payment
            // voided, shipments closed, customer told), then archive it. A
            // shipped or delivered order (e.g. COD cash not yet marked paid)
            // is only archived, as Shopper does.
            return $action->before(function () use ($action): void {
                $cancel = resolve(CancelOrderWithProviders::class);

                if ($this->order->status !== OrderStatus::Cancelled
                    && $this->order->canBeCancelled()
                    && ! $cancel->hasShipped($this->order)) {
                    try {
                        $cancel->handle($this->order);
                    } catch (Throwable $e) {
                        report($e);

                        Notification::make()
                            ->title('Gagal membatalkan pesanan.')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        $action->halt();
                    }
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
        $allShipments = OrderShipment::query()
            ->where('order_id', $this->order->id)
            ->get();

        $shipments = $allShipments->reject(
            static fn (OrderShipment $shipment): bool => strtolower(trim((string) $shipment->status)) === NormalizeShipmentStatus::CANCELLED
                || ShipmentMetadata::isSupersededByReship($shipment, $allShipments),
        );

        if ($shipments->isEmpty()) {
            return false;
        }

        return $shipments->every(
            static fn (OrderShipment $shipment): bool => strtolower(trim((string) $shipment->status)) === NormalizeShipmentStatus::DELIVERED,
        );
    }

    private function hasOnlyCancelledShipmentsWithoutManualLabel(): bool
    {
        $shipments = OrderShipment::query()
            ->where('order_id', $this->order->id)
            ->get();

        if ($shipments->isEmpty()) {
            return false;
        }

        $hasActiveShipment = $shipments->contains(
            static fn (OrderShipment $shipment): bool => strtolower(trim((string) $shipment->status)) !== NormalizeShipmentStatus::CANCELLED,
        );

        if ($hasActiveShipment) {
            return false;
        }

        $boundShippingIds = $this->order->items()
            ->whereNotNull('order_shipping_id')
            ->pluck('order_shipping_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($boundShippingIds === []) {
            return true;
        }

        $cancelledAwbs = $shipments
            ->flatMap(static fn (OrderShipment $shipment): array => [
                strtolower(trim((string) $shipment->awb)),
                strtolower(trim((string) $shipment->tracking_number)),
            ])
            ->filter(static fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();

        $hasGenuineRecoveryLabel = OrderShipping::query()
            ->where('order_id', $this->order->id)
            ->whereIn('id', $boundShippingIds)
            ->get()
            ->contains(static function (OrderShipping $shipping) use ($cancelledAwbs): bool {
                $tracking = strtolower(trim((string) $shipping->tracking_number));

                return $tracking !== '' && ! in_array($tracking, $cancelledAwbs, true);
            });

        return ! $hasGenuineRecoveryLabel;
    }
}
