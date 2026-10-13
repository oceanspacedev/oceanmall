<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Komerce\ShippingCostClient;
use App\Services\Komerce\ShippingDeliveryClient;
use App\Support\KomerceCourierAssets;
use App\Support\RajaOngkirCouriers;
use Illuminate\Console\Command;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\Inventory;
use Throwable;

/**
 * Pull the courier list from the providers instead of a hand-typed list.
 *
 * - RajaOngkir Shipping Cost: which official couriers return services for the
 *   warehouse → reference route (what checkout can quote).
 * - Komerce Delivery: which couriers the account can ship with on the same
 *   route (what cpanel can turn into an AWB).
 *
 * Checkout quotes and Store Order both use Komerce Delivery, so a courier is
 * enabled when Komerce Delivery offers it; the RajaOngkir Cost answer is kept
 * as information (it is the fallback quote source and the tracking vocabulary).
 */
final class SyncKomerceCarriers extends Command
{
    protected $signature = 'komerce:sync-carriers
        {--dry-run : Show what the provider offers without touching the carriers table}';

    protected $description = 'Sync the carriers table from RajaOngkir Cost + Komerce Delivery responses (official courier list, logos, enabled state)';

    public function handle(ShippingCostClient $cost, ShippingDeliveryClient $delivery): int
    {
        if (! komerce_shipping_cost_enabled()) {
            $this->error('RajaOngkir Shipping Cost API key is required to sync carriers.');

            return self::FAILURE;
        }

        // The free Collaborator plan has no Shipping Delivery API: then the
        // Cost answer alone decides which couriers are offered (manual AWB).
        $hasDelivery = komerce_shipping_delivery_enabled();

        $inventory = Inventory::query()->where('is_default', true)->first()
            ?? Inventory::query()->first();
        $originId = trim((string) $inventory?->getAttribute('rajaongkir_origin_id'));

        if ($inventory === null || $originId === '') {
            $this->error('Default inventory has no rajaongkir_origin_id. Fill it in Settings → Locations first.');

            return self::FAILURE;
        }

        $destinationId = (int) config('komerce.sync.destination_id');
        $weightGrams = (int) config('komerce.sync.weight_grams', 1000);

        try {
            $quotable = $this->quotableCouriers($cost, $originId, $destinationId, $weightGrams);
        } catch (Throwable $e) {
            $this->error('RajaOngkir Shipping Cost probe failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $deliverable = [];

        if ($hasDelivery) {
            try {
                $deliverable = $this->deliverableCouriers($delivery, $inventory, $originId, $destinationId, $weightGrams);
            } catch (Throwable $e) {
                $this->error('Komerce Delivery probe failed: '.$e->getMessage());

                return self::FAILURE;
            }
        }

        if ($quotable === [] && $deliverable === []) {
            $this->error('Both providers returned no couriers for the reference route; carriers table left untouched. Check the reference destination or the Collaborator dashboard.');

            return self::FAILURE;
        }

        $rows = [];
        $syncedAt = now()->toIso8601String();
        $codes = array_values(array_unique(array_merge(array_keys(RajaOngkirCouriers::OFFICIAL), array_keys($deliverable))));

        foreach ($codes as $code) {
            $officialName = RajaOngkirCouriers::name($code);
            $canQuote = array_key_exists($code, $quotable);
            // With Delivery: checkout quotes and Store Order both run on it, so
            // "offered by Delivery" enables a courier. Without Delivery (free
            // plan): whatever the Cost API quotes is what the store can offer.
            $enabled = $hasDelivery ? array_key_exists($code, $deliverable) : $canQuote;

            $rows[] = [
                $code,
                $officialName ?? $deliverable[$code]['name'] ?? strtoupper($code),
                $canQuote ? implode(', ', $quotable[$code]) : '-',
                isset($deliverable[$code]) ? implode(', ', $deliverable[$code]['services']) : ($hasDelivery ? '-' : 'n/a (no Delivery API)'),
                $enabled ? 'ENABLED' : 'disabled',
            ];

            if ($this->option('dry-run')) {
                continue;
            }

            $carrier = Carrier::query()->firstOrNew(['slug' => $code]);
            $metadata = is_array($carrier->metadata) ? $carrier->metadata : [];
            $metadata['logo_url'] = KomerceCourierAssets::logoUrl($code) ?? ($metadata['logo_url'] ?? null);
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
                'driver' => 'rajaongkir',
                'is_enabled' => $enabled,
                'metadata' => $metadata,
            ])->save();
        }

        // Any other non-manual carrier is not something Komerce can ship for us.
        if (! $this->option('dry-run')) {
            Carrier::query()
                ->where('driver', '!=', 'manual')
                ->whereNotIn('slug', $codes)
                ->get()
                ->each(function (Carrier $carrier) use ($syncedAt, &$rows): void {
                    $metadata = is_array($carrier->metadata) ? $carrier->metadata : [];
                    $metadata['sync_reason'] = 'not a RajaOngkir/Komerce courier';
                    $metadata['synced_at'] = $syncedAt;
                    $carrier->forceFill(['is_enabled' => false, 'metadata' => $metadata])->save();
                    $rows[] = [(string) $carrier->slug, (string) $carrier->name, '-', '-', 'disabled'];
                });
        }

        $this->table(['code', 'courier', 'RajaOngkir Cost services', 'Komerce Delivery services', 'state'], $rows);
        $this->info(sprintf(
            '%s %d couriers (origin %s → destination %d).',
            $this->option('dry-run') ? 'Probed' : 'Synced',
            count($codes),
            $originId,
            $destinationId,
        ));

        return self::SUCCESS;
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
        $code = (int) data_get($response, 'meta.code', 200);

        if ($status !== 'success' || $code >= 400) {
            throw new \RuntimeException(sprintf(
                '%s responded with %s (%d): %s',
                $provider,
                $status,
                $code,
                (string) data_get($response, 'meta.message', 'no message'),
            ));
        }
    }

    /**
     * @return array<string, list<string>> code => service codes
     */
    private function quotableCouriers(ShippingCostClient $cost, string $originId, int $destinationId, int $weightGrams): array
    {
        $response = $cost->calculate(
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
    private function deliverableCouriers(ShippingDeliveryClient $delivery, Inventory $inventory, string $originId, int $destinationId, int $weightGrams): array
    {
        $originPin = trim((string) $inventory->latitude).','.trim((string) $inventory->longitude);

        $response = $delivery->calculate(
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
