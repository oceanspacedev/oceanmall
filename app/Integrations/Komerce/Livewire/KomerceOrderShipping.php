<?php

declare(strict_types=1);

namespace App\Integrations\Komerce\Livewire;

use App\Actions\Orders\SyncOrderPayment;
use App\Actions\Shipping\EnsureOrderShipments;
use App\Actions\Shipping\NormalizeShipmentStatus;
use App\Actions\Shipping\RefreshShipmentTracking;
use App\Actions\Shipping\SyncOrderShippingFromShipments;
use App\Integrations\Komerce\Actions\Shipping\ReconcileKomerceShipment;
use App\Integrations\Komerce\Komerce;
use App\Integrations\Komerce\Shipping\RajaOngkirDriver;
use App\Models\OrderShipment;
use App\Payment\PaymentActions;
use App\Shipping\ShippingCapabilities;
use App\Support\OrderShipmentOpsPresenter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Order;

final class KomerceOrderShipping extends Component
{
    #[Locked]
    public Order $order;

    public ?string $overrideError = null;

    public ?string $successMessage = null;

    public function mount(Order $order): void
    {
        Gate::authorize('print-shipment-label', $order);

        $this->order = $order;
        $this->ensureShipmentsExist();
    }

    public function processDeliveryOrder(?int $shipmentId = null): void
    {
        Gate::authorize('print-shipment-label', $this->order);
        $this->overrideError = null;
        $this->successMessage = null;

        if ($this->order->payment_status !== PaymentStatus::Paid) {
            $this->overrideError = 'Lunasi pesanan dulu sebelum mendaftarkan pickup dan resi Komerce.';

            return;
        }

        $shipments = $this->ensureShipmentsExist();

        if ($shipmentId !== null) {
            $targetShipments = $shipments->where('id', $shipmentId);
        } else {
            $targetShipments = $shipments;
        }

        if ($targetShipments->isEmpty()) {
            $this->overrideError = 'Tidak ada shipment pengiriman yang dapat diproses.';

            return;
        }

        $issuedCount = 0;
        $pickupPendingCount = 0;
        $lastError = null;

        foreach ($targetShipments as $shipment) {
            $alreadyLabeled = filled($shipment->awb) || filled($shipment->tracking_number);
            if ($alreadyLabeled) {
                continue;
            }

            $fulfiller = resolve(ShippingCapabilities::class)->fulfillerFor($shipment);

            if ($fulfiller === null) {
                continue;
            }

            try {
                $fulfiller->fulfillShipment($shipment);
                $shipment->refresh();
                if (filled($shipment->awb) || filled($shipment->tracking_number)) {
                    $issuedCount++;
                } else {
                    $pickupPendingCount++;
                }
            } catch (\Throwable $e) {
                report($e);
                $lastError = $e->getMessage();
            }
        }

        $this->order->refresh();
        resolve(SyncOrderShippingFromShipments::class)->handle($this->order);
        $this->order->refresh();

        $this->dispatch('order.updated');
        $this->dispatch('order.shipping.created');

        if ($issuedCount > 0) {
            $this->successMessage = "Nomor resi Komerce terbit untuk pesanan #{$this->order->number}.";
        } elseif ($pickupPendingCount > 0) {
            $this->successMessage = 'Pickup Komerce diminta. Nomor resi muncul setelah kurir memproses paket.';
        } elseif ($lastError !== null) {
            $this->overrideError = 'Gagal pickup/resi Komerce: '.$lastError;
        }
    }

    public function processAllDeliveryOrders(): void
    {
        $this->processDeliveryOrder(null);
    }

    public function requestPickup(?int $shipmentId = null): void
    {
        $this->processDeliveryOrder($shipmentId);
    }

    public function refreshTracking(?int $shipmentId = null): void
    {
        Gate::authorize('print-shipment-label', $this->order);
        $this->overrideError = null;
        $this->successMessage = null;

        $shipments = OrderShipment::query()
            ->where('order_id', $this->order->id)
            ->when($shipmentId !== null, fn ($q) => $q->where('id', $shipmentId))
            ->get();

        if ($shipments->isEmpty()) {
            $this->overrideError = 'Belum ada shipment yang dapat dilacak.';

            return;
        }

        $reconcile = resolve(ReconcileKomerceShipment::class);
        $trackable = collect();
        $awaitingAwbCount = 0;

        foreach ($shipments as $shipment) {
            if (filled(data_get($shipment->metadata, 'komerce.order_no'))) {
                try {
                    $shipment = $reconcile->handle($shipment);
                } catch (\Throwable $e) {
                    report($e);
                    $this->overrideError = 'Gagal menyelaraskan data Komerce: '.$e->getMessage();
                }
            }

            if (filled($shipment->awb) || filled($shipment->tracking_number)) {
                $trackable->push($shipment);
            } elseif (filled(data_get($shipment->metadata, 'komerce.order_no'))) {
                $awaitingAwbCount++;
            }
        }

        if ($trackable->isEmpty()) {
            if ($awaitingAwbCount > 0) {
                $this->successMessage = 'Data Komerce disinkronkan. Paket masih dalam proses packing dan nomor resi belum diterbitkan oleh kurir.';
            } else {
                $this->overrideError = $this->overrideError
                    ?? 'Belum ada nomor resi AWB. Request pickup dulu, atau tunggu Komerce menerbitkan resi.';
            }

            $this->order->refresh();
            $this->dispatch('order.updated');

            return;
        }

        $shipments = $trackable;
        $refresher = resolve(RefreshShipmentTracking::class);
        $refreshedCount = 0;
        $lastError = null;

        foreach ($shipments as $shipment) {
            try {
                $refresher->handle($shipment);
                $refreshedCount++;
            } catch (\Throwable $e) {
                report($e);
                $lastError = $e->getMessage();
            }
        }

        $this->order->refresh();
        resolve(SyncOrderShippingFromShipments::class)->handle($this->order);
        $this->order->refresh();

        $this->dispatch('order.updated');

        if ($refreshedCount > 0) {
            $this->successMessage = 'Berhasil memperbarui status pelacakan kurir dari RajaOngkir.';
        } elseif ($lastError !== null) {
            $this->overrideError = 'Gagal memperbarui status pelacakan: '.$lastError;
        }
    }

    public function markPaidAndProcessDelivery(): void
    {
        Gate::authorize('print-shipment-label', $this->order);
        $this->overrideError = null;
        $this->successMessage = null;

        $result = resolve(SyncOrderPayment::class)->handle($this->order);
        $this->order->refresh();

        if ($this->order->payment_status !== PaymentStatus::Paid) {
            $this->overrideError = match ($result) {
                'no_payment' => 'Tidak ada referensi pembayaran Komerce. Jangan tandai lunas manual.',
                default => 'Komerce belum menandai pembayaran lunas. Status toko tidak diubah.',
            };

            return;
        }

        $this->processDeliveryOrder(null);
        if ($this->overrideError === null) {
            $this->successMessage = "Pembayaran selaras dengan Komerce. Pickup/resi diproses untuk #{$this->order->number}.";
        }
    }

    public function simulateGenerateAwb(int $shipmentId): void
    {
        $this->ensureCourierSimulationAllowed();
        Gate::authorize('print-shipment-label', $this->order);
        $this->overrideError = null;
        $this->successMessage = null;

        $shipment = OrderShipment::query()
            ->where('order_id', $this->order->id)
            ->where('id', $shipmentId)
            ->first();

        if ($shipment === null) {
            $this->overrideError = 'Shipment tidak ditemukan.';

            return;
        }

        $courier = strtoupper((string) ($shipment->carrier_code ?: 'JNT'));
        $awb = $courier.mt_rand(100000000, 999999999);
        $orderNo = 'KOM-'.$this->order->number.'-'.$shipment->id;

        $metadata = is_array($shipment->metadata) ? $shipment->metadata : [];
        $komerce = is_array($metadata['komerce'] ?? null) ? $metadata['komerce'] : [];

        // Never overwrite a real Komerce order_no/order_id: the shipment must
        // keep pointing at the provider record so reconcile/cancel still match.
        if (! is_scalar($komerce['order_no'] ?? null) || trim((string) $komerce['order_no']) === '') {
            $komerce['order_no'] = $orderNo;
            $komerce['order_id'] = mt_rand(100000, 999999);
        }
        $komerce['awb'] = $awb;

        $history = [
            [
                'description' => "[TERDAFTAR] Nomor resi {$awb} berhasil diterbitkan oleh {$courier}. Paket sedang dikemas oleh penjual.",
                'datetime' => now()->format('Y-m-d H:i:s'),
                'location' => 'Gudang Penjual ('.($shipment->inventory?->name ?? 'Gudang Utama').')',
            ],
        ];

        $komerce['tracking_history'] = $history;
        $komerce['tracking_status'] = 'Resi Terbit';
        $komerce['tracked_at'] = now()->toIso8601String();
        $metadata['komerce'] = $komerce;
        $metadata['tracking_history'] = $history;

        $shipment->forceFill([
            'awb' => $awb,
            'tracking_number' => $awb,
            'status' => NormalizeShipmentStatus::LABELED,
            'metadata' => $metadata,
        ])->save();

        $this->order->refresh();
        resolve(SyncOrderShippingFromShipments::class)->handle($this->order);
        $this->order->refresh();

        $this->dispatch('order.updated');
        $this->successMessage = "Nomor resi simulasi berhasil diterbitkan: {$awb}";
    }

    public function simulateScanPickup(int $shipmentId): void
    {
        $this->ensureCourierSimulationAllowed();
        Gate::authorize('print-shipment-label', $this->order);
        $this->overrideError = null;
        $this->successMessage = null;

        $shipment = OrderShipment::query()
            ->where('order_id', $this->order->id)
            ->where('id', $shipmentId)
            ->first();

        if ($shipment === null) {
            $this->overrideError = 'Shipment tidak ditemukan.';

            return;
        }

        $metadata = is_array($shipment->metadata) ? $shipment->metadata : [];
        $rawHistory = data_get($metadata, 'komerce.tracking_history')
            ?: data_get($metadata, 'tracking_history', []);
        $history = is_array($rawHistory) ? $rawHistory : [];

        $courierName = strtoupper((string) ($shipment->carrier_code ?: 'Kurir'));
        $history[] = [
            'description' => "[PICKED_UP] Paket telah diserahkan oleh penjual ke kurir {$courierName}. Sedang dalam perjalanan ke alamat tujuan.",
            'datetime' => now()->format('Y-m-d H:i:s'),
            'location' => 'Pusat Drop Point '.$courierName,
        ];

        $komerce = is_array($metadata['komerce'] ?? null) ? $metadata['komerce'] : [];
        $komerce['tracking_history'] = $history;
        $komerce['tracking_status'] = 'Dalam Pengiriman';
        $komerce['tracked_at'] = now()->toIso8601String();
        $metadata['komerce'] = $komerce;
        $metadata['tracking_history'] = $history;
        $metadata['last_tracking_status'] = 'IN_TRANSIT';

        $shipment->forceFill([
            'status' => NormalizeShipmentStatus::PICKED_UP,
            'metadata' => $metadata,
        ])->save();

        $this->order->refresh();
        resolve(SyncOrderShippingFromShipments::class)->handle($this->order);
        $this->order->refresh();

        $this->dispatch('order.updated');
        $this->successMessage = 'Kurir berhasil men-scan jemput paket! Status pengiriman kini DIANTAR (Shipped).';
    }

    public function simulateScanDelivered(int $shipmentId): void
    {
        $this->ensureCourierSimulationAllowed();
        Gate::authorize('print-shipment-label', $this->order);
        $this->overrideError = null;
        $this->successMessage = null;

        $shipment = OrderShipment::query()
            ->where('order_id', $this->order->id)
            ->where('id', $shipmentId)
            ->first();

        if ($shipment === null) {
            $this->overrideError = 'Shipment tidak ditemukan.';

            return;
        }

        $metadata = is_array($shipment->metadata) ? $shipment->metadata : [];
        $rawHistory = data_get($metadata, 'komerce.tracking_history')
            ?: data_get($metadata, 'tracking_history', []);
        $history = is_array($rawHistory) ? $rawHistory : [];

        $destCity = $this->order->shippingAddress?->city ?? 'Kota Tujuan';
        $history[] = [
            'description' => '[DELIVERED] Paket telah sampai di alamat tujuan dan diterima oleh penerima.',
            'datetime' => now()->format('Y-m-d H:i:s'),
            'location' => $destCity,
        ];

        $komerce = is_array($metadata['komerce'] ?? null) ? $metadata['komerce'] : [];
        $komerce['tracking_history'] = $history;
        $komerce['tracking_status'] = 'Terkirim';
        $komerce['tracked_at'] = now()->toIso8601String();
        $metadata['komerce'] = $komerce;
        $metadata['tracking_history'] = $history;
        $metadata['last_tracking_status'] = 'DELIVERED';

        $shipment->forceFill([
            'status' => NormalizeShipmentStatus::DELIVERED,
            'metadata' => $metadata,
        ])->save();

        $this->order->refresh();
        resolve(SyncOrderShippingFromShipments::class)->handle($this->order);
        $this->order->refresh();

        $this->dispatch('order.updated');
        $this->successMessage = 'Kurir berhasil men-scan paket diterima! Karena pembayaran lunas & barang terkirim, status pesanan otomatis menjadi COMPLETED.';
    }

    private function ensureCourierSimulationAllowed(): void
    {
        abort_unless(app()->environment(['local', 'testing']), 403);
    }

    public function render(): View
    {
        $this->ensureShipmentsExist();
        $presenter = resolve(OrderShipmentOpsPresenter::class);
        $shipments = $presenter->shipments($this->order);
        $inventories = $presenter->inventories(RajaOngkirDriver::CODE);
        $printableCount = $presenter->printableCount($shipments);
        $hasUnprocessed = collect($shipments)->contains(
            fn (array $s): bool => (bool) ($s['needs_pickup'] ?? false) || blank($s['delivery_order_no'] ?? null),
        );
        $needsPickup = collect($shipments)->contains(fn (array $s): bool => (bool) ($s['needs_pickup'] ?? false));
        $hasTrackable = collect($shipments)->contains(
            fn (array $s): bool => filled($s['awb']) || filled($s['tracking_number']) || filled($s['delivery_order_no']),
        );
        $overridable = array_values(array_filter(
            $shipments,
            static fn (array $shipment): bool => (bool) $shipment['can_override'],
        ));
        $payments = resolve(PaymentActions::class);

        return view('komerce::livewire.order-shipping', [
            'order' => $this->order,
            'successMessage' => $this->successMessage,
            'overrideError' => $this->overrideError,
            'shipments' => $shipments,
            'inventories' => $inventories,
            'canSimulateCourier' => app()->environment(['local', 'testing']),
            'komerceEnabled' => Komerce::shippingDeliveryEnabled(),
            'canPrintAnyLabel' => $printableCount > 0,
            'printableShipmentCount' => $printableCount,
            'hasUnprocessedShipment' => $hasUnprocessed,
            'needsPickup' => $needsPickup,
            'orderIsPaid' => $this->order->payment_status === PaymentStatus::Paid,
            'hasTrackableShipment' => $hasTrackable,
            'overridableShipments' => $overridable,
            // Only an order paid through a provider that reconciles can be
            // "synced"; manual / COD orders use Shopper's own mark-paid.
            'canSyncPayment' => $this->order->payment_status !== PaymentStatus::Paid
                && $payments->reconciler($this->order) !== null,
        ]);
    }

    /**
     * @return Collection<int, OrderShipment>
     */
    private function ensureShipmentsExist(): Collection
    {
        return resolve(EnsureOrderShipments::class)->handle($this->order);
    }
}
