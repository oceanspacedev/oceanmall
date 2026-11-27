<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Models\User;
use App\Shipping\InventoryOrigins;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Shopper\Core\Enum\AddressType;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Enum\ShippingStatus;
use Shopper\Core\Models\Address;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Tests\TestCase;

/**
 * The provider-neutral migrations move data already in production; each test
 * puts rows back in their pre-migration shape and re-runs the migration.
 */
final class ProviderNeutralDataMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_origin_column_moves_to_the_provider_keyed_table(): void
    {
        $inventory = Inventory::factory()->create();
        $empty = Inventory::factory()->create();

        $migration = $this->migration('2026_11_18_000100_create_inventory_shipping_origins_table');
        $migration->down();
        $this->assertFalse(Schema::hasTable('inventory_shipping_origins'));

        DB::table(shopper_table('inventories'))->where('id', $inventory->id)->update(['rajaongkir_origin_id' => ' 17248 ']);
        DB::table(shopper_table('inventories'))->where('id', $empty->id)->update(['rajaongkir_origin_id' => '']);

        $migration->up();

        $this->assertDatabaseHas('inventory_shipping_origins', [
            'inventory_id' => $inventory->id,
            'driver' => 'rajaongkir',
            'external_id' => '17248',
        ]);
        $this->assertDatabaseMissing('inventory_shipping_origins', ['inventory_id' => $empty->id]);
    }

    public function test_inventory_origin_migration_is_expand_only_for_rolling_deploys(): void
    {
        $inventory = Inventory::factory()->create();
        $cleared = Inventory::factory()->create();
        $migration = $this->migration('2026_11_18_000100_create_inventory_shipping_origins_table');
        $migration->down();

        DB::table(shopper_table('inventories'))->where('id', $inventory->id)->update(['rajaongkir_origin_id' => '17248']);
        DB::table(shopper_table('inventories'))->where('id', $cleared->id)->update(['rajaongkir_origin_id' => '501']);

        $migration->up();

        // The previous release still reads and writes the legacy column.
        $this->assertTrue(Schema::hasColumn(shopper_table('inventories'), 'rajaongkir_origin_id'));
        $this->assertSame('17248', DB::table(shopper_table('inventories'))->where('id', $inventory->id)->value('rajaongkir_origin_id'));
        DB::table(shopper_table('inventories'))->where('id', $inventory->id)->update(['rajaongkir_origin_id' => '17249']);

        // The current code never writes the column and only uses the table.
        $inventory->refresh()->update(['shipping_origins' => ['rajaongkir' => '114']]);
        $cleared->refresh()->update(['shipping_origins' => ['rajaongkir' => null]]);
        $this->assertSame('114', resolve(InventoryOrigins::class)->get($inventory, 'rajaongkir'));
        $this->assertNull(resolve(InventoryOrigins::class)->get($cleared, 'rajaongkir'));
        $this->assertSame('17249', DB::table(shopper_table('inventories'))->where('id', $inventory->id)->value('rajaongkir_origin_id'));

        // Saving a loaded model never revives the column's stale value.
        $inventory->refresh()->update(['name' => 'Gudang Utama']);
        $cleared->refresh()->update(['name' => 'Gudang Cadangan']);
        $this->assertSame('114', resolve(InventoryOrigins::class)->get($inventory, 'rajaongkir'));
        $this->assertNull(resolve(InventoryOrigins::class)->get($cleared, 'rajaongkir'));

        // Rollback: the table is the source of truth, a cleared origin stays cleared.
        $migration->down();
        $this->assertSame('114', DB::table(shopper_table('inventories'))->where('id', $inventory->id)->value('rajaongkir_origin_id'));
        $this->assertNull(DB::table(shopper_table('inventories'))->where('id', $cleared->id)->value('rajaongkir_origin_id'));
        $migration->up();
        $this->assertSame('114', resolve(InventoryOrigins::class)->get($inventory, 'rajaongkir'));
    }

    public function test_order_shipments_get_driver_and_external_reference_backfilled(): void
    {
        $order = Order::factory()->create([
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
        ]);
        $inventory = Inventory::factory()->create();

        $registered = $this->rawShipment($order, $inventory, 'REG', ['komerce' => ['order_no' => 'KOM-1']]);
        $deliveryQuote = $this->rawShipment($order, $inventory, 'jne:REG', ['rate' => ['provider' => 'shipping_delivery']]);
        $costQuote = $this->rawShipment($order, $inventory, 'REG', ['rate' => ['carrier_code' => 'jne', 'amount' => 9000]]);
        $zoneOption = $this->rawShipment($order, $inventory, '12', ['rate' => ['amount' => 5000]]);
        $unknown = $this->rawShipment($order, $inventory, 'REG', null);
        $alreadySet = $this->rawShipment($order, $inventory, '12', ['rate' => ['amount' => 1]], 'custom');

        $this->migration('2026_11_18_000200_add_driver_and_external_reference_to_order_shipments_table')->up();

        $row = static fn (int $id): object => DB::table('order_shipments')->where('id', $id)->first();

        $this->assertSame('komerce', $row($registered)->driver);
        $this->assertSame('KOM-1', $row($registered)->external_reference);
        $this->assertSame('komerce', $row($deliveryQuote)->driver);
        $this->assertNull($row($deliveryQuote)->external_reference);
        $this->assertSame('rajaongkir', $row($costQuote)->driver);
        $this->assertSame('manual', $row($zoneOption)->driver);
        $this->assertNull($row($unknown)->driver);
        $this->assertSame('custom', $row($alreadySet)->driver);
    }

    public function test_address_metadata_moves_to_neutral_keys_and_back(): void
    {
        $user = User::factory()->create();
        $address = Address::query()->create([
            'user_id' => $user->id,
            'first_name' => 'Budi',
            'last_name' => 'Santoso',
            'street_address' => 'Jl. Melawai 1',
            'postal_code' => '12160',
            'city' => 'Jakarta Selatan',
            'type' => AddressType::Shipping,
            'metadata' => json_encode([
                'rajaongkir_destination_id' => '17547',
                'rajaongkir_destination_label' => 'MELAWAI',
                'rajaongkir_pin_point' => '-6.24,106.80',
                'note' => 'pagar hijau',
            ], JSON_THROW_ON_ERROR),
        ]);
        $order = Order::factory()->create([
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
        ]);
        DB::table(shopper_table('orders'))->where('id', $order->id)->update([
            'metadata' => json_encode([
                'komerce' => ['channel_code' => 'BCA'],
                'shipping_address' => ['city' => 'Jakarta', 'rajaongkir_destination_id' => '152'],
            ], JSON_THROW_ON_ERROR),
        ]);

        $migration = $this->migration('2026_11_18_000300_move_address_destination_metadata_to_neutral_keys');
        $migration->up();

        $addressMeta = json_decode((string) DB::table(shopper_table('user_addresses'))->where('id', $address->id)->value('metadata'), true);
        // Copied, not moved: an instance of the previous release still reads the legacy keys.
        $this->assertSame([
            'rajaongkir_destination_id' => '17547',
            'rajaongkir_destination_label' => 'MELAWAI',
            'rajaongkir_pin_point' => '-6.24,106.80',
            'note' => 'pagar hijau',
            'destination_id' => '17547',
            'destination_label' => 'MELAWAI',
            'pin_point' => '-6.24,106.80',
            'destination_driver' => 'rajaongkir',
        ], $addressMeta);

        $orderMeta = json_decode((string) DB::table(shopper_table('orders'))->where('id', $order->id)->value('metadata'), true);
        $this->assertSame(['channel_code' => 'BCA'], $orderMeta['komerce']);
        $this->assertSame(
            ['city' => 'Jakarta', 'rajaongkir_destination_id' => '152', 'destination_id' => '152', 'destination_driver' => 'rajaongkir'],
            $orderMeta['shipping_address'],
        );

        $migration->down();
        $addressMeta = json_decode((string) DB::table(shopper_table('user_addresses'))->where('id', $address->id)->value('metadata'), true);
        $this->assertSame('17547', $addressMeta['rajaongkir_destination_id']);
        $this->assertArrayNotHasKey('destination_id', $addressMeta);
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function rawShipment(Order $order, Inventory $inventory, string $serviceCode, ?array $metadata, ?string $driver = null): int
    {
        return (int) DB::table('order_shipments')->insertGetId([
            'order_id' => $order->id,
            'inventory_id' => $inventory->id,
            'driver' => $driver,
            'service_code' => $serviceCode,
            'metadata' => $metadata === null ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function migration(string $name): object
    {
        return require database_path("migrations/{$name}.php");
    }
}
