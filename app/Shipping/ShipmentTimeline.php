<?php

declare(strict_types=1);

namespace App\Shipping;

use App\Models\OrderShipment;
use Illuminate\Support\Collection;
use Shopper\Core\Enum\ShipmentStatus;
use Shopper\Core\Models\OrderShipping;
use Shopper\Core\Models\OrderShippingEvent;

/**
 * The customer-facing tracking timeline of each warehouse shipment (web order
 * page, mobile API), from two sources:
 *
 * - the provider history a shipping driver writes to
 *   order_shipments.metadata.tracking_history (ShipmentMetadata);
 * - Shopper's own shipment event log (order_shipping_events, written by
 *   Shopper's RecordShipmentEventAction when staff add an event in cpanel,
 *   e.g. for a parcel shipped with manual AWB entry and no provider).
 *
 * A Shopper OrderShipping row belongs to the shipment with the same AWB /
 * tracking number; rows that match none go to the order's only shipment.
 */
final class ShipmentTimeline
{
    /**
     * OrderShippingEvent metadata.source of rows mirrored from a provider's
     * tracking_history (SyncOrderShippingFromShipments). They are already in
     * the provider history, so the timeline skips them.
     */
    public const PROVIDER_SOURCE = 'provider';

    /**
     * @param  iterable<OrderShipment>  $shipments  The order's warehouse shipments
     * @return array<int, list<array{description: string, datetime: string|null, location: string|null}>> keyed by shipment id
     */
    public static function forShipments(int $orderId, iterable $shipments): array
    {
        $shipments = collect($shipments)->values();
        $shopperRows = OrderShipping::query()
            ->where('order_id', $orderId)
            ->with('events')
            ->orderBy('id')
            ->get();

        $used = [];
        $timelines = [];

        foreach ($shipments as $shipment) {
            $keys = collect([$shipment->awb, $shipment->tracking_number])
                ->map(static fn (mixed $value): string => strtolower(trim((string) $value)))
                ->filter()
                ->unique()
                ->all();

            $events = [];

            foreach ($shopperRows as $row) {
                $tracking = strtolower(trim((string) $row->tracking_number));

                if (isset($used[$row->id]) || $tracking === '' || ! in_array($tracking, $keys, true)) {
                    continue;
                }

                $used[$row->id] = true;
                array_push($events, ...self::shopperEvents($row));
            }

            $timelines[(int) $shipment->id] = [$shipment, $events];
        }

        // Shopper shipments with no matching AWB (e.g. a label created before
        // the AWB was copied to the warehouse shipment) belong to the order's
        // only warehouse shipment.
        if ($shipments->count() === 1) {
            $id = (int) $shipments->first()->id;

            foreach ($shopperRows as $row) {
                if (! isset($used[$row->id])) {
                    array_push($timelines[$id][1], ...self::shopperEvents($row));
                }
            }
        }

        $result = [];

        foreach ($timelines as $id => [$shipment, $events]) {
            $result[$id] = self::merge(ShipmentMetadata::trackingHistory($shipment), $events);
        }

        return $result;
    }

    /**
     * @return list<array{description: string, datetime: string|null, location: string|null, sort: int|null}>
     */
    private static function shopperEvents(OrderShipping $row): array
    {
        $events = [];

        /** @var Collection<int, OrderShippingEvent> $rowEvents */
        $rowEvents = $row->events;

        foreach ($rowEvents as $event) {
            if (data_get($event->metadata, 'source') === self::PROVIDER_SOURCE) {
                continue;
            }

            $description = trim((string) $event->description);
            $status = $event->status;
            $occurredAt = $event->occurred_at ?? $event->created_at;

            $events[] = [
                'description' => $description !== ''
                    ? $description
                    : ($status instanceof ShipmentStatus ? $status->getLabel() : 'Paket diproses'),
                'datetime' => $occurredAt?->copy()->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i'),
                'location' => filled($event->location) ? (string) $event->location : null,
                'sort' => $occurredAt?->getTimestamp(),
            ];
        }

        return $events;
    }

    /**
     * Provider history as the provider orders it; with Shopper events too,
     * everything newest first (undated entries last, in their own order).
     *
     * Retaining the early return when `$shopper === []` preserves the
     * provider's native ordering (required by ShipmentTrackingRefreshTest).
     * When merging provider and Shopper events, timestamps are normalized to
     * minute precision so Shopper events created within the same minute do not
     * sort ahead of minute-granular carrier manifest scans; ties inside the
     * same minute put provider scans before Shopper events, then newest exact
     * timestamp first, then original index.
     *
     * @param  list<array{description: string, datetime: string|null, location: string|null}>  $provider
     * @param  list<array{description: string, datetime: string|null, location: string|null, sort: int|null}>  $shopper
     * @return list<array{description: string, datetime: string|null, location: string|null}>
     */
    private static function merge(array $provider, array $shopper): array
    {
        if ($shopper === []) {
            return $provider;
        }

        $all = [];

        foreach ($provider as $index => $event) {
            $parsed = $event['datetime'] !== null ? strtotime($event['datetime']) : false;
            $exact = $parsed === false ? null : $parsed;
            $minute = $exact !== null ? (int) (floor($exact / 60) * 60) : null;
            $all[] = [$event, $minute, 0, $exact, $index];
        }

        foreach ($shopper as $index => $event) {
            $exact = $event['sort'];
            $minute = $exact !== null ? (int) (floor($exact / 60) * 60) : null;
            unset($event['sort']);
            $all[] = [$event, $minute, 1, $exact, count($provider) + $index];
        }

        usort($all, static function (array $a, array $b): int {
            if ($a[1] === null || $b[1] === null) {
                return [$a[1] === null, $a[4]] <=> [$b[1] === null, $b[4]];
            }

            $minuteCmp = $b[1] <=> $a[1];
            if ($minuteCmp !== 0) {
                return $minuteCmp;
            }

            $sourceCmp = $a[2] <=> $b[2];
            if ($sourceCmp !== 0) {
                return $sourceCmp;
            }

            $exactCmp = ($b[3] ?? 0) <=> ($a[3] ?? 0);
            if ($exactCmp !== 0) {
                return $exactCmp;
            }

            return $a[4] <=> $b[4];
        });

        return array_map(static fn (array $entry): array => $entry[0], $all);
    }
}
