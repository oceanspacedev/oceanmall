<?php

declare(strict_types=1);

namespace App\Integrations\Komerce\Services;

use App\Integrations\Komerce\Komerce;
use App\Integrations\Komerce\Shipping\KomerceShippingDriver;
use App\Integrations\Komerce\Shipping\RajaOngkirDriver;
use App\Integrations\Komerce\Support\KomerceCapabilities;
use App\Integrations\Komerce\Support\KomerceCourierAssets;
use App\Integrations\Komerce\Support\RajaOngkirCouriers;
use App\Shipping\CarrierSyncResult;
use App\Shipping\InventoryOrigins;
use App\Support\EnabledCarriers;
use RuntimeException;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\Inventory;
use Throwable;

/**
 * The courier list of the RajaOngkir / Komerce products, pulled from the
 * providers instead of a hand-typed list (RajaOngkirDriver::syncCarriers):
 *
 * - RajaOngkir Shipping Cost: which official couriers return services for the
 *   warehouse -> reference route (what checkout can quote).
 * - Komerce Delivery (when configured): which couriers the account can ship
 *   with on the same route (what can become an AWB). With Delivery, a courier
 *   is enabled when Delivery offers it; without it (free plan), whatever Cost
 *   quotes is enabled.
 *
 * It only ever touches carriers the RajaOngkir driver owns
 * (carriers.driver = 'rajaongkir'): manual carriers and other providers'
 * carriers keep their driver and enabled state. The courier code is stored in
 * metadata.courier_code; a slug another driver already uses is not taken
 * over (the new row gets "rajaongkir-<code>").
 */
final readonly class KomerceCarrierSync
{
    private const HEADERS = ['code', 'courier', 'RajaOngkir Cost services', 'Komerce Delivery services', 'state'];

    public function __construct(
        private ShippingCostClient $cost,
        private ShippingDeliveryClient $delivery,
    ) {}

    public function sync(bool $dryRun = false): CarrierSyncResult
    {
        $hasCost = Komerce::shippingCostEnabled();
        $hasDelivery = Komerce::shippingDeliveryEnabled();

        if (! $hasCost && ! $hasDelivery) {
            return CarrierSyncResult::failed('RajaOngkir Shipping Cost or Komerce Delivery API key is required to sync carriers.');
        }

        $inventory = Inventory::query()->where('is_default', true)->first()
            ?? Inventory::query()->first();
        $origins = resolve(InventoryOrigins::class);
        $originId = (string) ($hasCost
            ? ($origins->get($inventory, RajaOngkirDriver::CODE) ?? $origins->get($inventory, KomerceShippingDriver::CODE))
            : ($origins->get($inventory, KomerceShippingDriver::CODE) ?? $origins->get($inventory, RajaOngkirDriver::CODE)));

        if ($inventory === null || $originId === '') {
            return CarrierSyncResult::failed('Default inventory has no RajaOngkir origin id. Fill it in Settings → Locations first.');
        }

        $destinationId = (int) config('komerce.sync.destination_id');
        $weightGrams = (int) config('komerce.sync.weight_grams', 1000);
        $quotable = [];

        if ($hasCost) {
            try {
                $quotable = $this->quotableCouriers($originId, $destinationId, $weightGrams);
            } catch (Throwable $e) {
                $costUnavailable = ! Komerce::shippingCostEnabled()
                    || resolve(KomerceCapabilities::class)->quotaExhausted('shipping_cost');

                if ($hasDelivery && $costUnavailable) {
                    $hasCost = false;
                    $quotable = [];
                } else {
                    return CarrierSyncResult::failed('RajaOngkir Shipping Cost probe failed: '.$e->getMessage());
                }
            }
        }

        $deliverable = [];

        if ($hasDelivery) {
            try {
                $deliverable = $this->deliverableCouriers($inventory, $originId, $destinationId, $weightGrams);
            } catch (Throwable $e) {
                if (! Komerce::shippingDeliveryEnabled() && $hasCost) {
                    $hasDelivery = false;
                } else {
                    return CarrierSyncResult::failed('Komerce Delivery probe failed: '.$e->getMessage());
                }
            }
        }

        if ($quotable === [] && $deliverable === []) {
            return CarrierSyncResult::failed('Both providers returned no couriers for the reference route; carriers table left untouched. Check the reference destination or the Collaborator dashboard.');
        }

        $rows = [];
        $enabledSlugs = [];
        $disabledSlugs = [];
        $syncedIds = [];
        $syncedAt = now()->toIso8601String();
        $codes = array_values(array_unique(array_merge(array_keys(RajaOngkirCouriers::OFFICIAL), array_keys($deliverable))));

        foreach ($codes as $code) {
            $officialName = RajaOngkirCouriers::name($code);
            $canQuote = array_key_exists($code, $quotable);
            $enabled = $hasDelivery ? array_key_exists($code, $deliverable) : $canQuote;

            $rows[] = [
                $code,
                $officialName ?? $deliverable[$code]['name'] ?? strtoupper($code),
                $hasCost ? ($canQuote ? implode(', ', $quotable[$code]) : '-') : 'n/a (no Cost API)',
                isset($deliverable[$code]) ? implode(', ', $deliverable[$code]['services']) : ($hasDelivery ? '-' : 'n/a (no Delivery API)'),
                $enabled ? 'ENABLED' : 'disabled',
            ];

            if ($dryRun) {
                continue;
            }

            $carrier = $this->ownedCarrier($code);
            $metadata = is_array($carrier->metadata) ? $carrier->metadata : [];
            $metadata[EnabledCarriers::COURIER_CODE] = $code;
            // The Komerce CDN logo is served by the module's CarrierLogos source
            // while Komerce is configured; never persist it (metadata wins
            // over every source and would outlive the provider).
            if (is_string($metadata['logo_url'] ?? null) && KomerceCourierAssets::isProviderUrl($metadata['logo_url'])) {
                unset($metadata['logo_url']);
            }
            $metadata['rajaongkir'] = ['services' => $quotable[$code] ?? [], 'synced_at' => $syncedAt];
            $metadata['komerce'] = [
                'shipping_name' => $deliverable[$code]['name'] ?? null,
                'services' => $deliverable[$code]['services'] ?? [],
                'synced_at' => $syncedAt,
            ];
            $metadata['sync_reason'] = match (true) {
                $enabled && $hasDelivery => 'offered by Komerce Delivery for this account',
                $enabled => 'quoted by RajaOngkir Shipping Cost (no Delivery API on this plan: AWB is entered manually)',
                $canQuote => 'quoted by RajaOngkir Cost but not offered by Komerce Delivery for this account',
                default => $hasDelivery ? 'not offered by Komerce Delivery for this account' : 'RajaOngkir Shipping Cost returned no service',
            };

            $carrier->forceFill([
                'name' => $carrier->exists ? $carrier->name : ($officialName ?? $deliverable[$code]['name'] ?? strtoupper($code)),
                'driver' => RajaOngkirDriver::CODE,
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

        // Other carriers this driver owns are not something the providers ship for us.
        if (! $dryRun) {
            Carrier::query()
                ->where('driver', RajaOngkirDriver::CODE)
                ->whereNotIn('id', $syncedIds)
                ->get()
                ->each(function (Carrier $carrier) use ($syncedAt, &$rows, &$disabledSlugs): void {
                    $metadata = is_array($carrier->metadata) ? $carrier->metadata : [];
                    $metadata['sync_reason'] = 'not a RajaOngkir/Komerce courier';
                    $metadata['synced_at'] = $syncedAt;
                    $carrier->forceFill(['is_enabled' => false, 'metadata' => $metadata])->save();
                    $rows[] = [(string) $carrier->slug, (string) $carrier->name, '-', '-', 'disabled'];
                    $disabledSlugs[] = (string) $carrier->slug;
                });
        }

        return new CarrierSyncResult(
            successful: true,
            message: sprintf(
                '%s %d couriers (origin %s → destination %d).',
                $dryRun ? 'Probed' : 'Synced',
                count($codes),
                $originId,
                $destinationId,
            ),
            enabled: $enabledSlugs,
            disabled: $disabledSlugs,
            headers: self::HEADERS,
            rows: $rows,
        );
    }

    /**
     * The carrier this driver owns for a courier code; a new row otherwise.
     * A slug held by another driver's carrier is left alone.
     */
    private function ownedCarrier(string $code): Carrier
    {
        $owned = Carrier::query()
            ->where('driver', RajaOngkirDriver::CODE)
            ->orderBy('id')
            ->get()
            ->first(static fn (Carrier $carrier): bool => EnabledCarriers::courierCode($carrier) === $code);

        if ($owned instanceof Carrier) {
            return $owned;
        }

        $slugTaken = Carrier::query()->where('slug', $code)->exists();

        return new Carrier(['slug' => $slugTaken ? RajaOngkirDriver::CODE.'-'.$code : $code]);
    }

    /**
     * Providers wrap every answer in meta{code,status}; anything but success
     * must not be interpreted as "no couriers".
     *
     * @param  array<string, mixed>  $response
     */
    private function assertSuccessEnvelope(array $response, string $provider): void
    {
        $status = strtolower(trim((string) data_get($response, 'meta.status', 'success')));
        $codeRaw = data_get($response, 'meta.code');
        $code = is_numeric($codeRaw) ? (int) $codeRaw : 200;

        if (($response['success'] ?? null) === false || $status !== 'success' || $code >= 400) {
            throw new RuntimeException(sprintf(
                '%s responded with %s (%d): %s',
                $provider,
                $status,
                $code,
                (string) data_get($response, 'meta.message', data_get($response, 'message', 'no message')),
            ));
        }
    }

    /**
     * @return array<string, list<string>> code => service codes
     */
    private function quotableCouriers(string $originId, int $destinationId, int $weightGrams): array
    {
        if (resolve(KomerceCapabilities::class)->quotaExhausted('shipping_cost')) {
            throw new RuntimeException('Shipping Cost daily quota exhausted.');
        }

        $response = $this->cost->calculate(
            ['id' => $originId],
            ['id' => $destinationId],
            $weightGrams,
            array_keys(RajaOngkirCouriers::OFFICIAL),
        );

        $this->assertSuccessEnvelope($response, 'RajaOngkir Shipping Cost');

        $services = [];

        foreach ((array) data_get($response, 'data', []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $code = strtolower(trim((string) ($row['code'] ?? '')));

            if (! RajaOngkirCouriers::isOfficial($code)) {
                continue;
            }

            $services[$code][] = (string) ($row['service'] ?? '?');
        }

        return $services;
    }

    /**
     * @return array<string, array{name: string, services: list<string>}> code => Komerce shipping_name + services
     */
    private function deliverableCouriers(Inventory $inventory, string $originId, int $destinationId, int $weightGrams): array
    {
        if (resolve(KomerceCapabilities::class)->quotaExhausted('shipping_delivery')) {
            throw new RuntimeException('Komerce Delivery daily quota exhausted.');
        }

        $originPin = trim((string) $inventory->latitude).','.trim((string) $inventory->longitude);

        $response = $this->delivery->calculate(
            $originId,
            $destinationId,
            $originPin,
            (string) config('komerce.sync.destination_pin_point'),
            max(0.001, $weightGrams / 1000),
            (int) config('komerce.sync.item_value', 0),
        );

        $this->assertSuccessEnvelope($response, 'Komerce Delivery');

        $services = [];

        foreach (['calculate_reguler', 'calculate_cargo', 'calculate_instant'] as $group) {
            foreach ((array) data_get($response, "data.{$group}", []) as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $name = trim((string) ($row['shipping_name'] ?? ''));

                if ($name === '') {
                    continue;
                }

                $code = RajaOngkirCouriers::codeFromKomerceName($name) ?? strtolower(preg_replace('/[^a-z0-9]/i', '', $name) ?? '');

                if ($code === '') {
                    continue;
                }

                $services[$code]['name'] = $name;
                $services[$code]['services'][] = (string) ($row['service_name'] ?? '?');
            }
        }

        return $services;
    }
}
