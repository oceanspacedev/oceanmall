<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Models\OrderShipment;
use App\Models\User;
use App\Shipping\AddressDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Shopper\Core\Enum\AddressType;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Enum\ShippingStatus;
use Shopper\Core\Models\Address;
use Shopper\Core\Models\Country;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Tests\TestCase;

final class AddressDestinationBookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $manifest = public_path('build/manifest.json');
        $this->withHeader(
            'X-Inertia-Version',
            file_exists($manifest) ? hash_file('xxh128', $manifest) : '',
        );
    }

    public function test_address_index_clears_stale_driver_ids_in_memory_and_keeps_matching_ones(): void
    {
        $this->requireModules('Komerce');

        config()->set('komerce.enabled', true);
        config()->set('komerce.shipping_cost_api_key', 'test-key');
        config()->set('shopper.shipping.drivers.rajaongkir.enabled', true);
        config()->set('shopper.shipping.provider', null);

        $user = User::factory()->create();
        $country = Country::factory()->create(['cca2' => 'ID']);

        $matching = Address::query()->create([
            'user_id' => $user->id,
            'country_id' => $country->id,
            'first_name' => 'Budi',
            'last_name' => 'Sesuai',
            'street_address' => 'Jl. Sudirman 1',
            'city' => 'Jakarta Selatan',
            'postal_code' => '12190',
            'state' => 'DKI Jakarta',
            'phone_number' => '081234567890',
            'type' => AddressType::Shipping,
            'metadata' => json_encode([
                AddressDestination::ID => '152',
                AddressDestination::LABEL => 'Senayan, Kebayoran Baru',
                AddressDestination::DRIVER => 'rajaongkir',
            ]),
        ]);

        $stale = Address::query()->create([
            'user_id' => $user->id,
            'country_id' => $country->id,
            'first_name' => 'Siti',
            'last_name' => 'Stale',
            'street_address' => 'Jl. Thamrin 2',
            'city' => 'Jakarta Pusat',
            'postal_code' => '10350',
            'state' => 'DKI Jakarta',
            'phone_number' => '081234567891',
            'type' => AddressType::Shipping,
            'metadata' => json_encode([
                AddressDestination::ID => '999',
                AddressDestination::LABEL => 'Menteng',
                AddressDestination::DRIVER => 'other_provider',
            ]),
        ]);

        $this->actingAs($user)
            ->get(route('account.addresses'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('account/addresses')
                ->where('addresses.0.metadata.destination_id', '152')
                ->where('addresses.0.metadata.destination_driver', 'rajaongkir')
                ->where('addresses.1.metadata.destination_id', null)
                ->where('addresses.1.metadata.destination_label', null)
                ->missing('addresses.1.metadata.destination_driver'),
            );

        // Database row is untouched on GET.
        $staleMeta = json_decode((string) $stale->fresh()->metadata, true);
        $this->assertSame('999', $staleMeta[AddressDestination::ID]);
        $this->assertSame('other_provider', $staleMeta[AddressDestination::DRIVER]);
        $this->assertNotNull($matching->fresh());
    }

    public function test_address_update_replaces_stale_destination_driver_when_destination_id_changes(): void
    {
        $this->requireModules('Komerce');

        config()->set('komerce.enabled', true);
        config()->set('komerce.shipping_cost_api_key', 'test-key');
        config()->set('shopper.shipping.drivers.rajaongkir.enabled', true);
        config()->set('shopper.shipping.provider', null);

        $user = User::factory()->create();
        $country = Country::factory()->create(['cca2' => 'ID']);

        $address = Address::query()->create([
            'user_id' => $user->id,
            'country_id' => $country->id,
            'first_name' => 'Siti',
            'last_name' => 'Stale',
            'street_address' => 'Jl. Thamrin 2',
            'city' => 'Jakarta Pusat',
            'postal_code' => '10350',
            'state' => 'DKI Jakarta',
            'phone_number' => '081234567891',
            'type' => AddressType::Shipping,
            'metadata' => json_encode([
                AddressDestination::ID => '999',
                AddressDestination::LABEL => 'Menteng',
                AddressDestination::DRIVER => 'old_driver',
            ]),
        ]);

        $this->actingAs($user)
            ->patch(route('account.addresses.update', $address), [
                'first_name' => 'Siti',
                'last_name' => 'Updated',
                'street_address' => 'Jl. Thamrin 2',
                'city' => 'Jakarta Selatan',
                'postal_code' => '12190',
                'state' => 'DKI Jakarta',
                'phone_number' => '081234567891',
                'country_id' => $country->id,
                'type' => AddressType::Shipping->value,
                'destination_id' => '152',
                'destination_label' => 'Senayan, Kebayoran Baru',
            ])
            ->assertRedirect(route('account.addresses'));

        $updatedMeta = json_decode((string) $address->fresh()->metadata, true);
        $this->assertSame('152', $updatedMeta[AddressDestination::ID]);
        $this->assertSame('rajaongkir', $updatedMeta[AddressDestination::DRIVER]);
    }

    public function test_address_update_re_stamps_destination_driver_on_same_id_collision_across_provider_switch(): void
    {
        $this->requireModules('Komerce');

        config()->set('komerce.enabled', true);
        config()->set('komerce.shipping_cost_api_key', '');
        config()->set('komerce.shipping_delivery_api_key', 'deliv-key');
        config()->set('shopper.shipping.drivers.rajaongkir.enabled', false);
        config()->set('shopper.shipping.drivers.komerce.enabled', true);
        config()->set('shopper.shipping.provider', 'komerce');

        $user = User::factory()->create();
        $country = Country::factory()->create(['cca2' => 'ID']);

        $address = Address::query()->create([
            'user_id' => $user->id,
            'country_id' => $country->id,
            'first_name' => 'Siti',
            'last_name' => 'Collision',
            'street_address' => 'Jl. Thamrin 2',
            'city' => 'Jakarta Pusat',
            'postal_code' => '10350',
            'state' => 'DKI Jakarta',
            'phone_number' => '081234567891',
            'type' => AddressType::Shipping,
            'metadata' => json_encode([
                AddressDestination::ID => '123',
                AddressDestination::LABEL => 'Old RajaOngkir',
                AddressDestination::DRIVER => 'rajaongkir',
            ]),
        ]);

        $this->actingAs($user)
            ->patch(route('account.addresses.update', $address), [
                'first_name' => 'Siti',
                'last_name' => 'Collision',
                'street_address' => 'Jl. Thamrin 2',
                'city' => 'Jakarta Pusat',
                'postal_code' => '10350',
                'state' => 'DKI Jakarta',
                'phone_number' => '081234567891',
                'country_id' => $country->id,
                'type' => AddressType::Shipping->value,
                'destination_id' => '123',
                'destination_label' => 'New Komerce',
            ])
            ->assertRedirect(route('account.addresses'));

        $updatedMeta = json_decode((string) $address->fresh()->metadata, true);
        $this->assertSame('123', $updatedMeta[AddressDestination::ID]);
        $this->assertSame('New Komerce', $updatedMeta[AddressDestination::LABEL]);
        $this->assertSame('komerce', $updatedMeta[AddressDestination::DRIVER]);

        $this->actingAs($user)
            ->get(route('account.addresses'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('account/addresses')
                ->where('addresses.0.metadata.destination_id', '123')
                ->where('addresses.0.metadata.destination_label', 'New Komerce')
                ->where('addresses.0.metadata.destination_driver', 'komerce'),
            );
    }

    public function test_address_update_with_empty_destination_preserves_existing_driver_and_null_driver_clears_stale_driver(): void
    {
        $this->requireModules('Komerce');

        config()->set('komerce.enabled', true);
        config()->set('komerce.shipping_cost_api_key', '');
        config()->set('komerce.shipping_delivery_api_key', 'deliv-key');
        config()->set('shopper.shipping.drivers.rajaongkir.enabled', false);
        config()->set('shopper.shipping.drivers.komerce.enabled', true);
        config()->set('shopper.shipping.provider', 'komerce');

        $user = User::factory()->create();
        $country = Country::factory()->create(['cca2' => 'ID']);

        $address = Address::query()->create([
            'user_id' => $user->id,
            'country_id' => $country->id,
            'first_name' => 'Siti',
            'last_name' => 'Preserved',
            'street_address' => 'Jl. Thamrin 2',
            'city' => 'Jakarta Pusat',
            'postal_code' => '10350',
            'state' => 'DKI Jakarta',
            'phone_number' => '081234567891',
            'type' => AddressType::Shipping,
            'metadata' => json_encode([
                AddressDestination::ID => '123',
                AddressDestination::LABEL => 'Old RajaOngkir',
                AddressDestination::DRIVER => 'rajaongkir',
            ]),
        ]);

        // Editing non-destination fields with destination_id omitted preserves existing destination and driver.
        $this->actingAs($user)
            ->patch(route('account.addresses.update', $address), [
                'first_name' => 'Siti',
                'last_name' => 'Preserved',
                'street_address' => 'Jl. Thamrin 99',
                'city' => 'Jakarta Pusat',
                'postal_code' => '10350',
                'state' => 'DKI Jakarta',
                'phone_number' => '081234567891',
                'country_id' => $country->id,
                'type' => AddressType::Shipping->value,
            ])
            ->assertRedirect(route('account.addresses'));

        $preservedMeta = json_decode((string) $address->fresh()->metadata, true);
        $this->assertSame('123', $preservedMeta[AddressDestination::ID]);
        $this->assertSame('rajaongkir', $preservedMeta[AddressDestination::DRIVER]);

        // Submitting empty destination_id clears destination metadata even when region is unchanged.
        $this->actingAs($user)
            ->patch(route('account.addresses.update', $address), [
                'first_name' => 'Siti',
                'last_name' => 'Preserved',
                'street_address' => 'Jl. Thamrin 99',
                'city' => 'Jakarta Pusat',
                'postal_code' => '10350',
                'state' => 'DKI Jakarta',
                'phone_number' => '081234567891',
                'country_id' => $country->id,
                'type' => AddressType::Shipping->value,
                'destination_id' => '',
                'destination_label' => '',
            ])
            ->assertRedirect(route('account.addresses'));

        $this->assertNull($address->fresh()->metadata);

        // Submitting a non-empty destination_id when active destination driver is null unsets stale driver.
        config()->set('komerce.enabled', false);
        config()->set('komerce.shipping_delivery_api_key', '');
        config()->set('shopper.shipping.drivers.komerce.enabled', false);
        config()->set('shopper.shipping.provider', 'none');

        $this->actingAs($user)
            ->patch(route('account.addresses.update', $address), [
                'first_name' => 'Siti',
                'last_name' => 'Preserved',
                'street_address' => 'Jl. Thamrin 99',
                'city' => 'Jakarta Pusat',
                'postal_code' => '10350',
                'state' => 'DKI Jakarta',
                'phone_number' => '081234567891',
                'country_id' => $country->id,
                'type' => AddressType::Shipping->value,
                'destination_id' => '123',
                'destination_label' => 'Manual Destination',
            ])
            ->assertRedirect(route('account.addresses'));

        $clearedDriverMeta = json_decode((string) $address->fresh()->metadata, true);
        $this->assertSame('123', $clearedDriverMeta[AddressDestination::ID]);
        $this->assertArrayNotHasKey(AddressDestination::DRIVER, $clearedDriverMeta);
    }

    public function test_account_order_show_requires_non_empty_awb_for_can_track(): void
    {
        $this->requireModules('Komerce');

        Http::preventStrayRequests();
        Http::fake();

        config()->set('komerce.enabled', true);
        config()->set('komerce.shipping_cost_api_key', 'test-key');
        config()->set('shopper.shipping.drivers.rajaongkir.enabled', true);
        config()->set('shopper.shipping.provider', null);

        $customer = User::factory()->create();
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
        ]);
        $inventory = Inventory::factory()->create([
            'name' => 'Gudang Utama',
            'rajaongkir_origin_id' => '17248',
        ]);

        OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inventory->id,
            'driver' => 'rajaongkir',
            'carrier_code' => 'jne',
            'carrier_name' => 'JNE',
            'service_code' => 'REG',
            'service_name' => 'Regular',
            'cost' => 15000,
            'currency_code' => 'IDR',
            'status' => 'pending',
            'awb' => null,
            'tracking_number' => null,
        ]);

        OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inventory->id,
            'driver' => 'rajaongkir',
            'carrier_code' => 'jne',
            'carrier_name' => 'JNE',
            'service_code' => 'REG',
            'service_name' => 'Regular',
            'cost' => 15000,
            'currency_code' => 'IDR',
            'status' => 'shipped',
            'awb' => 'JNE-TRACK-123',
            'tracking_number' => 'JNE-TRACK-123',
        ]);

        $this->actingAs($customer)
            ->get(route('account.orders.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('account/order-show')
                ->where('shipments.0.can_track', false)
                ->where('shipments.1.can_track', true),
            );
    }

    public function test_web_vue_sources_include_m4_address_and_order_show_parity_guards(): void
    {
        $addressesVue = file_get_contents(resource_path('js/pages/account/addresses.vue'));
        $orderShowVue = file_get_contents(resource_path('js/pages/account/order-show.vue'));

        $this->assertIsString($addressesVue);
        $this->assertStringContainsString('opt.id ?? opt.destination_id', $addressesVue);
        $this->assertStringContainsString('opt.city ?? opt.city_name', $addressesVue);
        $this->assertStringContainsString('destinationSearchError', $addressesVue);
        $this->assertStringContainsString('if (!res.ok)', $addressesVue);

        $this->assertIsString($orderShowVue);
        $this->assertStringContainsString('paymentError && !(pendingPayment || canRetryPayment)', $orderShowVue);
        $this->assertStringContainsString('paymentNeedsRenewal', $orderShowVue);
        $this->assertStringContainsString('canRetryPayment && paymentNeedsRenewal', $orderShowVue);
    }
}
