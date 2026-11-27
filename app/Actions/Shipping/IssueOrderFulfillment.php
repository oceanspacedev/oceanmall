<?php

declare(strict_types=1);

namespace App\Actions\Shipping;

use App\Models\OrderShipment;
use App\Shipping\Contracts\PrintsLabels;
use App\Shipping\Contracts\ReconcilesShipments;
use App\Shipping\ShipmentFulfillment;
use App\Shipping\ShipmentMetadata;
use App\Shipping\ShippingCapabilities;
use App\Support\OrderPaymentType;
use RuntimeException;
use Shopper\Core\Models\Order;
use Throwable;

/**
 * Staff "issue AWB" for a paid order whose shipments a provider fulfils:
 * each unlabelled shipment goes to its own driver (FulfillsShipments), after
 * re-reading the provider state when the shipment is already registered
 * there (ReconcilesShipments). The provider issues the AWB; Shopper only
 * records it (SyncOrderShippingFromShipments).
 */
final readonly class IssueOrderFulfillment
{
    public function __construct(private ShippingCapabilities $capabilities) {}

    /**
     * @return array{awbs: list<string>, print_route: string|null, errors: list<string>}
     *
     * @throws RuntimeException when no AWB could be issued (the message says why)
     */
    public function handle(Order $order): array
    {
        if (! OrderPaymentType::canFulfill($order)) {
            throw new RuntimeException('Terbitkan resi setelah pembayaran lunas.');
        }

        $shipments = resolve(EnsureOrderShipments::class)->handle($order);

        if ($shipments->isEmpty()) {
            throw new RuntimeException('Pesanan ini belum punya alokasi gudang untuk pengiriman.');
        }

        $activeShipments = $shipments->reject(
            static fn (OrderShipment $shipment): bool => strtolower(trim((string) $shipment->status)) === NormalizeShipmentStatus::CANCELLED
                || ShipmentMetadata::isSupersededByReship($shipment, $shipments),
        )->values();

        if ($activeShipments->isEmpty()) {
            throw new RuntimeException('Semua pengiriman pada pesanan ini sudah dibatalkan.');
        }

        $errors = [];
        $printable = false;

        foreach ($activeShipments as $shipment) {
            $driver = $this->capabilities->fulfillerFor($shipment);

            if ($driver === null) {
                continue;
            }

            $printable = $printable || $driver instanceof PrintsLabels;

            if ($this->labelled($shipment)) {
                continue;
            }

            if ($driver instanceof ReconcilesShipments && filled($shipment->external_reference)) {
                try {
                    $shipment = $driver->reconcileShipment($shipment)->refresh();
                } catch (Throwable $e) {
                    report($e);
                }
            }

            if (strtolower(trim((string) $shipment->status)) === NormalizeShipmentStatus::CANCELLED) {
                continue;
            }

            if ($this->labelled($shipment)) {
                continue;
            }

            try {
                $outcome = ShipmentFulfillment::run((int) $shipment->id, static function (OrderShipment $locked) use ($driver): bool {
                    $driver->fulfillShipment($locked);

                    return true;
                });

                if ($outcome === ShipmentFulfillment::BUSY) {
                    $errors[] = 'Booking pengiriman sedang diproses. Muat ulang beberapa saat lagi.';
                }
            } catch (Throwable $e) {
                report($e);
                $errors[] = $e->getMessage();
            }
        }

        resolve(SyncOrderShippingFromShipments::class)->handle($order->refresh());

        $allFreshShipments = OrderShipment::query()
            ->where('order_id', $order->id)
            ->get();
        $freshShipments = $allFreshShipments
            ->reject(
                static fn (OrderShipment $shipment): bool => strtolower(trim((string) $shipment->status)) === NormalizeShipmentStatus::CANCELLED
                    || ShipmentMetadata::isSupersededByReship($shipment, $allFreshShipments),
            )
            ->values();

        if ($freshShipments->isEmpty()) {
            throw new RuntimeException($errors[0] ?? 'Semua pengiriman pada pesanan ini sudah dibatalkan.');
        }

        $awbs = $freshShipments
            ->map(static fn (OrderShipment $shipment): string => trim((string) ($shipment->awb ?: $shipment->tracking_number)))
            ->filter()
            ->values()
            ->all();

        if ($awbs === []) {
            $registered = $freshShipments->contains(
                static fn (OrderShipment $shipment): bool => filled($shipment->external_reference),
            );

            if ($registered && $errors === []) {
                throw new RuntimeException('Pickup sudah diajukan ke kurir (paket sedang dipacking). Nomor resi belum diterbitkan.');
            }

            throw new RuntimeException($errors[0] ?? 'Penyedia pengiriman belum mengembalikan nomor resi.');
        }

        return [
            'awbs' => $awbs,
            'print_route' => $printable ? route('shopper.orders.fulfillment.print-label', $order) : null,
            'errors' => $errors,
        ];
    }

    private function labelled(OrderShipment $shipment): bool
    {
        return trim((string) ($shipment->awb ?: $shipment->tracking_number)) !== '';
    }
}
