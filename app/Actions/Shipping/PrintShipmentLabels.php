<?php

declare(strict_types=1);

namespace App\Actions\Shipping;

use App\Models\OrderShipment;
use App\Shipping\Contracts\PrintsLabels;
use App\Shipping\ShippingCapabilities;
use RuntimeException;
use Shopper\Core\Models\Order;
use Shopper\Shipping\Exceptions\ShippingException;

final readonly class PrintShipmentLabels
{
    public function __construct(private ShippingCapabilities $capabilities) {}

    /**
     * Provider labels for an order's shipments (or one shipment), printed by
     * the configured driver that can (PrintsLabels).
     *
     * @return array{label_url: string|null, pdf: string|null}
     *
     * @throws RuntimeException When no provider can print, or the driver
     *                          refuses (the message says why).
     */
    public function handle(Order $order, ?string $format = null, ?int $shipmentId = null): array
    {
        $shipments = OrderShipment::query()
            ->where('order_id', $order->id)
            ->when($shipmentId !== null, fn ($query) => $query->whereKey($shipmentId))
            ->orderBy('id')
            ->get();

        $byDriver = [];
        $printers = [];

        foreach ($shipments as $shipment) {
            $printer = $this->capabilities->forShipmentSupporting($shipment, PrintsLabels::class);

            if ($printer === null) {
                continue;
            }

            $printers[$printer->code()] = $printer;
            $byDriver[$printer->code()][] = $shipment;
        }

        if ($printers === []) {
            throw new RuntimeException(__('Label pengiriman otomatis belum tersedia untuk pesanan ini. Cetak label dari kurir, lalu isi nomor resi secara manual.'));
        }

        // One provider prints the labels of one order.
        $code = array_key_first($printers);

        try {
            $result = $printers[$code]->printShipmentLabels($byDriver[$code], $format);
        } catch (ShippingException $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }

        return [
            'label_url' => is_string($result['label_url'] ?? null) ? $result['label_url'] : null,
            'pdf' => is_string($result['pdf'] ?? null) ? $result['pdf'] : null,
        ];
    }
}
