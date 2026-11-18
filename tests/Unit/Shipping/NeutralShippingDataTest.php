<?php

declare(strict_types=1);

namespace Tests\Unit\Shipping;

use App\Shipping\AddressDestination;
use App\Shipping\SelectedRate;
use PHPUnit\Framework\TestCase;

final class NeutralShippingDataTest extends TestCase
{
    public function test_a_search_row_with_only_id_and_label_gets_null_region_keys(): void
    {
        $this->assertSame(
            ['id' => 'IDNP6', 'label' => 'Coblong', 'postal_code' => null, 'city' => null, 'state' => null, 'district' => null],
            AddressDestination::searchRow(['id' => 'IDNP6', 'label' => 'Coblong']),
        );

        $row = AddressDestination::searchRow(['id' => 17547, 'label' => ' MELAWAI ', 'postal_code' => ' 12160 ', 'zip_code' => '12160']);
        $this->assertSame('17547', $row['id']);
        $this->assertSame('MELAWAI', $row['label']);
        $this->assertSame('12160', $row['postal_code']);
        $this->assertSame('12160', $row['zip_code'], 'Extra keys pass through.');

        $this->assertNull(AddressDestination::searchRow(['label' => 'no id']));
        $this->assertSame([], AddressDestination::searchRows([null, 'x', ['label' => 'no id']]));
    }

    public function test_for_driver_normalises_legacy_keys_and_clears_foreign_or_unused_destinations(): void
    {
        $legacy = ['city' => 'Jakarta', 'rajaongkir_destination_id' => '17547', 'rajaongkir_destination_label' => 'MELAWAI'];

        $this->assertSame(
            ['city' => 'Jakarta', 'destination_id' => '17547', 'destination_label' => 'MELAWAI', 'destination_driver' => 'rajaongkir'],
            AddressDestination::forDriver($legacy, 'rajaongkir'),
        );

        $foreign = AddressDestination::forDriver(['destination_id' => 'AREA-9', 'destination_driver' => 'biteship'], 'rajaongkir');
        $this->assertNull($foreign['destination_id']);
        $this->assertNull($foreign['destination_label']);
        $this->assertArrayNotHasKey('destination_driver', $foreign);

        $this->assertNull(AddressDestination::forDriver($legacy, null)['destination_id']);
    }

    public function test_selected_rate_keeps_driver_details_and_drops_display_only_keys(): void
    {
        $rate = SelectedRate::fromQuote([
            'service_code' => 'BEST',
            'service_name' => 'Besok Sampai',
            'amount' => 21000,
            'currency' => 'IDR',
            'carrier_code' => 'sicepat',
            'carrier_name' => 'SiCepat',
            'carrier_id' => '7',
            'estimated_days' => 1,
            'driver' => 'areaship',
            'description' => 'display only',
            'carrier_logo' => 'https://cdn.example.test/logo.png',
            // Details a new provider attached with ShippingQuoteContext::describe().
            'rate_id' => 'rt_123',
            'courier_type' => 'instant',
            'object' => new \stdClass,
        ]);

        $this->assertSame('BEST', $rate['id']);
        $this->assertSame(21000, $rate['price']);
        $this->assertSame(21000, $rate['shipping_cost']);
        $this->assertSame(7, $rate['carrier_id']);
        $this->assertSame('areaship', $rate['driver']);
        $this->assertSame('rt_123', $rate['rate_id']);
        $this->assertSame('instant', $rate['courier_type']);
        $this->assertArrayNotHasKey('description', $rate);
        $this->assertArrayNotHasKey('carrier_logo', $rate);
        $this->assertArrayNotHasKey('object', $rate);
    }
}
