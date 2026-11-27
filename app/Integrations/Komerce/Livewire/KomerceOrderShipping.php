<?php

declare(strict_types=1);

namespace App\Integrations\Komerce\Livewire;

use App\Actions\Orders\SyncOrderPayment;
use App\Actions\Shipping\EnsureOrderShipments;
use App\Actions\Shipping\NormalizeShipmentStatus;
use App\Actions\Shipping\RefreshShipmentTracking;
use App\Actions\Shipping\ReshipOrderShipment;
use App\Actions\Shipping\SyncOrderShippingFromShipments;
use App\Integrations\Komerce\Actions\Shipping\ReconcileKomerceShipment;
use App\Integrations\Komerce\Komerce;
use App\Integrations\Komerce\Shipping\KomerceShippingDriver;
use App\Integrations\Komerce\Shipping\RajaOngkirDriver;
use App\Models\OrderShipment;
use App\Payment\PaymentActions;
use App\Shipping\ShipmentFulfillment;
use App\Shipping\ShipmentMetadata;
use App\Shipping\ShippingCapabilities;
use App\Support\OrderPaymentType;
use App\Support\OrderShipmentOpsPresenter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Order;
use Throwable;

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
        Gate::authorize('manage-order-fulfillment', $this->order);
        $this->overrideError = null;
        $this->successMessage = null;

        if (! OrderPaymentType::canFulfill($this->order)) {
            $this->overrideError = 'Lunasi pesanan dulu sebelum mendaftarkan pickup dan resi Komerce.';

            return;
        }

        $shipments = $this->ensureShipmentsExist();

        if ($shipmentId !== null) {
            $targetShipments = $shipments->where('id', $shipmentId);
        } else {
            $targetShipments = $shipments->reject(
                static fn (OrderShipment $shipment): bool => ShipmentMetadata::isSupersededByReship($shipment, $shipments),
            )->values();
        }

        if ($targetShipments->isEmpty()) {
            $this->overrideError = 'Tidak ada shipment pengiriman yang dapat diproses.';

            return;
        }

        $issuedCount = 0;
        $pickupPendingCount = 0;
        $skippedNoFulfiller = false;
        $skippedAlreadyLabeled = false;
        $skippedCancelled = false;
        $skippedSuperseded = false;
        $lastError = null;

        foreach ($targetShipments as $shipment) {
            if (strtolower((string) $shipment->status) === NormalizeShipmentStatus::CANCELLED) {
                $skippedCancelled = true;

                continue;
            }

            if (ShipmentMetadata::isSupersededByReship($shipment, $shipments)) {
                $skippedSuperseded = true;

                continue;
            }

            $alreadyLabeled = filled($shipment->awb) || filled($shipment->tracking_number);
            if ($alreadyLabeled) {
                $skippedAlreadyLabeled = true;

                continue;
            }

            $fulfiller = resolve(ShippingCapabilities::class)->fulfillerFor($shipment);

            if ($fulfiller === null) {
                $skippedNoFulfiller = true;

                continue;
            }

            try {
                $outcome = ShipmentFulfillment::run((int) $shipment->id, static function (OrderShipment $locked) use ($fulfiller): bool {
                    $fulfiller->fulfillShipment($locked);

                    return true;
                });

                if ($outcome === ShipmentFulfillment::BUSY) {
                    $lastError = 'Booking pengiriman sedang diproses. Muat ulang sebentar lagi.';

                    continue;
                }

                $shipment->refresh();
                if (filled($shipment->awb) || filled($shipment->tracking_number)) {
                    $issuedCount++;
                } else {
                    $pickupPendingCount++;
                }
            } catch (Throwable $e) {
                report($e);
                $lastError = $this->sanitizeProviderError($e);
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
        } elseif ($skippedNoFulfiller) {
            $this->overrideError = 'Layanan pickup otomatis Komerce Delivery tidak aktif atau tidak mencakup paket ini. Gunakan input resi manual di kartu Fulfillment.';
        } elseif ($skippedAlreadyLabeled) {
            $this->overrideError = 'Semua pengiriman aktif pada pesanan ini sudah memiliki nomor resi (AWB).';
        } elseif ($skippedCancelled) {
            $this->overrideError = 'Pengiriman pada pesanan ini sudah dibatalkan.';
        } elseif ($skippedSuperseded) {
            $this->overrideError = 'Pengiriman ini sudah digantikan oleh pengiriman ulang.';
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
        Gate::authorize('manage-order-fulfillment', $this->order);
        $this->overrideError = null;
        $this->successMessage = null;

        $allShipments = OrderShipment::query()
            ->where('order_id', $this->order->id)
            ->get();

        $shipments = $shipmentId !== null
            ? $allShipments->where('id', $shipmentId)->values()
            : $allShipments;

        if ($shipments->isEmpty()) {
            $this->overrideError = 'Belum ada shipment yang dapat dilacak.';

            return;
        }

        $shipments = $shipments->reject(
            static fn (OrderShipment $shipment): bool => strtolower((string) $shipment->status) === NormalizeShipmentStatus::CANCELLED
                || ($shipmentId === null && ShipmentMetadata::isSupersededByReship($shipment, $allShipments)),
        )->values();

        if ($shipments->isEmpty()) {
            $this->overrideError = $shipmentId === null
                ? 'Semua pengiriman pada pesanan ini telah dibatalkan.'
                : 'Pengiriman yang sudah dibatalkan tidak dapat dilacak.';

            return;
        }

        $reconcile = resolve(ReconcileKomerceShipment::class);
        $trackable = collect();
        $awaitingAwbCount = 0;
        $reconcileError = null;
        $deliveryEnabled = Komerce::shippingDeliveryEnabled();

        foreach ($shipments as $shipment) {
            if ($deliveryEnabled && filled(data_get($shipment->metadata, 'komerce.order_no'))) {
                try {
                    $shipment = $reconcile->handle($shipment);
                } catch (Throwable $e) {
                    report($e);
                    $reconcileError = 'Gagal menyelaraskan data Komerce: '.$this->sanitizeProviderError($e);
                }
            }

            if (filled($shipment->awb) || filled($shipment->tracking_number)) {
                $trackable->push($shipment);
            } elseif ($deliveryEnabled && filled(data_get($shipment->metadata, 'komerce.order_no'))) {
                $awaitingAwbCount++;
            }
        }

        if ($trackable->isEmpty()) {
            if ($reconcileError !== null) {
                $this->overrideError = $reconcileError;
            } elseif ($awaitingAwbCount > 0) {
                $this->successMessage = 'Data Komerce disinkronkan. Paket masih dalam proses packing dan nomor resi belum diterbitkan oleh kurir.';
            } else {
                $this->overrideError = 'Belum ada nomor resi AWB. Request pickup dulu, atau tunggu Komerce menerbitkan resi.';
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
            } catch (Throwable $e) {
                report($e);
                $lastError = $e->getMessage();
            }
        }

        $this->order->refresh();
        resolve(SyncOrderShippingFromShipments::class)->handle($this->order);
        $this->order->refresh();

        $this->dispatch('order.updated');

        if ($refreshedCount > 0) {
            $this->successMessage = $lastError === null
                ? 'Berhasil memperbarui status pelacakan kurir dari RajaOngkir.'
                : "Sebagian status pelacakan diperbarui ({$refreshedCount} paket), namun ada kendala pada paket lain.";
        }

        $effectiveError = $lastError ?? $reconcileError;
        if ($effectiveError !== null) {
            $this->overrideError = str_starts_with($effectiveError, 'Gagal ')
                ? $effectiveError
                : 'Gagal memperbarui status pelacakan: '.$effectiveError;
        }
    }

    public function markPaidAndProcessDelivery(): void
    {
        Gate::authorize('manage-order-fulfillment', $this->order);
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

        if (resolve(ShippingCapabilities::class)->fulfillerForOrder($this->order) !== null) {
            $shipments = $this->ensureShipmentsExist();
            $activeShipments = $shipments->reject(
                static fn (OrderShipment $shipment): bool => strtolower((string) $shipment->status) === NormalizeShipmentStatus::CANCELLED
                    || ShipmentMetadata::isSupersededByReship($shipment, $shipments),
            );
            $needsFulfillment = $activeShipments->isEmpty() || $activeShipments->contains(
                static fn (OrderShipment $shipment): bool => blank($shipment->awb) && blank($shipment->tracking_number),
            );

            if ($needsFulfillment) {
                $this->processDeliveryOrder(null);
            } else {
                resolve(SyncOrderShippingFromShipments::class)->handle($this->order);
                $this->order->refresh();
                $this->dispatch('order.updated');
                $this->dispatch('order.shipping.created');
            }

            if ($this->overrideError === null) {
                $this->successMessage = "Pembayaran selaras dengan Komerce. Pickup/resi diproses untuk #{$this->order->number}.";
            }

            return;
        }

        $this->dispatch('order.updated');
        $this->successMessage = "Pembayaran selaras dengan Komerce untuk #{$this->order->number}.";
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

        unset($komerce['fulfillment_error'], $komerce['fulfillment_failed_at'], $metadata['fulfillment_error']);
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

    public function reshipShipment(int $shipmentId): void
    {
        Gate::authorize('manage-order-fulfillment', $this->order);
        $this->overrideError = null;
        $this->successMessage = null;

        $shipment = OrderShipment::query()
            ->where('order_id', $this->order->id)
            ->findOrFail($shipmentId);

        try {
            $newShipment = resolve(ReshipOrderShipment::class)->handle($shipment, auth()->user());
            $this->order->refresh();
            $this->dispatch('order.updated');
            $this->successMessage = "Pengiriman ulang #{$newShipment->id} siap diproses.";
        } catch (Throwable $e) {
            $this->overrideError = $e->getMessage();
        }
    }

    public function render(): View
    {
        $this->ensureShipmentsExist();
        $presenter = resolve(OrderShipmentOpsPresenter::class);
        $shipments = $presenter->shipments($this->order);
        $originDriver = Komerce::shippingCostEnabled()
            ? RajaOngkirDriver::CODE
            : (Komerce::shippingDeliveryEnabled() ? KomerceShippingDriver::CODE : null);
        $inventories = $presenter->inventories($originDriver);
        $activeShipments = collect($shipments)
            ->reject(static fn (array $s): bool => strtolower((string) ($s['status'] ?? '')) === NormalizeShipmentStatus::CANCELLED
                || (bool) ($s['is_superseded'] ?? false))
            ->values();
        $printableCount = $presenter->printableCount($activeShipments->all());
        $hasUnprocessed = $activeShipments->contains(
            static fn (array $s): bool => (bool) ($s['needs_pickup'] ?? false)
                || (
                    ($s['driver'] ?? null) !== 'manual'
                    && blank($s['delivery_order_no'] ?? null)
                    && blank($s['awb'] ?? null)
                    && blank($s['tracking_number'] ?? null)
                ),
        );
        $needsPickup = $activeShipments->contains(static fn (array $s): bool => (bool) ($s['needs_pickup'] ?? false));
        $hasTrackable = $activeShipments->contains(
            static fn (array $s): bool => filled($s['awb']) || filled($s['tracking_number']) || filled($s['delivery_order_no']),
        );
        $allHaveAwb = $activeShipments->isNotEmpty() && $activeShipments->every(
            static fn (array $s): bool => filled($s['awb']) || filled($s['tracking_number']),
        );
        $anyHasAwb = $activeShipments->contains(
            static fn (array $s): bool => filled($s['awb']) || filled($s['tracking_number']),
        );
        $allRegistered = $activeShipments->isNotEmpty() && $activeShipments->every(
            static fn (array $s): bool => filled($s['delivery_order_no'] ?? null),
        );
        $anyRegistered = $activeShipments->contains(
            static fn (array $s): bool => filled($s['delivery_order_no'] ?? null),
        );
        $allCancelled = $shipments !== [] && $activeShipments->isEmpty();
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
            'shippingCostEnabled' => Komerce::shippingCostEnabled(),
            'canTrackShipping' => Komerce::shippingEnabled(),
            'canPrintAnyLabel' => $printableCount > 0,
            'printableShipmentCount' => $printableCount,
            'hasUnprocessedShipment' => $hasUnprocessed,
            'needsPickup' => $needsPickup,
            'allHaveAwb' => $allHaveAwb,
            'anyHasAwb' => $anyHasAwb,
            'allRegistered' => $allRegistered,
            'anyRegistered' => $anyRegistered,
            'allCancelled' => $allCancelled,
            'orderIsPaid' => OrderPaymentType::canFulfill($this->order),
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

    private function sanitizeProviderError(Throwable $e): string
    {
        $previous = $e->getPrevious();
        $status = null;

        if ($e instanceof RequestException) {
            $status = $e->response->status();
        } elseif ($previous instanceof RequestException) {
            $status = $previous->response->status();
        }

        $message = $e->getMessage();

        if ($status === null && preg_match('/HTTP request returned status code (\d{3})/i', $message, $matches) === 1) {
            $status = (int) $matches[1];
        }

        if ($status !== null) {
            return match (true) {
                in_array($status, [401, 403], true) => 'Akses layanan Komerce tidak aktif atau ditolak. Periksa konfigurasi API key.',
                $status === 429 => 'Batas permintaan layanan Komerce sedang tercapai. Silakan coba lagi beberapa saat lagi.',
                $status >= 500 => 'Layanan Komerce sedang mengalami gangguan. Silakan coba lagi sebentar.',
                default => "Gagal menghubungi layanan Komerce (kode {$status}).",
            };
        }

        if ($e instanceof ConnectionException || $previous instanceof ConnectionException) {
            return 'Koneksi ke layanan Komerce terputus. Silakan coba lagi sebentar.';
        }

        if (str_contains($message, 'HTTP request returned status code') || str_contains($message, '{"')) {
            return 'Terjadi kendala saat menghubungi layanan Komerce. Silakan coba lagi sebentar.';
        }

        return $message;
    }
}
