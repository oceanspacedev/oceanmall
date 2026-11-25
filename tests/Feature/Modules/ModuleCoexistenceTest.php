<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Integrations\KiriminAja\Services\KiriminAjaTracker;
use App\Integrations\Komerce\Actions\Shipping\ApplyDeliveryWebhookStatus;
use App\Integrations\Komerce\Actions\Shipping\CancelKomerceDelivery;
use App\Integrations\Komerce\Support\KomerceShipments;
use App\Models\OrderShipment;
use App\Payment\PaymentActions;
use App\Shipping\SelectedRate;
use App\Shipping\ShippingCapabilities;
use App\Support\CarrierLogos;
use Database\Seeders\CarrierSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Payment\Enum\TransactionStatus;
use Shopper\Payment\Enum\TransactionType;
use Shopper\Payment\Facades\Payment;
use Shopper\Payment\Models\PaymentTransaction;
use Shopper\Shipping\Facades\Shipping;
use Tests\Support\FulfilmentSafetyFixtures;
use Tests\TestCase;

/**
 * Every provider module can be installed and keyed at the same time (Komerce,
 * Xendit, Midtrans for payment; Komerce/RajaOngkir, Biteship, KiriminAja for
 * shipping; only one per section SELLS, see SingleActiveProviderTest, and a
 * store that moved from one to another holds orders of both) without one
 * touching what another owns: distinct driver codes,
 * seeded rows, webhook URLs and config namespaces, and module code that only
 * acts on its own payments and shipments even though the provider-neutral
 * columns (external_reference, awb) hold every provider's ids.
 */
final class ModuleCoexistenceTest extends TestCase
{
    use FulfilmentSafetyFixtures;
    use RefreshDatabase;

    private const PAYMENT_MODULES = ['Komerce' => ['komerce'], 'Xendit' => ['xendit'], 'Midtrans' => ['midtrans']];

    private const SHIPPING_MODULES = ['Komerce' => ['rajaongkir', 'komerce'], 'Biteship' => ['biteship'], 'KiriminAja' => ['kiriminaja']];

    protected function setUp(): void
    {
        parent::setUp();
        $this->startFulfilmentSafety();
    }

    protected function tearDown(): void
    {
        $this->endFulfilmentSafety();
        parent::tearDown();
    }

    public function test_all_modules_register_distinct_driver_codes(): void
    {
        $this->requireModules('Komerce', 'Xendit', 'Midtrans', 'Biteship', 'KiriminAja');

        $payment = array_merge(...array_values(self::PAYMENT_MODULES));
        $shipping = array_merge(...array_values(self::SHIPPING_MODULES));

        $this->assertSame($payment, array_values(array_unique($payment)));
        $this->assertSame($shipping, array_values(array_unique($shipping)));
        $this->assertEmpty(array_diff($payment, Payment::availableDrivers()));
        $this->assertEmpty(array_diff($shipping, Shipping::availableDrivers()));

        // Each driver code resolves to its own module's class.
        foreach ([...self::PAYMENT_MODULES] as $module => $codes) {
            foreach ($codes as $code) {
                $this->assertStringStartsWith("App\\Integrations\\{$module}\\", Payment::driver($code)::class);
            }
        }

        foreach (self::SHIPPING_MODULES as $module => $codes) {
            foreach ($codes as $code) {
                $this->assertStringStartsWith("App\\Integrations\\{$module}\\", Shipping::driver($code)::class);
            }
        }
    }

    public function test_seeding_every_module_keeps_each_drivers_rows_apart(): void
    {
        $this->requireModules('Komerce', 'Xendit', 'Midtrans', 'Biteship', 'KiriminAja');

        // Twice: re-seeding must not move a row from one driver to another.
        foreach ([1, 2] as $_) {
            $this->seed(PaymentMethodSeeder::class);
            $this->seed(CarrierSeeder::class);
        }

        foreach (array_merge(...array_values(self::PAYMENT_MODULES)) as $driver) {
            $slugs = PaymentMethod::query()->where('driver', $driver)->pluck('slug');
            $this->assertNotEmpty($slugs, "No payment method seeded for {$driver}.");

            foreach ($slugs as $slug) {
                $this->assertStringStartsWith("{$driver}-", (string) $slug, "{$driver} seeded an unprefixed payment slug.");
            }
        }

        $owners = [];

        foreach (['rajaongkir', 'biteship', 'kiriminaja'] as $driver) {
            $carriers = Carrier::query()->where('driver', $driver)->get();
            $this->assertNotEmpty($carriers, "No carrier seeded for {$driver}.");

            foreach ($carriers as $carrier) {
                $this->assertArrayNotHasKey($carrier->slug, $owners, "Carrier slug {$carrier->slug} is shared by {$driver} and ".($owners[$carrier->slug] ?? ''));
                $owners[$carrier->slug] = $driver;
            }
        }

        // The same courier offered by several providers stays one carrier per provider.
        $jne = Carrier::query()->where('metadata->courier_code', 'jne')->pluck('driver')->sort()->values()->all();
        $this->assertSame(['biteship', 'kiriminaja', 'rajaongkir'], $jne);
    }

    public function test_every_module_webhook_has_its_own_url(): void
    {
        $this->requireModules('Komerce', 'Xendit', 'Midtrans', 'Biteship', 'KiriminAja');

        $uris = [];

        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'webhooks/')) {
                $uris[] = $route->uri();
            }
        }

        $this->assertSame($uris, array_values(array_unique($uris)));

        foreach (['webhooks/komerce/payment', 'webhooks/komerce/delivery', 'webhooks/xendit/invoice', 'webhooks/midtrans/notification', 'webhooks/biteship', 'webhooks/kiriminaja'] as $uri) {
            $this->assertContains($uri, $uris);
        }
    }

    public function test_komerce_only_owns_its_own_and_legacy_shipments(): void
    {
        $this->requireModules('Komerce');

        [, $shipment] = $this->paidOrderShipment(['driver' => 'biteship']);
        $this->assertFalse(KomerceShipments::owns($shipment));

        foreach (['komerce', 'rajaongkir', null, ''] as $driver) {
            $shipment->driver = $driver;
            $this->assertTrue(KomerceShipments::owns($shipment), 'driver '.var_export($driver, true));
        }
    }

    public function test_a_komerce_delivery_webhook_never_updates_another_providers_shipment_by_awb(): void
    {
        $this->requireModules('Komerce', 'Biteship');

        [, $shipment] = $this->paidOrderShipment([
            'driver' => 'biteship',
            'external_reference' => 'BITESHIP-ORDER-1',
            'awb' => 'JNE0001',
            'tracking_number' => 'JNE0001',
            'status' => 'labeled',
        ]);

        $result = resolve(ApplyDeliveryWebhookStatus::class)->handle('KOM-UNKNOWN', 'JNE0001', 'DELIVERED');

        $this->assertSame('not_found', $result);
        Http::assertNothingSent();
        $this->assertSame('labeled', $shipment->refresh()->status);
        $this->assertNull(data_get($shipment->metadata, 'komerce'));
    }

    public function test_komerce_never_cancels_another_providers_shipment(): void
    {
        $this->requireModules('Komerce', 'KiriminAja');

        [, $shipment] = $this->paidOrderShipment([
            'driver' => 'kiriminaja',
            'external_reference' => 'KA-ORDER-1',
            'status' => 'pending',
        ]);

        resolve(CancelKomerceDelivery::class)->cancelShipment($shipment);

        Http::assertNothingSent();
        $this->assertSame('pending', $shipment->refresh()->status);
        $this->assertNull(data_get($shipment->metadata, 'komerce'));
    }

    public function test_a_legacy_row_another_provider_registered_is_not_komerces(): void
    {
        $this->requireModules('Komerce');

        [, $foreign] = $this->paidOrderShipment(['driver' => null, 'external_reference' => 'KA-ORDER-9']);
        $legacy = $this->sibling($foreign, ['driver' => null, 'external_reference' => 'KOM-1', 'metadata' => ['komerce' => ['order_no' => 'KOM-1']]]);
        $fresh = $this->sibling($foreign, ['driver' => null]);

        $this->assertTrue(KomerceShipments::registeredElsewhere($foreign));
        $this->assertFalse(KomerceShipments::owns($foreign));
        $this->assertTrue(KomerceShipments::owns($legacy));
        $this->assertTrue(KomerceShipments::owns($fresh));

        $scoped = KomerceShipments::scope(OrderShipment::query())->pluck('id')->sort()->values()->all();
        $this->assertSame([$legacy->id, $fresh->id], $scoped);

        // Komerce metadata written onto it never rewrites the other provider's reference.
        $foreign->forceFill(['metadata' => ['komerce' => ['order_no' => 'KOM-2', 'tracking_history' => [['desc' => 'x']]]]])->save();
        $this->assertSame('KA-ORDER-9', $foreign->refresh()->external_reference);
        $this->assertNull($foreign->driver);
    }

    public function test_payment_actions_follow_the_driver_that_issued_the_payment(): void
    {
        $this->requireModules('Komerce', 'Midtrans');

        [$order] = $this->paidOrderShipment();
        $method = PaymentMethod::query()->create(['title' => 'QRIS', 'slug' => 'qris-switch', 'driver' => 'komerce', 'is_enabled' => true]);
        $order->forceFill(['payment_method_id' => $method->id])->save();

        PaymentTransaction::query()->create([
            'order_id' => $order->id,
            'driver' => 'komerce',
            'type' => TransactionType::Initiate,
            'status' => TransactionStatus::Pending,
            'amount' => 112000,
            'currency_code' => 'IDR',
            'reference' => 'KOM-PAY-1',
        ]);

        // Staff move the method to Midtrans in cpanel: the open Komerce payment stays Komerce's.
        $method->forceFill(['driver' => 'midtrans'])->save();

        $this->assertSame('komerce', resolve(PaymentActions::class)->driverCode($order->refresh()));
    }

    public function test_a_switched_off_providers_shipment_is_never_tracked_by_another_module(): void
    {
        $this->requireModules('Komerce', 'Biteship');

        config()->set('komerce.shipping_cost_api_key', 'cost-key');
        $capabilities = resolve(ShippingCapabilities::class);

        [, $biteship] = $this->paidOrderShipment(['driver' => 'biteship', 'external_reference' => 'BS-1', 'awb' => 'JNE0001']);
        $manual = $this->sibling($biteship, ['driver' => 'manual', 'external_reference' => null, 'awb' => 'JNE0002']);
        $komerce = $this->sibling($biteship, ['driver' => 'komerce', 'external_reference' => null, 'awb' => 'JNE0003']);

        $this->assertSame($capabilities->providerFamily('rajaongkir'), $capabilities->providerFamily('komerce'));
        $this->assertNotSame($capabilities->providerFamily('rajaongkir'), $capabilities->providerFamily('biteship'));

        $this->assertNull($capabilities->trackerFor($biteship), 'Biteship is keyless: RajaOngkir must not track its parcel.');
        $this->assertSame('rajaongkir', $capabilities->trackerFor($manual)?->code(), 'A hand-typed AWB is tracked by any provider.');
        $this->assertSame('rajaongkir', $capabilities->trackerFor($komerce)?->code(), 'Same provider: Cost tracks a Delivery parcel.');
    }

    public function test_a_picked_option_is_found_by_its_key_and_echoed_back_by_it(): void
    {
        $rows = [
            ['service_code' => 'rajaongkir|jne:REG', 'provider_service_code' => 'jne:REG', 'driver' => 'rajaongkir', 'amount' => 18000],
            ['service_code' => 'kiriminaja|jne:REG', 'provider_service_code' => 'jne:REG', 'driver' => 'kiriminaja', 'amount' => 15000],
            ['service_code' => 'kiriminaja|sicepat:BEST', 'provider_service_code' => 'sicepat:BEST', 'driver' => 'kiriminaja', 'amount' => 21000],
            ['service_code' => 7, 'driver' => 'manual', 'amount' => 10000],
        ];

        $this->assertSame('kiriminaja', SelectedRate::pick($rows, 'kiriminaja|jne:REG')['driver']);
        $this->assertNull(SelectedRate::pick($rows, 'jne:REG'), 'A bare code two providers share is never guessed.');
        $this->assertSame('kiriminaja', SelectedRate::pick($rows, 'sicepat:BEST')['driver'], 'A bare code only one provider has still resolves.');
        $this->assertSame('manual', SelectedRate::pick($rows, '7')['driver']);

        $selected = SelectedRate::fromQuote($rows[1]);
        $this->assertSame('jne:REG', $selected['service_code']);
        $this->assertSame('kiriminaja|jne:REG', SelectedRate::optionKeyOf($selected));
        $this->assertSame(7, SelectedRate::optionKeyOf(SelectedRate::fromQuote($rows[3])));
        // A selection stored before option keys existed.
        $this->assertSame('jne:REG', SelectedRate::optionKeyOf(['id' => 'jne:REG', 'service_code' => 'jne:REG']));
    }

    public function test_local_logo_sources_of_every_module_win_over_a_provider_cdn(): void
    {
        $logos = new CarrierLogos;
        $logos->extend(static fn (string $slug): ?string => 'https://cdn.example.test/'.$slug.'.png', remote: true);
        $logos->extend(static fn (string $slug): ?string => $slug === 'zz-courier' ? '/images/couriers/zz.svg' : null);

        $this->assertSame('/images/couriers/zz.svg', $logos->forCode('zz-courier'));
        $this->assertSame('https://cdn.example.test/zz-other.png', $logos->forCode('zz-other'));
    }

    public function test_kiriminaja_tracks_another_providers_shipment_by_awb_only(): void
    {
        $this->requireModules('KiriminAja');

        [, $foreign] = $this->paidOrderShipment([
            'driver' => 'biteship',
            'external_reference' => 'BITESHIP-ORDER-1',
            'awb' => 'JNE0001',
        ]);

        $this->assertNull(KiriminAjaTracker::reference($foreign));
        $this->assertSame('JNE0001', KiriminAjaTracker::identifier($foreign));

        $foreign->driver = 'kiriminaja';
        $this->assertSame('BITESHIP-ORDER-1', KiriminAjaTracker::identifier($foreign));
    }

    /**
     * Another shipment of the same order (the fixture product is created once).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function sibling(OrderShipment $shipment, array $attributes): OrderShipment
    {
        return OrderShipment::query()->create([
            ...$shipment->only(['order_id', 'inventory_id', 'carrier_code', 'carrier_name', 'service_code', 'service_name', 'cost', 'currency_code']),
            'status' => 'pending',
            ...$attributes,
        ])->refresh();
    }
}
