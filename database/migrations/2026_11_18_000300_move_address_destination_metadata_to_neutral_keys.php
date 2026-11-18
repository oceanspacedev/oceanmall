<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Address destinations used to be stored under RajaOngkir-named keys. Copy
 * them to provider-neutral keys (the legacy keys are kept) and record which
 * driver the id belongs to:
 *
 *   rajaongkir_destination_id    -> destination_id (+ destination_driver = rajaongkir)
 *   rajaongkir_destination_label -> destination_label
 *   rajaongkir_pin_point         -> pin_point
 *
 * Applies to the customer address book (user_addresses.metadata) and to the
 * order snapshot (orders.metadata.shipping_address). Readers keep falling back
 * to the legacy keys, so rows written by an older release still work.
 */
return new class extends Migration
{
    /** @var array<string, string> legacy => neutral */
    private const KEYS = [
        'rajaongkir_destination_id' => 'destination_id',
        'rajaongkir_destination_label' => 'destination_label',
        'rajaongkir_pin_point' => 'pin_point',
    ];

    private const LEGACY_DRIVER = 'rajaongkir';

    public function up(): void
    {
        $this->rewrite(shopper_table('user_addresses'), null, fn (array $address): array => $this->toNeutral($address));
        $this->rewrite(shopper_table('orders'), 'shipping_address', fn (array $address): array => $this->toNeutral($address));
    }

    public function down(): void
    {
        $this->rewrite(shopper_table('user_addresses'), null, fn (array $address): array => $this->toLegacy($address));
        $this->rewrite(shopper_table('orders'), 'shipping_address', fn (array $address): array => $this->toLegacy($address));
    }

    /**
     * @param  Closure(array<string, mixed>): array<string, mixed>  $transform
     */
    private function rewrite(string $table, ?string $path, Closure $transform): void
    {
        DB::table($table)
            ->whereNotNull('metadata')
            ->select(['id', 'metadata'])
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($table, $path, $transform): void {
                foreach ($rows as $row) {
                    $metadata = is_string($row->metadata) ? json_decode($row->metadata, true) : null;

                    if (! is_array($metadata)) {
                        continue;
                    }

                    $address = $path === null ? $metadata : ($metadata[$path] ?? null);

                    if (! is_array($address)) {
                        continue;
                    }

                    $rewritten = $transform($address);

                    if ($rewritten === $address) {
                        continue;
                    }

                    if ($path === null) {
                        $metadata = $rewritten;
                    } else {
                        $metadata[$path] = $rewritten;
                    }

                    DB::table($table)->where('id', $row->id)->update([
                        'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                    ]);
                }
            });
    }

    /**
     * @param  array<string, mixed>  $address
     * @return array<string, mixed>
     */
    private function toNeutral(array $address): array
    {
        $movedId = false;

        foreach (self::KEYS as $legacy => $neutral) {
            if (! array_key_exists($legacy, $address)) {
                continue;
            }

            // Copy, not move: the legacy keys stay so rows keep working with
            // the previous release after a rollback (down() + old code) and
            // for an instance of the previous release during a rolling deploy
            // (all 2026_11_18_* migrations are expand-only, see README
            // "Upgrading to provider-neutral shipping"). The current code
            // rewrites a row to neutral keys only when it saves it again
            // (AddressDestination::normalize()).
            $value = $address[$legacy];

            if (! $this->filled($address[$neutral] ?? null) && $this->filled($value)) {
                $address[$neutral] = is_scalar($value) ? trim((string) $value) : $value;
                $movedId = $movedId || $neutral === 'destination_id';
            }
        }

        if ($movedId && ! $this->filled($address['destination_driver'] ?? null)) {
            $address['destination_driver'] = self::LEGACY_DRIVER;
        }

        return $address;
    }

    /**
     * @param  array<string, mixed>  $address
     * @return array<string, mixed>
     */
    private function toLegacy(array $address): array
    {
        $driver = $address['destination_driver'] ?? null;

        if ($this->filled($driver) && $driver !== self::LEGACY_DRIVER) {
            return $address;
        }

        foreach (self::KEYS as $legacy => $neutral) {
            if ($this->filled($address[$neutral] ?? null) && ! $this->filled($address[$legacy] ?? null)) {
                $address[$legacy] = $address[$neutral];
            }

            unset($address[$neutral]);
        }

        unset($address['destination_driver']);

        return $address;
    }

    private function filled(mixed $value): bool
    {
        return is_scalar($value) && trim((string) $value) !== '';
    }
};
