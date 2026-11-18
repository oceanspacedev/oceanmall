<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Product;
use App\Models\User;
use App\Payment\Contracts\ConfirmsBeforeOrder;
use App\Payment\PaymentActions;
use App\Shipping\AddressDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\CarrierOption;
use Shopper\Core\Models\Country;
use Shopper\Core\Models\Currency;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Core\Models\Price;
use Shopper\Core\Models\Zone;
use Shopper\Payment\DataTransferObjects\PaymentResult;
use Shopper\Payment\DataTransferObjects\WebhookResult;
use Shopper\Payment\Drivers\Driver;
use Shopper\Payment\Facades\Payment;
use Tests\TestCase;

/**
 * Mobile API with gateways added through Payment::extend() and app builds
 * already in the stores, in a store without Komerce keys:
 *
 * - confirm-first drivers (ConfirmsBeforeOrder) are neither listed nor
 *   accepted by the app, by capability rather than driver name;
 * - a hosted-page (redirect) gateway still gives old builds a pay link in the
 *   legacy `payment` field;
 * - old builds, which always require a destination picked from the search,
 *   can still save an address when no driver resolves destinations.
 */
final class PaymentDriverCompatApiTest extends TestCase
{
    use RefreshDatabase;

    private PaymentMethod $card;

    private PaymentMethod $hosted;

    private CarrierOption $option;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake();

        Payment::extend('apicard', static fn (): ApiCardDriver => new ApiCardDriver);
        Payment::extend('hostedpay', static fn (): HostedPayDriver => new HostedPayDriver);
        config()->set('shopper.payment.drivers.apicard.enabled', true);
        config()->set('shopper.payment.drivers.hostedpay.enabled', true);

        $currency = Currency::query()->where('code', 'IDR')->first()
            ?? Currency::factory()->create(['code' => 'IDR', 'name' => 'Rupiah', 'symbol' => 'Rp', 'format' => 'Rp1.234']);
        $country = Country::factory()->create(['cca2' => 'ID']);
        $zone = Zone::factory()->create(['is_enabled' => true, 'currency_id' => $currency->id]);
        $zone->countries()->attach($country->id);

        $carrier = Carrier::query()->create(['name' => 'Kurir Toko', 'slug' => 'manual', 'driver' => 'manual', 'is_enabled' => true]);
        $zone->carriers()->attach($carrier->id);
        $this->option = CarrierOption::query()->create([
            'carrier_id' => $carrier->id,
            'zone_id' => $zone->id,
            'name' => 'Reguler',
            'price' => 15000,
            'is_enabled' => true,
        ]);

        $this->card = PaymentMethod::factory()->create(['title' => 'Kartu', 'slug' => 'kartu', 'driver' => 'apicard', 'is_enabled' => true]);
        $this->hosted = PaymentMethod::factory()->create(['title' => 'Hosted', 'slug' => 'hosted', 'driver' => 'hostedpay', 'is_enabled' => true]);
        $zone->paymentMethods()->attach([$this->card->id, $this->hosted->id]);

        $inventory = Inventory::factory()->create(['is_default' => true, 'country_id' => $country->id]);
        $product = Product::factory()->standard()->create(['published_at' => now()->subDay(), 'weight_value' => 0.5, 'weight_unit' => 'kg']);
        $product->mutateStock($inventory->id, 10);
        Price::query()->create([
            'priceable_type' => $product->getMorphClass(),
            'priceable_id' => $product->id,
            'amount' => 50000,
            'currency_id' => (Currency::query()->where('code', shopper_currency())->first()
                ?? Currency::factory()->create(['code' => shopper_currency(), 'name' => shopper_currency(), 'symbol' => shopper_currency(), 'format' => '1,234.56']))->id,
        ]);

        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/cart/items', ['product_id' => $product->id])->assertOk();
    }

    public function test_old_app_build_checks_out_without_a_destination_driver(): void
    {
        // Old builds search, require a picked row and read its zip_code.
        $this->getJson('/api/v1/checkout/destinations?q=Cirebon')
            ->assertOk()
            ->assertExactJson(['data' => []]);

        $row = $this->getJson('/api/v1/checkout/destinations?q='.urlencode('Cirebon 45153'))
            ->assertOk()
            ->assertJsonPath('data.0.zip_code', '45153')
            ->assertJsonPath('data.0.city_name', 'Cirebon')
            ->assertJsonPath('data.0.label', 'Cirebon 45153')
            ->json('data.0');

        // The old build's exact payload: the row id comes back as a legacy id.
        $this->postJson('/api/v1/checkout/shipping-address', [
            'first_name' => 'Budi',
            'last_name' => 'Santoso',
            'street_address' => 'Jl. Merdeka 1',
            'postal_code' => $row['zip_code'],
            'city' => $row['city_name'],
            'state' => $row['province_name'],
            'phone_number' => '081234567890',
            'rajaongkir_destination_id' => $row['id'],
            'rajaongkir_destination_label' => $row['label'],
        ])
            ->assertOk()
            ->assertJsonPath('data.shipping_address.postal_code', '45153')
            ->assertJsonPath('data.shipping_address.destination_id', null)
            ->assertJsonPath('data.shipping_address.destination_driver', null)
            // Only the legacy alias carries a placeholder (see the next test).
            ->assertJsonPath('data.shipping_address.rajaongkir_destination_id', AddressDestination::FREE_TEXT_PREFIX.'45153 Cirebon')
            ->assertJsonCount(1, 'data.shipping_rates');

        $address = User::query()->sole()->addresses()->sole();
        $this->assertStringNotContainsString(AddressDestination::FREE_TEXT_PREFIX, (string) json_encode($address->metadata));

        $this->postJson('/api/v1/checkout/shipping-option', ['service_code' => (string) $this->option->id])->assertOk();

        $this->postJson('/api/v1/checkout/place-order', ['payment_method_id' => $this->hosted->id])->assertCreated();
        Http::assertNothingSent();
    }

    public function test_old_app_build_finds_saved_regions_and_reuses_an_address_without_a_new_search(): void
    {
        $customer = User::query()->sole();
        $neighbour = User::factory()->create();
        $neighbour->addresses()->create([
            'first_name' => 'Siti', 'last_name' => 'Aminah', 'street_address' => 'Jl. Rahasia 9',
            'postal_code' => '45153', 'city' => 'Harjamukti, Cirebon', 'state' => 'Jawa Barat',
            'phone_number' => '081200000000', 'type' => 'shipping',
        ]);
        // A city that is only the postal code (typed into an old build) is no area.
        $neighbour->addresses()->create([
            'first_name' => 'Siti', 'last_name' => 'Aminah', 'street_address' => 'Jl. Lain 1',
            'postal_code' => '45154', 'city' => '45154', 'phone_number' => '081200000000', 'type' => 'shipping',
        ]);

        // By area name, and by postal code alone: the region the address book
        // knows (never names or streets), so city / province are real.
        $byName = $this->getJson('/api/v1/checkout/destinations?q=harjamukti')->assertOk()->json('data');
        $this->assertSame([[
            'id' => AddressDestination::FREE_TEXT_PREFIX.'Harjamukti, Cirebon, Jawa Barat 45153',
            'label' => 'Harjamukti, Cirebon, Jawa Barat 45153',
            'postal_code' => '45153',
            'city' => 'Harjamukti, Cirebon',
            'state' => 'Jawa Barat',
            'district' => null,
            'zip_code' => '45153',
            'city_name' => 'Harjamukti, Cirebon',
            'province_name' => 'Jawa Barat',
        ]], $byName);
        $this->getJson('/api/v1/checkout/destinations?q=45153')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.city_name', 'Harjamukti, Cirebon');
        $this->getJson('/api/v1/checkout/destinations?q=45154')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.city_name', null);
        $this->getJson('/api/v1/checkout/destinations?q=Bandung')->assertOk()->assertExactJson(['data' => []]);

        $row = $byName[0];
        $this->postJson('/api/v1/checkout/shipping-address', [
            'first_name' => 'Budi', 'last_name' => 'Santoso', 'street_address' => 'Jl. Merdeka 1',
            'postal_code' => $row['zip_code'], 'city' => $row['city_name'], 'state' => $row['province_name'],
            'phone_number' => '081234567890',
            'rajaongkir_destination_id' => $row['id'], 'rajaongkir_destination_label' => $row['label'],
        ])->assertOk();

        // Reopening: old builds only reuse an address with a legacy destination
        // id. They get a placeholder; current builds still see no destination.
        $legacyId = AddressDestination::FREE_TEXT_PREFIX.'45153 Harjamukti, Cirebon';
        $checkout = $this->getJson('/api/v1/checkout')
            ->assertOk()
            ->assertJsonPath('data.shipping_address.destination_id', null)
            ->assertJsonPath('data.shipping_address.rajaongkir_destination_id', $legacyId)
            ->assertJsonPath('data.saved_addresses.0.destination_id', null)
            ->assertJsonPath('data.saved_addresses.0.rajaongkir_destination_id', $legacyId)
            ->json('data');
        $this->getJson('/api/v1/addresses')->assertOk()->assertJsonPath('data.0.rajaongkir_destination_id', $legacyId);

        // The old build's payload for a reused address: postal code from the
        // checkout address, city from the label (the address's city).
        $this->postJson('/api/v1/checkout/shipping-address', [
            'first_name' => 'Budi', 'last_name' => 'Santoso', 'street_address' => 'Jl. Merdeka 1',
            'postal_code' => $checkout['shipping_address']['postal_code'],
            'city' => $checkout['shipping_address']['rajaongkir_destination_label'] ?? $checkout['shipping_address']['city'],
            'phone_number' => '081234567890',
            'rajaongkir_destination_id' => $checkout['shipping_address']['rajaongkir_destination_id'],
        ])
            ->assertOk()
            ->assertJsonPath('data.shipping_address.city', 'Harjamukti, Cirebon')
            ->assertJsonPath('data.shipping_address.state', 'Jawa Barat')
            ->assertJsonCount(1, 'data.shipping_rates');

        $this->assertSame(1, $customer->addresses()->count(), 'the same address, not a copy');
        $this->assertStringNotContainsString(AddressDestination::FREE_TEXT_PREFIX, (string) json_encode($customer->addresses()->sole()->metadata));
        Http::assertNothingSent();
    }

    public function test_confirm_first_methods_are_hidden_from_and_refused_by_the_app(): void
    {
        $this->readyForPayment();

        $listed = collect($this->getJson('/api/v1/checkout')->assertOk()->json('data.payment_methods'))->pluck('driver')->all();
        $this->assertContains('hostedpay', $listed);
        $this->assertNotContains('apicard', $listed);

        $this->postJson('/api/v1/checkout/place-order', ['payment_method_id' => $this->card->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Metode pembayaran ini hanya tersedia di website.');

        $this->assertSame(0, Order::query()->count());
    }

    public function test_hosted_page_gateway_gives_old_app_builds_a_payment_url(): void
    {
        $this->readyForPayment();

        $this->postJson('/api/v1/checkout/place-order', ['payment_method_id' => $this->hosted->id])
            ->assertCreated()
            ->assertJsonPath('data.payment_action.kind', 'redirect')
            ->assertJsonPath('data.payment_action.data.url', 'https://pay.example.test/session/1')
            ->assertJsonPath('data.payment', ['payment_url' => 'https://pay.example.test/session/1']);
    }

    public function test_legacy_instructions_shape_per_action_kind(): void
    {
        $this->assertSame(['payment_url' => 'https://pay.example.test/x'], PaymentActions::legacyInstructions(['driver' => 'x', 'kind' => 'redirect', 'data' => ['url' => 'https://pay.example.test/x']]));
        $this->assertNull(PaymentActions::legacyInstructions(['driver' => 'x', 'kind' => 'redirect', 'data' => []]));
        $this->assertSame(['va_number' => '1'], PaymentActions::legacyInstructions(['driver' => 'x', 'kind' => 'instructions', 'data' => ['va_number' => '1']]));
        $this->assertNull(PaymentActions::legacyInstructions(['driver' => 'x', 'kind' => 'client_secret', 'data' => ['client_secret' => 's']]));
        $this->assertNull(PaymentActions::legacyInstructions(null));
    }

    private function readyForPayment(): void
    {
        $this->postJson('/api/v1/checkout/shipping-address', [
            'first_name' => 'Budi',
            'last_name' => 'Santoso',
            'street_address' => 'Jl. Merdeka 1',
            'postal_code' => '45153',
            'city' => 'Cirebon',
            'phone_number' => '081234567890',
        ])->assertOk();

        $this->postJson('/api/v1/checkout/shipping-option', ['service_code' => (string) $this->option->id])->assertOk();
    }
}

/**
 * An embedded card gateway as a third party would add it.
 */
final class ApiCardDriver extends Driver implements ConfirmsBeforeOrder
{
    public function code(): string
    {
        return 'apicard';
    }

    public function name(): string
    {
        return 'ApiCard';
    }

    public function logo(): ?string
    {
        return null;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function supportsWebhooks(): bool
    {
        return false;
    }

    public function supportsRefunds(): bool
    {
        return false;
    }

    public function initiatePayment(int $amount, string $currency, array $context = []): PaymentResult
    {
        return new PaymentResult(success: true, status: 'pending', reference: 'card_1', clientSecret: 'cs_1', amount: $amount);
    }

    public function handleWebhook(array $payload, array $headers = []): WebhookResult
    {
        return WebhookResult::ignored();
    }

    public function prepareBeforeOrder(int $amount, string $currency, array $context = []): array
    {
        return ['reference' => 'card_1', 'data' => ['client_secret' => 'cs_1']];
    }

    public function returnReference(Request $request): ?string
    {
        return null;
    }

    public function confirmedStatus(string $reference): ?PaymentStatus
    {
        return null;
    }

    public function attachOrder(Order $order, string $reference, PaymentStatus $status): void {}
}

/**
 * A hosted payment page gateway (Midtrans / Xendit invoice style).
 */
final class HostedPayDriver extends Driver
{
    public function code(): string
    {
        return 'hostedpay';
    }

    public function name(): string
    {
        return 'HostedPay';
    }

    public function logo(): ?string
    {
        return null;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function supportsWebhooks(): bool
    {
        return false;
    }

    public function supportsRefunds(): bool
    {
        return false;
    }

    public function initiatePayment(int $amount, string $currency, array $context = []): PaymentResult
    {
        return new PaymentResult(success: true, status: 'pending', reference: 'hp_1', redirectUrl: 'https://pay.example.test/session/1', amount: $amount);
    }

    public function handleWebhook(array $payload, array $headers = []): WebhookResult
    {
        return WebhookResult::ignored();
    }
}
