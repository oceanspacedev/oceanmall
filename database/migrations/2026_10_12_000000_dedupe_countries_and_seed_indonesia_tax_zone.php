<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Shopper\Core\Models\Country;

/**
 * Data repair for an Indonesia-only store:
 *  1. Shopper's CountriesTableSeeder was run twice, leaving every country
 *     (including Indonesia) duplicated. Keep the lowest id per cca2 and
 *     repoint every reference to it.
 *  2. The tax zones shipped with Shopper's demo data (CM, CI, SN, FR, DE,
 *     CA, US) do not apply here. Replace them with an Indonesian PPN zone.
 *
 * Idempotent: safe to run on databases that are already clean.
 */
return new class extends Migration
{
    /** @var array<int, string> */
    private array $demoTaxCountries = ['CM', 'CI', 'SN', 'FR', 'DE', 'CA', 'US'];

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->dedupeCountries();
            $this->replaceDemoTaxZones();
        });
    }

    public function down(): void
    {
        // Data repair; nothing sensible to restore.
    }

    private function dedupeCountries(): void
    {
        $countries = shopper_table('countries');

        $duplicates = DB::table($countries)
            ->select('cca2', DB::raw('MIN(id) as keep_id'), DB::raw('COUNT(*) as total'))
            ->groupBy('cca2')
            ->having('total', '>', 1)
            ->get();

        foreach ($duplicates as $duplicate) {
            $keepId = (int) $duplicate->keep_id;
            $extraIds = DB::table($countries)
                ->where('cca2', $duplicate->cca2)
                ->where('id', '!=', $keepId)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            foreach (['user_addresses', 'cart_addresses', 'inventories', 'tax_zones'] as $table) {
                $name = shopper_table($table);

                if (Schema::hasTable($name) && Schema::hasColumn($name, 'country_id')) {
                    DB::table($name)->whereIn('country_id', $extraIds)->update(['country_id' => $keepId]);
                }
            }

            $this->repointZoneRelations($keepId, $extraIds);
            $this->repointStoreCountrySetting($keepId, $extraIds);

            DB::table($countries)->whereIn('id', $extraIds)->delete();
        }
    }

    /**
     * @param  array<int, int>  $extraIds
     */
    private function repointZoneRelations(int $keepId, array $extraIds): void
    {
        $relations = shopper_table('zone_has_relations');

        if (! Schema::hasTable($relations)) {
            return;
        }

        $countryType = Country::class;

        $rows = DB::table($relations)
            ->where('zonable_type', $countryType)
            ->whereIn('zonable_id', $extraIds)
            ->get();

        foreach ($rows as $row) {
            $alreadyLinked = DB::table($relations)
                ->where('zone_id', $row->zone_id)
                ->where('zonable_type', $countryType)
                ->where('zonable_id', $keepId)
                ->exists();

            $query = DB::table($relations)
                ->where('zone_id', $row->zone_id)
                ->where('zonable_type', $countryType)
                ->where('zonable_id', $row->zonable_id);

            $alreadyLinked ? $query->delete() : $query->update(['zonable_id' => $keepId]);
        }
    }

    /**
     * @param  array<int, int>  $extraIds
     */
    private function repointStoreCountrySetting(int $keepId, array $extraIds): void
    {
        $settings = shopper_table('settings');

        if (! Schema::hasTable($settings)) {
            return;
        }

        $row = DB::table($settings)->where('key', 'country_id')->first();

        if (! $row) {
            return;
        }

        $current = (int) trim((string) $row->value, '"');

        if (in_array($current, $extraIds, true)) {
            DB::table($settings)->where('key', 'country_id')->update(['value' => json_encode($keepId)]);
        }
    }

    private function replaceDemoTaxZones(): void
    {
        $countries = shopper_table('countries');
        $taxZones = shopper_table('tax_zones');
        $taxRates = shopper_table('tax_rates');

        if (! Schema::hasTable($taxZones) || ! Schema::hasTable($taxRates)) {
            return;
        }

        $demoCountryIds = DB::table($countries)->whereIn('cca2', $this->demoTaxCountries)->pluck('id');

        if ($demoCountryIds->isNotEmpty()) {
            $zoneIds = DB::table($taxZones)->whereIn('country_id', $demoCountryIds)->pluck('id');

            DB::table($taxRates)->whereIn('tax_zone_id', $zoneIds)->delete();
            DB::table($taxZones)->whereIn('id', $zoneIds)->delete();
        }

        $indonesiaId = DB::table($countries)->where('cca2', 'ID')->value('id');

        if (! $indonesiaId) {
            return;
        }

        $zoneId = DB::table($taxZones)
            ->where('country_id', $indonesiaId)
            ->whereNull('parent_id')
            ->value('id');

        if (! $zoneId) {
            $zoneId = DB::table($taxZones)->insertGetId([
                'country_id' => $indonesiaId,
                'is_tax_inclusive' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $hasRate = DB::table($taxRates)->where('tax_zone_id', $zoneId)->exists();

        if (! $hasRate) {
            DB::table($taxRates)->insert([
                'tax_zone_id' => $zoneId,
                'name' => 'PPN 11%',
                'code' => 'ID-PPN',
                'rate' => 11,
                'is_default' => true,
                'is_combinable' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
