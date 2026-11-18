<?php

declare(strict_types=1);

namespace Tests\Unit\Shipping;

use App\Shipping\DestinationCoordinateResolver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class DestinationCoordinateResolverTest extends TestCase
{
    public function test_resolves_direct_rajaongkir_pin_point_when_present(): void
    {
        $resolver = new DestinationCoordinateResolver;
        $pin = $resolver->resolve([
            'rajaongkir_pin_point' => '-6.7167,108.5333',
        ]);

        $this->assertSame('-6.7167,108.5333', $pin);
    }

    public function test_resolves_from_latitude_and_longitude_when_present(): void
    {
        $resolver = new DestinationCoordinateResolver;
        $pin = $resolver->resolve([
            'latitude' => -6.7167,
            'longitude' => 108.5333,
        ]);

        $this->assertSame('-6.7167,108.5333', $pin);
    }

    public function test_geocodes_from_destination_label_when_coordinates_are_missing(): void
    {
        Http::fake([
            'https://nominatim.openstreetmap.org/*' => Http::response([
                [
                    'lat' => '-6.7127481',
                    'lon' => '108.5320267',
                ],
            ], 200),
        ]);

        $resolver = new DestinationCoordinateResolver;
        $pin = $resolver->resolve([
            'rajaongkir_destination_id' => '17245',
            'rajaongkir_destination_label' => 'KEDAWUNG, KEDAWUNG, CIREBON, JAWA BARAT, 45153',
            'city' => 'CIREBON',
            'state' => 'JAWA BARAT',
        ]);

        $this->assertSame('-6.712748,108.532027', $pin);
    }

    public function test_falls_back_to_warehouse_pin_when_geocoding_fails(): void
    {
        Http::fake([
            'https://nominatim.openstreetmap.org/*' => Http::response([], 500),
        ]);

        $resolver = new DestinationCoordinateResolver;
        $pin = $resolver->resolve([
            'rajaongkir_destination_id' => '999999',
            'city' => 'UnknownPlace',
        ], fallbackPin: '-6.7366,108.5414');

        $this->assertSame('-6.7366,108.5414', $pin);
    }

    public function test_a_failed_geocode_is_cached_so_quotes_do_not_re_ask_nominatim(): void
    {
        Http::fake([
            'https://nominatim.openstreetmap.org/*' => Http::response([], 500),
        ]);

        $resolver = new DestinationCoordinateResolver;
        $address = [
            'destination_id' => '999998',
            'destination_driver' => 'areaship',
            'destination_label' => 'NOWHERE, NOWHERE, NOWHERE',
            'city' => 'Nowhere',
        ];

        $this->assertNull($resolver->resolve($address));
        $sent = count(Http::recorded());
        $this->assertGreaterThan(0, $sent);

        $this->assertNull($resolver->resolve($address));
        $this->assertSame('-6.7366,108.5414', $resolver->resolve($address, fallbackPin: '-6.7366,108.5414'));
        $this->assertCount($sent, Http::recorded(), 'A cached miss must not call Nominatim again.');
    }

    public function test_a_successful_geocode_is_cached_per_driver_and_destination(): void
    {
        Http::fake([
            'https://nominatim.openstreetmap.org/*' => Http::response([['lat' => '-6.7', 'lon' => '108.5']], 200),
        ]);

        $resolver = new DestinationCoordinateResolver;
        $address = ['destination_id' => '17245', 'destination_driver' => 'areaship', 'city' => 'Cirebon'];

        $this->assertSame('-6.7,108.5', $resolver->resolve($address));
        $this->assertSame('-6.7,108.5', $resolver->resolve($address));
        Http::assertSentCount(1);
    }
}
