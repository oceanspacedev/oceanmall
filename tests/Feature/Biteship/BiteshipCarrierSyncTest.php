<?php

declare(strict_types=1);

namespace Tests\Feature\Biteship;

use App\Integrations\Biteship\Database\BiteshipCarrierSeeder;
use App\Support\EnabledCarriers;
use Illuminate\Support\Facades\Http;
use Shopper\Core\Models\Carrier;

final class BiteshipCarrierSyncTest extends BiteshipTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->enableBiteship();
    }

    /**
     * @return array<string, mixed>
     */
    private function couriers(): array
    {
        return ['success' => true, 'object' => 'courier', 'couriers' => [
            ['courier_name' => 'JNE', 'courier_code' => 'jne', 'courier_service_name' => 'Reguler', 'courier_service_code' => 'reg', 'shipment_duration_unit' => 'days'],
            ['courier_name' => 'JNE', 'courier_code' => 'jne', 'courier_service_name' => 'YES', 'courier_service_code' => 'yes', 'shipment_duration_unit' => 'days'],
            ['courier_name' => 'SiCepat', 'courier_code' => 'sicepat', 'courier_service_name' => 'BEST', 'courier_service_code' => 'best', 'shipment_duration_unit' => 'days'],
            ['courier_name' => 'Grab', 'courier_code' => 'grab', 'courier_service_name' => 'Instant', 'courier_service_code' => 'instant', 'shipment_duration_unit' => 'hours'],
            ['courier_name' => 'Kurir Kilat', 'courier_code' => 'kilat', 'courier_service_name' => 'Same Day', 'courier_service_code' => 'same_day', 'shipment_duration_unit' => 'hours'],
        ]];
    }

    public function test_sync_enables_offered_couriers_and_leaves_other_drivers_alone(): void
    {
        Carrier::query()->create(['slug' => 'jne', 'name' => 'JNE (RajaOngkir)', 'is_enabled' => true, 'driver' => 'rajaongkir']);
        Carrier::query()->create(['slug' => 'manual', 'name' => 'Kurir Toko', 'is_enabled' => true, 'driver' => 'manual']);
        $this->biteshipCarriers(['tiki']);
        Http::fake([self::BASE.'/v1/couriers' => Http::response($this->couriers())]);

        $this->artisan('shipping:sync-carriers', ['--driver' => 'biteship'])->assertSuccessful();

        $owned = Carrier::query()->where('driver', 'biteship')->get()->keyBy(static fn (Carrier $carrier): string => EnabledCarriers::courierCode($carrier));

        $this->assertTrue((bool) $owned['jne']->is_enabled);
        $this->assertSame('biteship-jne', $owned['jne']->slug);
        $this->assertSame(['reg', 'yes'], data_get($owned['jne']->metadata, 'biteship.services'));
        $this->assertTrue((bool) $owned['sicepat']->is_enabled);
        $this->assertFalse((bool) $owned['grab']->is_enabled, 'Instant couriers need coordinates');
        $this->assertFalse((bool) $owned['kilat']->is_enabled, 'Hour-only couriers are instant');
        $this->assertFalse((bool) $owned['tiki']->is_enabled, 'No longer offered');

        $this->assertTrue((bool) Carrier::query()->where('slug', 'jne')->value('is_enabled'));
        $this->assertSame('rajaongkir', Carrier::query()->where('slug', 'jne')->value('driver'));
        $this->assertTrue((bool) Carrier::query()->where('slug', 'manual')->value('is_enabled'));
    }

    public function test_a_courier_staff_switched_off_stays_off(): void
    {
        Http::fake([self::BASE.'/v1/couriers' => Http::response($this->couriers())]);
        $this->artisan('shipping:sync-carriers', ['--driver' => 'biteship'])->assertSuccessful();

        $sicepat = Carrier::query()->where('driver', 'biteship')->get()->first(static fn (Carrier $carrier): bool => EnabledCarriers::courierCode($carrier) === 'sicepat');
        $sicepat->update(['is_enabled' => false]);

        $this->artisan('shipping:sync-carriers', ['--driver' => 'biteship'])->assertSuccessful();

        $this->assertFalse((bool) $sicepat->refresh()->is_enabled);
    }

    public function test_dry_run_touches_nothing(): void
    {
        Http::fake([self::BASE.'/v1/couriers' => Http::response($this->couriers())]);

        $this->artisan('shipping:sync-carriers', ['--driver' => 'biteship', '--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, Carrier::query()->where('driver', 'biteship')->count());
    }

    public function test_a_failed_or_empty_answer_leaves_carriers_untouched(): void
    {
        $this->biteshipCarriers(['jne']);
        Http::fake([self::BASE.'/v1/couriers' => Http::sequence()
            ->push(['success' => false, 'error' => 'Unauthorized'], 401)
            ->push(['success' => true, 'couriers' => []]),
        ]);

        $this->artisan('shipping:sync-carriers', ['--driver' => 'biteship'])->assertFailed();
        $this->artisan('shipping:sync-carriers', ['--driver' => 'biteship'])->assertFailed();

        $this->assertTrue((bool) Carrier::query()->where('slug', 'biteship-jne')->value('is_enabled'));
    }

    public function test_seeding_with_a_key_creates_enabled_defaults_then_syncs(): void
    {
        Http::fake([self::BASE.'/v1/couriers' => Http::response($this->couriers())]);

        $this->seed(BiteshipCarrierSeeder::class);

        $owned = Carrier::query()->where('driver', 'biteship')->get()->keyBy(static fn (Carrier $carrier): string => EnabledCarriers::courierCode($carrier));
        $this->assertTrue((bool) $owned['jne']->is_enabled);
        $this->assertFalse((bool) $owned['tiki']->is_enabled, 'The sync switched off what Biteship does not offer');
    }
}
