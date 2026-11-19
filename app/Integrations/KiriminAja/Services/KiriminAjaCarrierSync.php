<?php

declare(strict_types=1);

namespace App\Integrations\KiriminAja\Services;

use App\Integrations\KiriminAja\Shipping\KiriminAjaDriver;
use App\Integrations\KiriminAja\Support\KiriminAjaCouriers;
use App\Shipping\CarrierSyncResult;
use App\Support\EnabledCarriers;
use Shopper\Core\Models\Carrier;
use Throwable;

/**
 * Aligns the Shopper carriers the `kiriminaja` driver owns with the express
 * couriers active for this KiriminAja account (POST /api/mitra/couriers),
 * keyed by metadata.courier_code. Run by the generic
 * `shipping:sync-carriers` through KiriminAjaDriver::syncCarriers().
 *
 * - an offered courier without a carrier gets one (enabled); a slug held by
 *   another driver (e.g. RajaOngkir's "jne") is never taken over: the new
 *   carrier is "kiriminaja-jne";
 * - an existing carrier keeps what staff set, except that a carrier this
 *   sync switched off earlier is switched on again once it is offered;
 * - a carrier of this driver that is no longer offered is switched off
 *   (remembered as `kiriminaja.synced_off`).
 *
 * With KIRIMINAJA_SYNC_WHITELIST=true the enabled codes are also sent to
 * set_whitelist_services (an account-wide preference that narrows quotes).
 */
final readonly class KiriminAjaCarrierSync
{
    private const HEADERS = ['code', 'courier', 'type', 'state'];

    public function __construct(private KiriminAjaClient $client) {}

    public function sync(bool $dryRun = false): CarrierSyncResult
    {
        try {
            $response = $this->client->couriers();
        } catch (Throwable $e) {
            return CarrierSyncResult::failed('KiriminAja courier list failed: '.$e->getMessage());
        }

        $offered = [];
        $rows = [];

        foreach ((array) ($response['datas'] ?? $response['data'] ?? []) as $courier) {
            if (! is_array($courier)) {
                continue;
            }

            $code = strtolower(trim((string) ($courier['code'] ?? '')));
            $type = strtolower(trim((string) ($courier['type'] ?? 'express')));

            if ($code === '') {
                continue;
            }

            if ($type !== 'express' || ! KiriminAjaCouriers::isExpressCode($code)) {
                $rows[] = [$code, (string) ($courier['name'] ?? $code), $type, 'skipped (not express)'];

                continue;
            }

            $offered[$code] = trim((string) ($courier['name'] ?? '')) ?: (KiriminAjaCouriers::name($code) ?? strtoupper($code));
        }

        if ($offered === []) {
            return CarrierSyncResult::failed('KiriminAja returned no express courier; carriers left untouched.');
        }

        $enabled = [];
        $disabled = [];
        $syncedAt = now()->toIso8601String();
        $owned = Carrier::query()->where('driver', KiriminAjaDriver::CODE)->orderBy('id')->get();
        $seen = [];

        foreach ($offered as $code => $name) {
            $carrier = $owned->first(static fn (Carrier $carrier): bool => EnabledCarriers::courierCode($carrier) === $code);
            $metadata = $carrier instanceof Carrier && is_array($carrier->metadata) ? $carrier->metadata : [];
            $bucket = is_array($metadata['kiriminaja'] ?? null) ? $metadata['kiriminaja'] : [];

            $isEnabled = ! $carrier instanceof Carrier
                || (bool) $carrier->is_enabled
                || ($bucket['synced_off'] ?? false) === true;

            $rows[] = [$code, $name, 'express', $carrier instanceof Carrier ? ($isEnabled ? 'enabled' : 'kept off (staff)') : 'created'];

            if ($carrier instanceof Carrier) {
                $seen[] = $carrier->id;
            }

            if ($dryRun) {
                continue;
            }

            if (! $carrier instanceof Carrier) {
                $carrier = new Carrier([
                    'slug' => Carrier::query()->where('slug', $code)->exists() ? KiriminAjaDriver::CODE.'-'.$code : $code,
                    'name' => $name,
                    'description' => "Pengiriman {$name} via KiriminAja",
                ]);
            }

            $metadata[EnabledCarriers::COURIER_CODE] = $code;
            $metadata['kiriminaja'] = ['name' => $name, 'synced_at' => $syncedAt, 'synced_off' => false];

            $carrier->forceFill([
                'driver' => KiriminAjaDriver::CODE,
                'is_enabled' => $isEnabled,
                'metadata' => $metadata,
            ])->save();

            $seen[] = $carrier->id;

            if ($isEnabled) {
                $enabled[] = (string) $carrier->slug;
            } else {
                $disabled[] = (string) $carrier->slug;
            }
        }

        foreach ($owned->whereNotIn('id', $seen) as $carrier) {
            $rows[] = [EnabledCarriers::courierCode($carrier), (string) $carrier->name, '-', 'disabled (not offered)'];

            if ($dryRun) {
                continue;
            }

            $metadata = is_array($carrier->metadata) ? $carrier->metadata : [];
            $metadata['kiriminaja'] = [
                ...(is_array($metadata['kiriminaja'] ?? null) ? $metadata['kiriminaja'] : []),
                'synced_at' => $syncedAt,
                // Only a carrier this sync switched off is switched on again.
                'synced_off' => (bool) $carrier->is_enabled || (data_get($metadata, 'kiriminaja.synced_off') === true),
            ];

            $carrier->forceFill(['is_enabled' => false, 'metadata' => $metadata])->save();
            $disabled[] = (string) $carrier->slug;
        }

        $message = sprintf('%s %d KiriminAja express couriers.', $dryRun ? 'Probed' : 'Synced', count($offered));

        if (! $dryRun && (bool) config('kiriminaja.sync_whitelist', false)) {
            $codes = Carrier::query()->where('driver', KiriminAjaDriver::CODE)->where('is_enabled', true)->get()
                ->map(static fn (Carrier $carrier): string => EnabledCarriers::courierCode($carrier))
                ->values()->all();

            try {
                $this->client->setWhitelistServices($codes);
                $message .= ' Whitelist set to: '.implode(', ', $codes).'.';
            } catch (Throwable $e) {
                $message .= ' Whitelist update failed: '.$e->getMessage();
            }
        }

        return new CarrierSyncResult(
            successful: true,
            message: $message,
            enabled: $enabled,
            disabled: $disabled,
            headers: self::HEADERS,
            rows: $rows,
        );
    }
}
