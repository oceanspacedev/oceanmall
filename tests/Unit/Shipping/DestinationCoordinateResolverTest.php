<?php

declare(strict_types=1);

namespace Tests\Unit\Shipping;

use App\Services\Shipping\DestinationCoordinateResolver;
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
}
