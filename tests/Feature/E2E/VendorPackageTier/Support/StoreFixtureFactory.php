<?php

declare(strict_types=1);

namespace Tests\Feature\E2E\VendorPackageTier\Support;

use App\Actions\GetCountriesByZone;
use App\Models\OrderShipment;
use App\Models\OrderShipmentLine;
use App\Models\Price;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\GranularPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Shopper\Core\Enum\AddressType;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Enum\ShippingStatus;
use Shopper\Core\Models\Address;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\CarrierOption;
use Shopper\Core\Models\Country;
use Shopper\Core\Models\Currency;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\OrderAddress;
use Shopper\Core\Models\OrderItem;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Core\Models\Setting;
use Shopper\Core\Models\Zone;
use Shopper\Database\Seeders\PermissionRoleTableSeeder;
use Shopper\Database\Seeders\PermissionsTableSeeder;
use Shopper\Payment\Enum\TransactionStatus;
use Shopper\Payment\Enum\TransactionType;
use Shopper\Payment\Models\PaymentTransaction;
use Spatie\Permission\Models\Role;

/**
 * Reusable domain fixture builders for E2E vendor package-tier tests.
 */
final class StoreFixtureFactory
{
    public Currency $currency;

    public Country $country;

    public Zone $zone;

    public Inventory $inventory;

    public ?Inventory $secondInventory = null;

    /** @var array<string, Carrier> */
    public array $carriers = [];

    public ?CarrierOption $manualOption = null;

    /** @var array<string, PaymentMethod> */
    public array $paymentMethods = [];

    public static function seedBaseStore(bool $withManualCarrier = true, bool $withSecondInventory = false): self
    {
        $factory = new self;

        $factory->currency = Currency::query()->where('code', 'IDR')->first()
            ?? Currency::factory()->create([
                'code' => 'IDR',
                'name' => 'Indonesian Rupiah',
                'symbol' => 'Rp',
                'format' => 'Rp1.234',
            ]);

        $factory->country = Country::query()->where('cca2', 'ID')->first()
            ?? Country::factory()->create(['cca2' => 'ID', 'name' => 'Indonesia']);

        $factory->zone = Zone::factory()->create([
            'is_enabled' => true,
            'currency_id' => $factory->currency->id,
        ]);
        $factory->zone->countries()->attach($factory->country->id);

        $factory->inventory = Inventory::query()->where('email', 'gudang@oceanmall.test')->first()
            ?? Inventory::factory()->create([
                'name' => 'Gudang Cirebon',
                'is_default' => true,
                'country_id' => $factory->country->id,
                'latitude' => -6.7366,
                'longitude' => 108.5414,
                'email' => 'gudang@oceanmall.test',
                'phone_number' => '02311234567',
                'street_address' => 'Jl. Gudang 10',
                'city' => 'Cirebon',
                'postal_code' => '45111',
                'rajaongkir_origin_id' => '17248',
            ]);

        if ($withSecondInventory) {
            $factory->ensureSecondInventory();
        }

        foreach ([
            'jne' => 'JNE Express',
            'jnt' => 'J&T Express',
            'spx' => 'SPX Express',
            'ide' => 'ID Express',
            'pos' => 'POS Indonesia',
        ] as $code => $name) {
            $carrier = Carrier::query()->create([
                'slug' => $code,
                'name' => $name,
                'driver' => 'rajaongkir',
                'is_enabled' => true,
                'metadata' => ['courier_code' => $code],
            ]);
            $factory->zone->carriers()->attach($carrier->id);
            $factory->carriers[$code] = $carrier;
        }

        if ($withManualCarrier) {
            $manual = Carrier::query()->create([
                'slug' => 'manual',
                'name' => 'Kurir Toko',
                'driver' => 'manual',
                'is_enabled' => true,
            ]);
            $factory->zone->carriers()->attach($manual->id);
            $factory->carriers['manual'] = $manual;

            $factory->manualOption = CarrierOption::query()->create([
                'carrier_id' => $manual->id,
                'zone_id' => $factory->zone->id,
                'name' => 'Reguler',
                'description' => 'Pengiriman reguler toko',
                'price' => 15000,
                'is_enabled' => true,
            ]);
        }

        $factory->paymentMethods['bca'] = PaymentMethod::factory()->create([
            'slug' => 'komerce-va-bca',
            'title' => 'BCA Virtual Account',
            'driver' => 'komerce',
            'is_enabled' => true,
            'metadata' => json_encode(['payment_type' => 'bank_transfer', 'channel_code' => 'BCA'], JSON_THROW_ON_ERROR),
        ]);

        $factory->paymentMethods['bri'] = PaymentMethod::factory()->create([
            'slug' => 'komerce-va-bri',
            'title' => 'BRI Virtual Account',
            'driver' => 'komerce',
            'is_enabled' => true,
            'metadata' => json_encode(['payment_type' => 'bank_transfer', 'channel_code' => 'BRI'], JSON_THROW_ON_ERROR),
        ]);

        $factory->paymentMethods['qris'] = PaymentMethod::factory()->create([
            'slug' => 'komerce-qris',
            'title' => 'QRIS',
            'driver' => 'komerce',
            'is_enabled' => true,
            'metadata' => json_encode(['payment_type' => 'qris'], JSON_THROW_ON_ERROR),
        ]);

        $factory->paymentMethods['cod'] = PaymentMethod::factory()->create([
            'slug' => 'cod',
            'title' => 'Cash on delivery',
            'driver' => 'manual',
            'is_enabled' => true,
        ]);

        $factory->paymentMethods['manual'] = PaymentMethod::factory()->create([
            'slug' => 'bank-transfer-manual',
            'title' => 'Transfer Bank Manual',
            'driver' => 'manual',
            'is_enabled' => true,
        ]);

        $factory->zone->paymentMethods()->attach(
            collect($factory->paymentMethods)->pluck('id')->all(),
        );

        Setting::query()->updateOrCreate(['key' => 'country_id'], ['value' => (string) $factory->country->id]);
        Setting::query()->updateOrCreate(['key' => 'default_currency_id'], ['value' => (string) $factory->currency->id]);
        Cache::forget('shopper-setting.country_id');
        Cache::forget('shopper-setting.default_currency_id');
        Cache::forget('shopper-setting.default_currency');
        GetCountriesByZone::flush();

        return $factory;
    }

    public function ensureSecondInventory(): Inventory
    {
        if ($this->secondInventory instanceof Inventory) {
            return $this->secondInventory;
        }

        $this->secondInventory = Inventory::query()->where('email', 'bandung@oceanmall.test')->first()
            ?? Inventory::factory()->create([
                'name' => 'Gudang Bandung',
                'is_default' => false,
                'country_id' => $this->country->id,
                'latitude' => -6.9175,
                'longitude' => 107.6191,
                'email' => 'bandung@oceanmall.test',
                'phone_number' => '0221234567',
                'street_address' => 'Jl. Asia Afrika 20',
                'city' => 'Bandung',
                'postal_code' => '40111',
                'rajaongkir_origin_id' => '17249',
            ]);

        return $this->secondInventory;
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    public function createStockedProduct(
        int $price = 100000,
        int $stock = 10,
        int $weightGrams = 500,
        ?Inventory $inventory = null,
        array $attrs = [],
    ): Product {
        $targetInventory = $inventory ?? $this->inventory;

        /** @var Product $product */
        $product = Product::factory()->standard()->create(array_merge([
            'name' => 'Produk OceanMall',
            'published_at' => now()->subDay(),
            'weight_value' => $weightGrams,
            'weight_unit' => 'g',
            'width_value' => 12,
            'width_unit' => 'cm',
            'height_value' => 8,
            'height_unit' => 'cm',
            'depth_value' => 20,
            'depth_unit' => 'cm',
        ], $attrs));

        if ($stock > 0) {
            $product->mutateStock($targetInventory->id, $stock);
        }

        Price::query()->create([
            'priceable_type' => $product->getMorphClass(),
            'priceable_id' => $product->id,
            'amount' => $price,
            'currency_id' => $this->currency->id,
        ]);

        return $product->fresh();
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    public function createVerifiedCustomer(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'first_name' => 'Budi',
            'last_name' => 'Santoso',
            'email' => 'budi.'.uniqid('', true).'@example.com',
            'phone_number' => '081234567890',
            'email_verified_at' => now(),
        ], $attrs));
    }

    public function createAdmin(): User
    {
        Setting::query()->updateOrCreate(['key' => 'email'], ['value' => 'ops@oceanmall.test']);
        Setting::query()->updateOrCreate(['key' => 'street_address'], ['value' => 'Jl. Test 1']);
        Cache::forget('shopper-setting.email');
        Cache::forget('shopper-setting.street_address');

        $role = Role::query()->firstOrCreate([
            'name' => config('shopper.admin.roles.admin'),
            'guard_name' => 'web',
        ]);

        (new PermissionsTableSeeder)->run();
        (new PermissionRoleTableSeeder)->run();
        (new GranularPermissionsSeeder)->run();

        $admin = User::factory()->create([
            'first_name' => 'Admin',
            'last_name' => 'OceanMall',
            'email' => 'admin.'.uniqid('', true).'@example.com',
            'email_verified_at' => now(),
        ]);
        $admin->assignRole($role->name);

        return $admin;
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @param  array<string, mixed>  $metadata
     */
    public function createSavedAddress(User $user, array $attrs = [], array $metadata = []): Address
    {
        $defaultMeta = [
            'destination_id' => '17601',
            'destination_label' => 'GAMBIR, GAMBIR, JAKARTA PUSAT, DKI JAKARTA, 10110',
            'destination_driver' => 'rajaongkir',
            'pin_point' => '-6.1754,106.8272',
        ];

        $mergedMeta = $metadata === [] ? $defaultMeta : $metadata;

        return $user->addresses()->create(array_merge([
            'first_name' => 'Budi',
            'last_name' => 'Santoso',
            'street_address' => 'Jl. Merdeka No. 1',
            'postal_code' => '10110',
            'city' => 'Jakarta Pusat',
            'state' => 'DKI Jakarta',
            'phone_number' => '081234567890',
            'country_id' => $this->country->id,
            'type' => AddressType::Shipping,
            'shipping_default' => false,
            'billing_default' => false,
            'metadata' => json_encode($mergedMeta, JSON_THROW_ON_ERROR),
        ], $attrs));
    }

    /**
     * @param  array<string, mixed>  $orderAttrs
     * @param  array<string, mixed>  $shipmentAttrs
     * @param  array<string, mixed>|null  $txAttrs
     * @return array{order: Order, shipment: OrderShipment, product: Product, transaction: ?PaymentTransaction}
     */
    public function createOrderWithShipment(
        User $customer,
        array $orderAttrs = [],
        array $shipmentAttrs = [],
        ?array $txAttrs = [],
        ?Product $product = null,
        ?Inventory $inventory = null,
    ): array {
        $targetInventory = $inventory ?? $this->inventory;
        $targetProduct = $product ?? $this->createStockedProduct(100000, 10, 500, $targetInventory);

        $shippingAddress = OrderAddress::query()->create([
            'first_name' => 'Budi',
            'last_name' => 'Santoso',
            'street_address' => 'Jl. Merdeka No. 1',
            'postal_code' => '10110',
            'city' => 'Jakarta Pusat',
            'phone' => '081234567890',
        ]);

        $defaultOrderMeta = [
            'komerce' => [
                'payment_type' => 'bank_transfer',
                'channel_code' => 'BCA',
                'provider' => 'payment_api',
                'payment_ref' => 'KOMPAY-VA-1',
                'expiry_date' => now()->addDay()->toDateTimeString(),
            ],
            'shipping_address' => [
                'destination_id' => '17601',
                'destination_driver' => 'rajaongkir',
                'rajaongkir_destination_id' => '17601',
                'pin_point' => '-6.1754,106.8272',
            ],
        ];

        if (array_key_exists('metadata', $orderAttrs) && is_array($orderAttrs['metadata'])) {
            $orderAttrs['metadata'] = $orderAttrs['metadata'] === []
                ? null
                : json_encode(
                    array_replace_recursive($defaultOrderMeta, $orderAttrs['metadata']),
                    JSON_THROW_ON_ERROR,
                );
        }

        $order = Order::factory()->create(array_merge([
            'number' => 'ORD-E2E-'.strtoupper(substr(uniqid('', true), -6)),
            'customer_id' => $customer->id,
            'price_amount' => 112000,
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
            'payment_method_id' => $this->paymentMethods['bca']->id,
            'shipping_address_id' => $shippingAddress->id,
            'metadata' => json_encode($defaultOrderMeta, JSON_THROW_ON_ERROR),
        ], $orderAttrs));

        OrderItem::query()->create([
            'order_id' => $order->id,
            'name' => $targetProduct->name,
            'sku' => $targetProduct->sku ?? 'SKU-E2E-1',
            'product_type' => $targetProduct->getMorphClass(),
            'product_id' => $targetProduct->id,
            'quantity' => 1,
            'unit_price_amount' => 100000,
        ]);

        $defaultShipmentMeta = [
            'rate' => [
                'provider' => 'shipping_delivery',
                'shipping_name' => 'JNE',
                'service_name' => 'JNEFlat',
                'shipping_cost' => 12000,
                'shipping_cashback' => 1000,
                'service_fee' => 0,
                'additional_cost' => 0,
                'grandtotal' => 112000,
                'cod_value' => 0,
                'insurance_value' => 0,
                'carrier_code' => 'jne',
            ],
        ];

        if (array_key_exists('metadata', $shipmentAttrs) && is_array($shipmentAttrs['metadata'])) {
            $shipmentAttrs['metadata'] = $shipmentAttrs['metadata'] === []
                ? []
                : array_replace_recursive($defaultShipmentMeta, $shipmentAttrs['metadata']);
        }

        $shipment = OrderShipment::query()->create(array_merge([
            'order_id' => $order->id,
            'inventory_id' => $targetInventory->id,
            'carrier_id' => $this->carriers['jne']->id ?? null,
            'driver' => 'rajaongkir',
            'carrier_code' => 'jne',
            'carrier_name' => 'JNE Express',
            'service_code' => 'JNEFlat',
            'service_name' => 'JNEFlat',
            'cost' => 12000,
            'currency_code' => 'IDR',
            'status' => 'pending',
            'metadata' => $defaultShipmentMeta,
        ], $shipmentAttrs));

        OrderShipmentLine::query()->create([
            'order_shipment_id' => $shipment->id,
            'purchasable_type' => $targetProduct->getMorphClass(),
            'purchasable_id' => $targetProduct->id,
            'qty' => 1,
        ]);

        $transaction = null;

        if ($txAttrs !== null) {
            $transaction = PaymentTransaction::query()->create(array_merge([
                'order_id' => $order->id,
                'driver' => 'komerce',
                'type' => TransactionType::Initiate,
                'status' => TransactionStatus::Pending,
                'amount' => (int) $order->price_amount,
                'currency_code' => 'IDR',
                'reference' => 'KOMPAY-VA-1',
            ], $txAttrs));
        }

        return [
            'order' => $order->fresh(),
            'shipment' => $shipment->fresh(),
            'product' => $targetProduct,
            'transaction' => $transaction?->fresh(),
        ];
    }
}
