<?php

declare(strict_types=1);

namespace App\Integrations\Biteship\Services;

use App\Integrations\Biteship\Biteship;
use App\Integrations\Biteship\Support\BiteshipCouriers;
use App\Shipping\CarrierSyncResult;
use App\Support\EnabledCarriers;
use Shopper\Core\Models\Carrier;
use Throwable;

/**
 * The Biteship courier list, pulled from GET /v1/couriers instead of a
 * hand-typed list (BiteshipShippingDriver::syncCarriers, run by the generic
 * `shipping:sync-carriers`):
 *
 * - every courier Biteship offers (instant couriers excepted, see
 *   BiteshipCouriers) becomes a Shopper carrier owned by this driver
 *   (carriers.driver = 'biteship', metadata.courier_code = Biteship code) and
 *   is enabled;
 * - carriers this driver owns that Biteship no longer offers are disabled;
 * - a carrier staff switched off in cpanel after a sync enabled it stays off
 *   (metadata.biteship.sync_enabled remembers what the sync set);
 * - manual carriers and other providers' carriers are never touched, and a
 *   slug another driver holds is not taken over (the new row gets
 *   "biteship-<code>").
 */
final readonly class BiteshipCarrierSync
{
    private const HEADERS = ['code', 'courier', 'Biteship services', 'state'];

    public function __construct(private BiteshipClient $client) {}

    public function sync(bool $dryRun = false): CarrierSyncResult
    {
        if (! Biteship::configured()) {
            return CarrierSyncResult::failed('Biteship is not configured (BITESHIP_API_KEY).');
        }

        try {
            $response = $this->client->couriers();
        } catch (Throwable $e) {
            return CarrierSyncResult::failed('Biteship couriers request failed: '.$e->getMessage());
        }

        $offered = $this->offered($response);

        if ($offered === []) {
            return CarrierSyncResult::failed('Biteship returned no couriers; carriers table left untouched.');
        }

        $rows = [];
        $enabledSlugs = [];
        $disabledSlugs = [];
        $syncedIds = [];
        $syncedAt = now()->toIso8601String();

        foreach ($offered as $code => $courier) {
            $carrier = $this->ownedCarrier($code);
            $metadata = is_array($carrier->metadata) ? $carrier->metadata : [];
            $staffDisabled = $carrier->exists
                && ! $carrier->is_enabled
                && data_get($metadata, 'biteship.sync_enabled') === true;
            $enabled = ! $courier['instant'] && ! $staffDisabled;

            $rows[] = [
                $code,
                $courier['name'],
                implode(', ', $courier['services']),
                match (true) {
                    $courier['instant'] => 'disabled (instant: needs coordinates)',
                    $staffDisabled => 'disabled (switched off by staff)',
                    default => 'ENABLED',
                },
            ];

            if ($dryRun) {
                continue;
            }

            $metadata[EnabledCarriers::COURIER_CODE] = $code;
            $metadata['biteship'] = [
                'name' => $courier['name'],
                'services' => $courier['services'],
                'instant' => $courier['instant'],
                // What this sync set; a later false is_enabled with this true
                // is a staff decision the next sync keeps.
                'sync_enabled' => $staffDisabled ? true : $enabled,
                'synced_at' => $syncedAt,
            ];

            $carrier->forceFill([
                'name' => $carrier->exists ? $carrier->name : (BiteshipCouriers::name($code) ?? $courier['name']),
                'description' => $carrier->exists ? $carrier->description : 'Pengiriman via '.(BiteshipCouriers::name($code) ?? $courier['name']),
                'driver' => Biteship::CODE,
                'is_enabled' => $enabled,
                'metadata' => $metadata,
            ])->save();

            $syncedIds[] = $carrier->id;

            if ($enabled) {
                $enabledSlugs[] = (string) $carrier->slug;
            } else {
                $disabledSlugs[] = (string) $carrier->slug;
            }
        }

        if (! $dryRun) {
            Carrier::query()
                ->where('driver', Biteship::CODE)
                ->whereNotIn('id', $syncedIds)
                ->get()
                ->each(function (Carrier $carrier) use ($syncedAt, &$rows, &$disabledSlugs): void {
                    $metadata = is_array($carrier->metadata) ? $carrier->metadata : [];
                    $metadata['biteship'] = [
                        ...(is_array($metadata['biteship'] ?? null) ? $metadata['biteship'] : []),
                        'sync_enabled' => false,
                        'synced_at' => $syncedAt,
                        'reason' => 'not offered by Biteship',
                    ];
                    $carrier->forceFill(['is_enabled' => false, 'metadata' => $metadata])->save();
                    $rows[] = [EnabledCarriers::courierCode($carrier), (string) $carrier->name, '-', 'disabled (not offered)'];
                    $disabledSlugs[] = (string) $carrier->slug;
                });
        }

        return new CarrierSyncResult(
            successful: true,
            message: sprintf('%s %d Biteship couriers.', $dryRun ? 'Probed' : 'Synced', count($offered)),
            enabled: $enabledSlugs,
            disabled: $disabledSlugs,
            headers: self::HEADERS,
            rows: $rows,
        );
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, array{name: string, services: list<string>, instant: bool}>
     */
    private function offered(array $response): array
    {
        $couriers = [];
        $hourly = [];

        foreach ((array) ($response['couriers'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $code = strtolower(trim((string) ($row['courier_code'] ?? '')));

            if ($code === '' || preg_match('/^[a-z0-9_.-]+$/', $code) !== 1) {
                continue;
            }

            $couriers[$code]['name'] = trim((string) ($row['courier_name'] ?? '')) ?: strtoupper($code);
            $service = trim((string) ($row['courier_service_code'] ?? ''));

            if ($service !== '') {
                $couriers[$code]['services'][] = $service;
            }

            $unit = strtolower(trim((string) ($row['shipment_duration_unit'] ?? '')));
            $hourly[$code] = ($hourly[$code] ?? true) && $unit === 'hours';
        }

        ksort($couriers);
        $offered = [];

        foreach ($couriers as $code => $courier) {
            $offered[$code] = [
                'name' => $courier['name'],
                'services' => array_values(array_unique($courier['services'] ?? [])),
                'instant' => BiteshipCouriers::isInstant($code) || ($hourly[$code] ?? false),
            ];
        }

        return $offered;
    }

    /**
     * The carrier this driver owns for a courier code; a new row otherwise.
     */
    private function ownedCarrier(string $code): Carrier
    {
        $owned = Carrier::query()
            ->where('driver', Biteship::CODE)
            ->orderBy('id')
            ->get()
            ->first(static fn (Carrier $carrier): bool => EnabledCarriers::courierCode($carrier) === $code);

        if ($owned instanceof Carrier) {
            return $owned;
        }

        $slugTaken = Carrier::query()->where('slug', $code)->exists();

        return new Carrier(['slug' => $slugTaken ? Biteship::CODE.'-'.$code : $code]);
    }
}
