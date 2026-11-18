<?php

declare(strict_types=1);

namespace Tests\Unit\Shipping;

use App\Shipping\AddressDestination;
use PHPUnit\Framework\TestCase;

final class AddressDestinationTest extends TestCase
{
    public function test_neutral_keys_win_over_legacy_keys(): void
    {
        $address = [
            'destination_id' => '17549',
            'destination_label' => 'KEBAYORAN LAMA',
            'pin_point' => '-6.2,106.7',
            'rajaongkir_destination_id' => '111',
            'rajaongkir_destination_label' => 'OLD',
            'rajaongkir_pin_point' => '0,0',
        ];

        $this->assertSame('17549', AddressDestination::id($address));
        $this->assertSame('KEBAYORAN LAMA', AddressDestination::label($address));
        $this->assertSame('-6.2,106.7', AddressDestination::pinPoint($address));
    }

    public function test_legacy_keys_are_read_as_a_fallback_and_imply_rajaongkir(): void
    {
        $address = [
            'rajaongkir_destination_id' => 17549,
            'rajaongkir_destination_label' => ' KEBAYORAN ',
            'rajaongkir_pin_point' => '-6.2,106.7',
        ];

        $this->assertSame('17549', AddressDestination::id($address));
        $this->assertSame('KEBAYORAN', AddressDestination::label($address));
        $this->assertSame('-6.2,106.7', AddressDestination::pinPoint($address));
        $this->assertSame('rajaongkir', AddressDestination::driver($address));
    }

    public function test_an_id_from_another_drivers_vocabulary_is_ignored(): void
    {
        $address = ['destination_id' => 'IDNP6', 'destination_driver' => 'biteship'];

        $this->assertSame('IDNP6', AddressDestination::id($address));
        $this->assertSame('IDNP6', AddressDestination::id($address, 'biteship'));
        $this->assertNull(AddressDestination::id($address, 'rajaongkir'));
        $this->assertNull(AddressDestination::id(['rajaongkir_destination_id' => '1'], 'biteship'));
        // Unknown owner: accepted for any driver.
        $this->assertSame('5', AddressDestination::id(['destination_id' => '5'], 'rajaongkir'));
    }

    public function test_from_input_maps_legacy_request_fields(): void
    {
        $this->assertSame([
            'destination_id' => '17547',
            'destination_label' => 'MELAWAI',
            'pin_point' => '-6.2,106.7',
            'destination_driver' => 'rajaongkir',
        ], AddressDestination::fromInput([
            'rajaongkir_destination_id' => 17547,
            'rajaongkir_destination_label' => 'MELAWAI',
            'rajaongkir_pin_point' => '-6.2,106.7',
        ]));

        $this->assertSame(
            ['destination_id' => 'A1'],
            AddressDestination::fromInput(['destination_id' => 'A1', 'destination_label' => '  ']),
        );
        $this->assertSame([], AddressDestination::fromInput(['destination_id' => '', 'rajaongkir_destination_id' => null]));
    }

    public function test_normalize_drops_legacy_keys_and_stamps_the_default_driver(): void
    {
        $normalized = AddressDestination::normalize([
            'city' => 'Jakarta',
            'destination_id' => '17547',
            'rajaongkir_destination_label' => 'MELAWAI',
        ], 'rajaongkir');

        $this->assertSame([
            'city' => 'Jakarta',
            'destination_id' => '17547',
            'destination_label' => 'MELAWAI',
            'destination_driver' => 'rajaongkir',
        ], $normalized);

        $this->assertArrayNotHasKey(
            'destination_driver',
            AddressDestination::normalize(['destination_driver' => 'rajaongkir', 'pin_point' => '1,2']),
        );
    }

    public function test_with_legacy_keys_emits_both_spellings(): void
    {
        $payload = AddressDestination::withLegacyKeys([
            'destination_id' => '17547',
            'destination_label' => 'MELAWAI',
            'destination_driver' => 'rajaongkir',
            'pin_point' => null,
        ]);

        $this->assertSame('17547', $payload['destination_id']);
        $this->assertSame('17547', $payload['rajaongkir_destination_id']);
        $this->assertSame('MELAWAI', $payload['rajaongkir_destination_label']);
        $this->assertNull($payload['rajaongkir_pin_point']);
        $this->assertSame('rajaongkir', $payload['destination_driver']);
    }
}
