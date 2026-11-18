<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Models\Product;
use App\Models\User;
use App\Shipping\AddressDestination;
use App\Shipping\Contracts\RequiresDestination;
use App\Shipping\Contracts\ResolvesDestinations;
use App\Shipping\InventoryOrigins;
use App\Shipping\ShippingQuoteContext;
use App\Support\CustomerCheckoutState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Shopper\Core\Models\Address;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\Country;
use Shopper\Core\Models\Currency;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Price;
use Shopper\Core\Models\Zone;
use Shopper\Shipping\DataTransferObjects\Address as ShippingAddress;
use Shopper\Shipping\DataTransferObjects\ShippingRate;
use Shopper\Shipping\Drivers\Driver;
use Shopper\Shipping\Facades\Shipping;
use Tests\TestCase;

/**
 * The store swaps RajaOngkir for another destination-based driver
 * ('areaswap', standing in for e.g. Biteship). App builds already in the
 * stores search /checkout/destinations (now the new driver's area ids) and
 * post the pick back under the legacy rajaongkir_destination_id key: that id
 * belongs to TODAY's destination driver, while ids stored before the swap
 * stay RajaOngkir's and are never offered as the new driver's.
 */
final class ProviderSwapLegacyAppTest extends TestCase
{
    use RefreshDatabase;

    private SwapAreaDriver $driver;

    private Country $country;

    private Inventory $inventory;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake();

        $this->driver = new SwapAreaDriver;
        $driver = $this->driver;
        Shipping::extend('areaswap', static fn (): SwapAreaDriver => $driver);
        config()->set('shopper.shipping.drivers.areaswap.enabled', true);

        $currency = Currency::query()->where('code', 'IDR')->first()
            ?? Currency::factory()->create(['code' => 'IDR', 'name' => 'Rupiah', 'symbol' => 'Rp', 'format' => 'Rp1.234']);
        $this->country = Country::factory()->create(['cca2' => 'ID']);
        $zone = Zone::factory()->create(['is_enabled' => true, 'currency_id' => $currency->id]);
        $zone->countries()->attach($this->country->id);

        $carrier = Carrier::query()->create([
            'name' => 'JNE via AreaSwap',
            'slug' => 'areaswap-jne',
            'driver' => 'areaswap',
            'is_enabled' => true,
            'metadata' => ['courier_code' => 'jne'],
        ]);
        $zone->carriers()->attach($carrier->id);

        $this->inventory = Inventory::factory()->create(['is_default' => true, 'country_id' => $this->country->id]);
        resolve(InventoryOrigins::class)->put($this->inventory, 'areaswap', 'ORIGIN-1');
    }

    public function test_from_request_stamps_legacy_only_ids_with_todays_destination_driver(): void
    {
        $this->assertSame(
            ['destination_id' => 'AREA-7', 'destination_driver' => 'areaswap'],
            AddressDestination::fromRequest(['rajaongkir_destination_id' => 'AREA-7'], 'areaswap'),
        );
        // No destination driver configured: legacy ids keep meaning RajaOngkir.
        $this->assertSame('rajaongkir', AddressDestination::fromRequest(['rajaongkir_destination_id' => '17547'], null)['destination_driver']);
        // An explicit driver always wins; a neutral id is left to normalize().
        $this->assertSame('rajaongkir', AddressDestination::fromRequest(['rajaongkir_destination_id' => '1', 'destination_driver' => 'rajaongkir'], 'areaswap')['destination_driver']);
        $this->assertArrayNotHasKey('destination_driver', AddressDestination::fromRequest(['destination_id' => 'AREA-7'], 'areaswap'));
        // Stored rows are unchanged: legacy keys there are RajaOngkir's.
        $this->assertSame('rajaongkir', AddressDestination::fromInput(['rajaongkir_destination_id' => '17547'])['destination_driver']);
    }

    public function test_an_old_app_build_posting_a_legacy_key_gets_quotes_from_the_new_driver(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $product = $this->stockedProduct();
        $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1])->assertOk();

        // What an old build does: search, then post the pick as rajaongkir_*.
        $this->getJson('/api/v1/checkout/destinations?q=melawai')
            ->assertOk()
            ->assertJsonPath('data.0.id', 'AREA-7');

        $this->postJson('/api/v1/checkout/shipping-address', [
            ...$this->address(),
            'rajaongkir_destination_id' => 'AREA-7',
            'rajaongkir_destination_label' => 'Melawai',
        ])
            ->assertOk()
            ->assertJsonPath('data.shipping_address.destination_id', 'AREA-7')
            ->assertJsonPath('data.shipping_address.destination_driver', 'areaswap')
            ->assertJsonPath('data.shipping_address.rajaongkir_destination_id', 'AREA-7')
            ->assertJsonPath('data.shipping_rates.0.driver', 'areaswap')
            ->assertJsonPath('data.shipping_rates.0.amount', 21000);

        $this->assertContains('AREA-7', $this->driver->quotedDestinations, 'The new driver quoted with the posted id.');

        $saved = Address::query()->where('user_id', $user->id)->sole();
        $this->assertSame('areaswap', json_decode((string) $saved->getRawOriginal('metadata'), true)['destination_driver']);
    }

    public function test_checkout_show_clears_a_session_destination_of_another_driver(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // Saved before the swap: a RajaOngkir subdistrict id.
        resolve(CustomerCheckoutState::class)->putShippingAddress($user, [
            ...$this->address(),
            'country_id' => $this->country->id,
            'destination_id' => '17547',
            'destination_driver' => 'rajaongkir',
            'destination_label' => 'MELAWAI, KEBAYORAN BARU',
        ]);

        $this->getJson('/api/v1/checkout')
            ->assertOk()
            ->assertJsonPath('data.shipping_address.city', 'Jakarta Selatan')
            ->assertJsonPath('data.shipping_address.destination_id', null)
            ->assertJsonPath('data.shipping_address.rajaongkir_destination_id', null)
            ->assertJsonPath('data.shipping_address.destination_label', null);
    }

    public function test_address_book_stamps_a_new_legacy_only_id_with_todays_driver(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $id = $this->postJson('/api/v1/addresses', [
            ...$this->address(),
            'country_id' => $this->country->id,
            'type' => 'shipping',
            'rajaongkir_destination_id' => 'AREA-7',
        ])
            ->assertCreated()
            ->assertJsonPath('data.destination_driver', 'areaswap')
            ->json('data.id');

        $this->assertSame('areaswap', json_decode((string) Address::query()->findOrFail($id)->getRawOriginal('metadata'), true)['destination_driver']);
    }

    /**
     * @return array<string, string>
     */
    private function address(): array
    {
        return [
            'first_name' => 'Budi',
            'last_name' => 'Santoso',
            'street_address' => 'Jl. Melawai 1',
            'postal_code' => '12160',
            'city' => 'Jakarta Selatan',
            'state' => 'DKI Jakarta',
            'phone_number' => '081234567890',
        ];
    }

    private function stockedProduct(): Product
    {
        $product = Product::factory()->standard()->create([
            'published_at' => now()->subDay(),
            'name' => 'Swap Product',
            'weight_value' => 0.5,
            'weight_unit' => 'kg',
        ]);
        $product->mutateStock($this->inventory->id, 10);

        $currency = Currency::query()->where('code', shopper_currency())->first()
            ?? Currency::factory()->create([
                'code' => shopper_currency(),
                'name' => shopper_currency(),
                'symbol' => shopper_currency(),
                'format' => '1,234.56',
            ]);

        Price::query()->create([
            'priceable_type' => $product->getMorphClass(),
            'priceable_id' => $product->id,
            'amount' => 100000,
            'currency_id' => $currency->id,
        ]);

        return $product;
    }
}

final class SwapAreaDriver extends Driver implements RequiresDestination, ResolvesDestinations
{
    /** @var list<string|null> */
    public array $quotedDestinations = [];

    public function code(): string
    {
        return 'areaswap';
    }

    public function name(): string
    {
        return 'AreaSwap';
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

    public function calculateRates(ShippingAddress $from, ShippingAddress $to, array $packages): Collection
    {
        $destination = resolve(ShippingQuoteContext::class)->destinationId($this->code());
        $this->quotedDestinations[] = $destination;

        if ($destination === null) {
            return collect();
        }

        return collect([new ShippingRate(
            carrierCode: 'jne',
            serviceCode: 'REG',
            serviceName: 'Reguler',
            amount: 21000,
            currency: 'IDR',
            estimatedDays: '2',
        )]);
    }

    public function searchDestinations(string $query, int $limit = 10): array
    {
        return [['id' => 'AREA-7', 'label' => 'Melawai', 'postal_code' => '12160', 'city' => 'Jakarta Selatan', 'state' => 'DKI Jakarta']];
    }

    public function resolveOrigin(Inventory $inventory): ?array
    {
        return null;
    }
}
