<?php

declare(strict_types=1);

namespace App\Livewire\Shopper;

use App\Actions\Checkout\SyncKomercePaymentStatus;
use App\Actions\Shipping\EnsureOrderShipments;
use App\Actions\Shipping\ReconcileKomerceShipment;
use App\Actions\Shipping\RefreshShipmentTracking;
use App\Actions\Shipping\SyncOrderShippingFromShipments;
use App\Actions\Warehouse\OverrideAllocation;
use App\Jobs\CreateRajaOngkirDeliveryForShipment;
use App\Models\OrderShipment;
use App\Models\User;
use App\Support\OrderShipmentOpsPresenter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Order;

final class KomerceOrderShipping extends Component
{
    #[Locked]
    public Order $order;

    public ?int $shipment_line_id = null;

    public int $qty = 1;

    public ?int $from_inventory_id = null;

    public ?int $to_inventory_id = null;

    public ?string $overrideError = null;

    public ?string $successMessage = null;

    public function mount(Order $order): void
    {
        Gate::authorize('print-shipment-label', $order);

        $this->order = $order;
        $this->ensureShipmentsExist();
        $this->seedOverrideDefaults();
    }

    public function updatedShipmentLineId(): void
    {
        $presenter = resolve(OrderShipmentOpsPresenter::class);
        foreach ($presenter->shipments($this->order) as $shipment) {
            foreach ($shipment['lines'] as $line) {
                if ((int) $line['id'] === (int) $this->shipment_line_id) {
                    $this->from_inventory_id = $shipment['inventory_id'];

                    return;
                }
            }
        }
    }

    public function applyOverride(OverrideAllocation $overrideAllocation): void
    {
        Gate::authorize('override-allocation', $this->order);
        $this->overrideError = null;
        $this->successMessage = null;

        $this->validate([
            'shipment_line_id' => ['required', 'integer'],
            'qty' => ['required', 'integer', 'min:1'],
            'from_inventory_id' => ['required', 'integer'],
            'to_inventory_id' => ['required', 'integer', 'different:from_inventory_id'],
        ]);

        $actor = auth()->user();
        if (! $actor instanceof User) {
            $this->overrideError = 'Authenticated admin user is required.';

            return;
        }

        try {
            $overrideAllocation->handle($this->order, [[
                'shipment_line_id' => $this->shipment_line_id,
                'qty' => $this->qty,
                'from_inventory_id' => $this->from_inventory_id,
                'to_inventory_id' => $this->to_inventory_id,
            ]], $actor);
            $this->successMessage = 'Stok berhasil dipindahkan ke gudang tujuan.';
        } catch (ValidationException $e) {
            $this->overrideError = collect($e->errors())->flatten()->first() ?? 'Could not move stock.';

            return;
        } catch (\Throwable $e) {
            report($e);
            $this->overrideError = $e->getMessage() ?: 'Could not move stock.';

            return;
        }

        $this->order->refresh();
        $this->seedOverrideDefaults();
        $this->dispatch('order.updated');
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

            try {
                (new CreateRajaOngkirDeliveryForShipment((int) $shipment->id))->handle();
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

        $this->seedOverrideDefaults();
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
            $this->seedOverrideDefaults();
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

        $this->seedOverrideDefaults();
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

        $result = resolve(SyncKomercePaymentStatus::class)->handle($this->order);
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
        $awb = $courier . mt_rand(100000000, 999999999);
        $orderNo = 'KOM-' . $this->order->number . '-' . $shipment->id;

        $metadata = is_array($shipment->metadata) ? $shipment->metadata : [];
        $komerce = is_array($metadata['komerce'] ?? null) ? $metadata['komerce'] : [];
        $komerce['order_no'] = $orderNo;
        $komerce['order_id'] = mt_rand(100000, 999999);
        $komerce['awb'] = $awb;

        $history = [
            [
                'description' => "[TERDAFTAR] Nomor resi {$awb} berhasil diterbitkan oleh {$courier}. Paket sedang dikemas oleh penjual.",
                'datetime' => now()->format('Y-m-d H:i:s'),
                'location' => 'Gudang Penjual (' . ($shipment->inventory?->name ?? 'Gudang Utama') . ')',
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
            'status' => \App\Actions\Shipping\NormalizeShipmentStatus::LABELED,
            'metadata' => $metadata,
        ])->save();

        $this->order->refresh();
        resolve(SyncOrderShippingFromShipments::class)->handle($this->order);
        $this->order->refresh();

        $this->seedOverrideDefaults();
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
            'location' => 'Pusat Drop Point ' . $courierName,
        ];

        $komerce = is_array($metadata['komerce'] ?? null) ? $metadata['komerce'] : [];
        $komerce['tracking_history'] = $history;
        $komerce['tracking_status'] = 'Dalam Pengiriman';
        $komerce['tracked_at'] = now()->toIso8601String();
        $metadata['komerce'] = $komerce;
        $metadata['tracking_history'] = $history;
        $metadata['last_tracking_status'] = 'IN_TRANSIT';

        $shipment->forceFill([
            'status' => \App\Actions\Shipping\NormalizeShipmentStatus::PICKED_UP,
            'metadata' => $metadata,
        ])->save();

        $this->order->refresh();
        resolve(SyncOrderShippingFromShipments::class)->handle($this->order);
        $this->order->refresh();

        $this->seedOverrideDefaults();
        $this->dispatch('order.updated');
        $this->successMessage = "Kurir berhasil men-scan jemput paket! Status pengiriman kini DIANTAR (Shipped).";
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
            'status' => \App\Actions\Shipping\NormalizeShipmentStatus::DELIVERED,
            'metadata' => $metadata,
        ])->save();

        $this->order->refresh();
        resolve(SyncOrderShippingFromShipments::class)->handle($this->order);
        $this->order->refresh();

        $this->seedOverrideDefaults();
        $this->dispatch('order.updated');
        $this->successMessage = "Kurir berhasil men-scan paket diterima! Karena pembayaran lunas & barang terkirim, status pesanan otomatis menjadi COMPLETED.";
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
        $inventories = $presenter->inventories();
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

        return view('livewire.shopper.komerce-order-shipping', [
            'order' => $this->order,
            'successMessage' => $this->successMessage,
            'overrideError' => $this->overrideError,
            'shipments' => $shipments,
            'inventories' => $inventories,
            'canSimulateCourier' => app()->environment(['local', 'testing']),
            'komerceEnabled' => komerce_shipping_delivery_enabled(),
            'canPrintAnyLabel' => $printableCount > 0,
            'printableShipmentCount' => $printableCount,
            'hasUnprocessedShipment' => $hasUnprocessed,
            'needsPickup' => $needsPickup,
            'orderIsPaid' => $this->order->payment_status === PaymentStatus::Paid,
            'hasTrackableShipment' => $hasTrackable,
            'overridableShipments' => $overridable,
            'lineOptions' => collect($overridable)->flatMap(
                static fn (array $shipment): array => collect($shipment['lines'])->map(
                    static fn (array $line): array => [
                        'id' => $line['id'],
                        'label' => $line['name'].' ×'.$line['qty'].' · '.($shipment['inventory_name'] ?? 'Warehouse'),
                        'inventory_id' => $shipment['inventory_id'],
                    ],
                )->all(),
            )->values()->all(),
        ]);
    }

    /**
     * @return Collection<int, OrderShipment>
     */
    private function ensureShipmentsExist(): Collection
    {
        return resolve(EnsureOrderShipments::class)->handle($this->order);
    }

    private function seedOverrideDefaults(): void
    {
        $presenter = resolve(OrderShipmentOpsPresenter::class);
        $shipments = $presenter->shipments($this->order);
        $overridable = collect($shipments)->firstWhere('can_override', true);
        $inventories = $presenter->inventories();

        if ($overridable === null) {
            $this->shipment_line_id = null;
            $this->from_inventory_id = null;
            $this->to_inventory_id = null;

            return;
        }

        $firstLine = $overridable['lines'][0] ?? null;
        $this->shipment_line_id = $firstLine['id'] ?? null;
        $this->from_inventory_id = $overridable['inventory_id'] ?? null;
        $this->to_inventory_id = collect($inventories)
            ->first(fn (array $inventory): bool => $inventory['id'] !== $this->from_inventory_id)['id'] ?? null;
        $this->qty = 1;
    }
}
