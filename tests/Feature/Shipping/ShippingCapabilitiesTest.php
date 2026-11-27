<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Integrations\Komerce\Payment\KomerceDriver;
use App\Integrations\Komerce\Shipping\KomerceShippingDriver;
use App\Integrations\Komerce\Shipping\RajaOngkirDriver;
use App\Models\OrderShipment;
use App\Payment\Contracts\ExpiresPayments;
use App\Payment\Contracts\ProvidesPaymentCatalog;
use App\Payment\Contracts\ReconcilesPayments;
use App\Shipping\Contracts\CancelsShipments;
use App\Shipping\Contracts\FulfillsShipments;
use App\Shipping\Contracts\PresentsCarriers;
use App\Shipping\Contracts\PrintsLabels;
use App\Shipping\Contracts\ReconcilesShipments;
use App\Shipping\Contracts\RequiresCoordinates;
use App\Shipping\Contracts\RequiresDestination;
use App\Shipping\Contracts\RequiresOriginId;
use App\Shipping\Contracts\ResolvesDestinations;
use App\Shipping\Contracts\SyncsCarriers;
use App\Shipping\Contracts\TracksShipments;
use App\Shipping\Contracts\ValidatesOriginIds;
use App\Shipping\ShippingCapabilities;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Shopper\Core\Models\Inventory;
use Shopper\Payment\Facades\Payment;
use Shopper\Shipping\DataTransferObjects\Address;
use Shopper\Shipping\Drivers\Driver;
use Shopper\Shipping\Exceptions\ShippingException;
use Shopper\Shipping\Facades\Shipping;
use Tests\TestCase;

final class ShippingCapabilitiesTest extends TestCase
{
    public function test_without_provider_keys_no_capability_is_reported(): void
    {
        Http::fake();
        $capabilities = resolve(ShippingCapabilities::class);

        $this->assertTrue($capabilities->drivers()->isEmpty());
        $this->assertFalse($capabilities->destinationSearch());
        $this->assertFalse($capabilities->requiresDestination());
        $this->assertFalse($capabilities->requiresCoordinates());
        $this->assertNull($capabilities->destinationDriver());
        $this->assertNull($capabilities->originDriverCode());
        $this->assertSame(
            ['destinationSearch' => false, 'requiresDestination' => false, 'requiresCoordinates' => false],
            $capabilities->toInertia(),
        );
        $this->assertSame(
            ['destination_search' => false, 'requires_destination' => false, 'requires_coordinates' => false],
            $capabilities->toApi(),
        );
        Http::assertNothingSent();
    }

    public function test_rajaongkir_cost_key_enables_destination_search_and_requirement(): void
    {
        $this->requireModules('Komerce');

        config()->set('komerce.shipping_cost_api_key', 'cost-key');
        $capabilities = resolve(ShippingCapabilities::class);

        $this->assertSame(['rajaongkir'], $capabilities->drivers()->keys()->all());
        $this->assertTrue($capabilities->destinationSearch());
        $this->assertTrue($capabilities->requiresDestination());
        $this->assertFalse($capabilities->requiresCoordinates());
        $this->assertSame('rajaongkir', $capabilities->destinationDriverCode());
        $this->assertSame('rajaongkir', $capabilities->originDriverCode());
    }

    public function test_delivery_key_alone_needs_coordinates_and_enables_destination_search(): void
    {
        $this->requireModules('Komerce');

        config()->set('komerce.shipping_delivery_api_key', 'delivery-key');
        $capabilities = resolve(ShippingCapabilities::class);

        $this->assertSame(['komerce'], $capabilities->drivers()->keys()->all());
        $this->assertTrue($capabilities->destinationSearch());
        $this->assertTrue($capabilities->requiresDestination());
        $this->assertTrue($capabilities->requiresCoordinates());
        $this->assertSame('komerce', $capabilities->destinationDriverCode());
        $this->assertSame('komerce', $capabilities->originDriverCode());
    }

    public function test_komship_mode_deduplicates_origin_drivers_by_provider_family(): void
    {
        $this->requireModules('Komerce');

        config()->set('komerce.shipping_cost_api_key', 'cost-key');
        config()->set('komerce.shipping_delivery_api_key', 'delivery-key');
        $capabilities = resolve(ShippingCapabilities::class);

        $this->assertSame(['rajaongkir'], $capabilities->originDrivers()->keys()->all());
        $this->assertSame('rajaongkir', $capabilities->destinationDriverCode());
        $this->assertSame('rajaongkir', $capabilities->originDriverCode());
    }

    public function test_a_third_party_driver_plugs_in_through_shipping_extend(): void
    {
        Shipping::extend('fake-areas', static fn (): Driver => new FakeAreaDriver);
        config()->set('shopper.shipping.drivers.fake-areas.enabled', true);

        $capabilities = resolve(ShippingCapabilities::class);

        $this->assertSame('fake-areas', $capabilities->destinationDriverCode());
        $this->assertTrue($capabilities->requiresDestination());
        $this->assertSame([['id' => 'AREA-1', 'label' => 'jakarta']], $capabilities->destinationDriver()->searchDestinations('Jakarta'));

        $shipment = new OrderShipment(['driver' => 'fake-areas']);
        $this->assertInstanceOf(FakeAreaDriver::class, $capabilities->forShipment($shipment));
        $this->assertNull($capabilities->forShipment(new OrderShipment(['driver' => 'unknown-driver'])));
        $this->assertNull($capabilities->forShipment(new OrderShipment));
    }

    public function test_the_store_setting_picks_the_primary_destination_driver(): void
    {
        $this->requireModules('Komerce');

        config()->set('komerce.shipping_cost_api_key', 'cost-key');
        Shipping::extend('fake-areas', static fn (): Driver => new FakeAreaDriver);
        config()->set('shopper.shipping.drivers.fake-areas.enabled', true);

        $capabilities = resolve(ShippingCapabilities::class);

        // Default: the first configured provider sells, so its vocabulary is used.
        $this->assertSame('rajaongkir', $capabilities->destinationDriverCode());

        // The destination setting only chooses between drivers of the provider
        // the store sells through: another keyed provider is never picked by it.
        config()->set('shopper.shipping.destination_driver', ' fake-areas ');
        $this->assertSame('rajaongkir', $capabilities->destinationDriverCode());

        // Selling through the other provider moves the vocabulary with it.
        config()->set('shopper.shipping.provider', 'fake-areas');
        $this->assertSame('fake-areas', $capabilities->destinationDriverCode());
        $this->assertSame('fake-areas', $capabilities->originDriverCode());
        config()->set('shopper.shipping.provider', null);

        // A preferred driver that is not configured never removes the search.
        config()->set('shopper.shipping.destination_driver', 'not-configured');
        $this->assertSame('rajaongkir', $capabilities->destinationDriverCode());

        config()->set('shopper.shipping.destination_driver', 'fake-areas');
        config()->set('shopper.shipping.drivers.fake-areas.enabled', false);
        $this->assertSame('rajaongkir', $capabilities->destinationDriverCode());
    }

    public function test_existing_drivers_implement_their_capability_contracts(): void
    {
        $this->requireModules('Komerce');

        $rajaOngkir = Shipping::driver('rajaongkir');
        $this->assertInstanceOf(RajaOngkirDriver::class, $rajaOngkir);
        $this->assertInstanceOf(ResolvesDestinations::class, $rajaOngkir);
        $this->assertInstanceOf(RequiresDestination::class, $rajaOngkir);
        $this->assertInstanceOf(SyncsCarriers::class, $rajaOngkir);
        $this->assertNotInstanceOf(FulfillsShipments::class, $rajaOngkir);

        $komerce = Shipping::driver('komerce');
        $this->assertInstanceOf(KomerceShippingDriver::class, $komerce);
        foreach ([
            RequiresDestination::class,
            RequiresCoordinates::class,
            RequiresOriginId::class,
            ResolvesDestinations::class,
            ValidatesOriginIds::class,
            PresentsCarriers::class,
            SyncsCarriers::class,
            FulfillsShipments::class,
            PrintsLabels::class,
            CancelsShipments::class,
            ReconcilesShipments::class,
            TracksShipments::class,
        ] as $contract) {
            $this->assertInstanceOf($contract, $komerce);
        }

        $payment = Payment::driver('komerce');
        $this->assertInstanceOf(KomerceDriver::class, $payment);
        foreach ([ProvidesPaymentCatalog::class, ReconcilesPayments::class, ExpiresPayments::class] as $contract) {
            $this->assertInstanceOf($contract, $payment);
        }
    }

    public function test_rajaongkir_destination_search_is_cached_and_refuses_without_key(): void
    {
        $this->requireModules('Komerce');

        config()->set('komerce.shipping_cost_api_key', 'cost-key');
        config()->set('komerce.rajaongkir.cost_base_url', 'https://shipping.example.test');
        Http::fake([
            'https://shipping.example.test/api/v1/destination/domestic-destination*' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => [['id' => 17547, 'label' => 'MELAWAI, KEBAYORAN BARU']],
            ]),
        ]);

        $driver = Shipping::driver('rajaongkir');
        $first = $driver->searchDestinations('Melawai', 5);
        $second = $driver->searchDestinations('  melawai ', 5);

        $this->assertSame('17547', $first[0]['id']);
        $this->assertSame($first, $second);
        Http::assertSentCount(1);

        config()->set('komerce.shipping_cost_api_key', '');
        $this->expectException(ShippingException::class);
        $driver->searchDestinations('Melawai');
    }

    public function test_rajaongkir_sync_carriers_reports_unconfigured_without_calling_the_provider(): void
    {
        $this->requireModules('Komerce');

        Http::fake();

        $result = Shipping::driver('rajaongkir')->syncCarriers();

        $this->assertFalse($result->successful);
        Http::assertNothingSent();
    }
}

final class FakeAreaDriver extends Driver implements RequiresDestination, ResolvesDestinations
{
    public function code(): string
    {
        return 'fake-areas';
    }

    public function name(): string
    {
        return 'Fake areas';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function supportsRealTimeRates(): bool
    {
        return true;
    }

    public function supportsLabels(): bool
    {
        return false;
    }

    public function supportsTracking(): bool
    {
        return false;
    }

    public function calculateRates(Address $from, Address $to, array $packages): Collection
    {
        return collect();
    }

    public function searchDestinations(string $query, int $limit = 10): array
    {
        return [['id' => 'AREA-1', 'label' => strtolower($query)]];
    }

    public function resolveOrigin(Inventory $inventory): ?array
    {
        return null;
    }
}
