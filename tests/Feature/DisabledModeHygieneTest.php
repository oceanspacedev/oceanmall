<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Checkout\FetchPaymentMethods;
use App\Console\Commands\ExpireUnpaidOrders;
use App\Integrations\Komerce\Komerce;
use App\Integrations\Komerce\Support\KomerceCapabilities;
use App\Models\User;
use App\Support\CarrierLogos;
use Database\Seeders\CarrierSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\ZoneSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\CarrierOption;
use Shopper\Core\Models\Country;
use Shopper\Core\Models\Currency;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Core\Models\Zone;
use Shopper\Payment\Enum\TransactionStatus;
use Shopper\Payment\Enum\TransactionType;
use Shopper\Payment\Models\PaymentTransaction;
use Tests\TestCase;

/**
 * With no provider keys every Komerce feature is inert: no external call, no
 * vendor-branded fallback, nothing cancelled blind, and a fresh seed can sell.
 */
final class DisabledModeHygieneTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function disabledWebhooks(): iterable
    {
        yield 'payment' => ['POST', '/webhooks/komerce/payment'];
        yield 'delivery' => ['POST', '/webhooks/komerce/delivery'];
        yield 'delivery (PUT)' => ['PUT', '/webhooks/komerce/delivery'];
        yield 'qrisly' => ['POST', '/webhooks/komerce/qrisly'];
    }

    #[DataProvider('disabledWebhooks')]
    public function test_every_disabled_webhook_gets_the_same_acknowledgement(string $method, string $uri): void
    {
        $this->requireModules('Komerce');

        Http::preventStrayRequests();
        Http::fake();
        // Even with a signing secret, nothing is verified or processed while the product is off.
        config()->set('komerce.webhook_secret', 'webhook-secret');

        $this->json($method, $uri, [
            'payment_id' => 'KOMPAY-1',
            'order_no' => 'KOM-1',
            'status' => 'PAID',
            'event' => 'payment.success',
            'data' => ['history_id' => '1'],
        ])
            ->assertOk()
            ->assertExactJson([
                'status' => 'disabled',
                'success' => true,
                'message' => 'Webhook received successfully',
            ]);

        Http::assertNothingSent();
    }

    public function test_expiry_cancels_pending_komerce_orders_locally_after_the_grace_period_once_the_key_is_removed(): void
    {
        $this->requireModules('Komerce');

        Http::preventStrayRequests();
        Http::fake();

        $order = Order::factory()->create([
            'customer_id' => User::factory()->create()->id,
            'status' => OrderStatus::New,
            'payment_status' => PaymentStatus::Pending,
            'currency_code' => 'IDR',
            'created_at' => now()->subDays(3),
            'metadata' => json_encode(['komerce' => [
                'payment_ref' => 'pay_old',
                'expiry_date' => now()->subHour()->toDateTimeString(),
            ]], JSON_THROW_ON_ERROR),
        ]);
        PaymentTransaction::query()->create([
            'order_id' => $order->id,
            'reference' => 'pay_old',
            'driver' => 'komerce',
            'type' => TransactionType::Initiate,
            'amount' => 10000,
            'currency_code' => 'IDR',
            'status' => TransactionStatus::Pending,
        ]);

        // Nothing can verify the payment: within the grace period the order
        // is left alone (a transfer may have landed just before expiry)...
        $this->artisan('komerce:expire-unpaid-orders')->assertSuccessful();

        $order->refresh();
        $this->assertSame(OrderStatus::New, $order->status);
        $this->assertSame(PaymentStatus::Pending, $order->payment_status);

        // ...then it is cancelled locally and its stock released, still
        // without any call to the unconfigured provider.
        $this->travel(ExpireUnpaidOrders::LOCAL_GRACE_HOURS)->hours();
        $this->artisan('orders:expire-unpaid')->assertSuccessful();

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(PaymentStatus::Voided, $order->payment_status);
        Http::assertNothingSent();
    }

    public function test_keyless_seed_has_an_enabled_manual_payment_method_and_shipping_option(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $this->seedBaseGeography();

        $this->seed([PaymentMethodSeeder::class, CarrierSeeder::class, ZoneSeeder::class]);

        $zone = Zone::query()->where('slug', 'indonesia')->firstOrFail();
        $cod = PaymentMethod::query()->where('slug', 'cod')->firstOrFail();

        $this->assertTrue((bool) $cod->is_enabled);
        $this->assertSame('manual', $cod->driver);
        $this->assertTrue($zone->paymentMethods()->whereKey($cod->id)->exists());
        $this->assertSame(
            0,
            PaymentMethod::query()->where('driver', 'komerce')->where('is_enabled', true)->count(),
            'Komerce methods are only enabled when the Payment key is present.',
        );

        $countryId = (int) Country::query()->where('cca3', 'IDN')->value('id');
        $offered = collect(resolve(FetchPaymentMethods::class)->handle($countryId))->pluck('id')->all();
        $this->assertSame([$cod->id], $offered);

        $manual = Carrier::query()->where('slug', 'manual')->firstOrFail();
        $this->assertTrue(CarrierOption::query()
            ->where('zone_id', $zone->id)
            ->where('carrier_id', $manual->id)
            ->where('is_enabled', true)
            ->exists());

        Http::assertNothingSent();
    }

    public function test_seed_with_payment_key_enables_komerce_methods_and_keeps_cod_off(): void
    {
        $this->requireModules('Komerce');

        config()->set('komerce.payment_api_key', 'test-payment-key');

        $this->seed(PaymentMethodSeeder::class);

        $this->assertFalse((bool) PaymentMethod::query()->where('slug', 'cod')->value('is_enabled'));
        $this->assertTrue((bool) PaymentMethod::query()->where('slug', 'komerce-va-bca')->value('is_enabled'));
        $this->assertTrue((bool) PaymentMethod::query()->where('slug', 'komerce-qris')->value('is_enabled'));
    }

    public function test_reseeding_keeps_staff_enabled_flags(): void
    {
        $this->requireModules('Komerce');

        config()->set('komerce.payment_api_key', 'test-payment-key');
        $this->seed(PaymentMethodSeeder::class);

        // Staff turn COD back on and one bank off in the cpanel.
        PaymentMethod::query()->where('slug', 'cod')->update(['is_enabled' => true]);
        PaymentMethod::query()->where('slug', 'komerce-va-bni')->update(['is_enabled' => false]);

        // Re-seed while the plan cache transiently reports no Payment product.
        config()->set('komerce.payment_api_key', '');
        $this->seed(PaymentMethodSeeder::class);

        $enabled = PaymentMethod::query()->pluck('is_enabled', 'slug')->map(static fn (mixed $value): bool => (bool) $value);
        $this->assertTrue($enabled['cod']);
        $this->assertFalse($enabled['komerce-va-bni']);
        $this->assertTrue($enabled['komerce-va-bca']);
        $this->assertTrue($enabled['komerce-qris']);
        $this->assertFalse($enabled['stripe']);
    }

    public function test_carrier_logos_never_point_at_a_provider_cdn_while_the_provider_is_off(): void
    {
        $logos = resolve(CarrierLogos::class);

        // Local file wins.
        $jne = $logos->forCarrier(new Carrier(['slug' => 'jne', 'name' => 'JNE']));
        $this->assertIsString($jne);
        $this->assertStringContainsString('images/couriers/jne.svg', $jne);

        // No local file and no provider: no logo rather than a third-party URL.
        $this->assertNull($logos->forCode('gosend'));

        // Carrier metadata always wins.
        $this->assertSame(
            'https://cdn.example.test/kurir.png',
            $logos->forCarrier(new Carrier(['slug' => 'jne', 'metadata' => ['logo_url' => 'https://cdn.example.test/kurir.png']])),
        );
    }

    public function test_provider_logo_source_is_consulted_only_while_the_provider_is_configured(): void
    {
        $this->requireModules('Komerce');

        config()->set('komerce.shipping_cost_api_key', 'test-cost-key');

        $logo = resolve(CarrierLogos::class)->forCode('gosend');

        $this->assertIsString($logo);
        $this->assertStringContainsString('storage.googleapis.com/komerce', $logo);
        // A local asset still wins over the provider CDN.
        $this->assertStringContainsString('images/couriers/jne.svg', (string) resolve(CarrierLogos::class)->forCode('jne'));
    }

    public function test_capability_state_is_read_from_the_cache_once_per_request(): void
    {
        $this->requireModules('Komerce');

        config()->set('komerce.shipping_cost_api_key', 'test-cost-key');
        $capabilities = resolve(KomerceCapabilities::class);

        $this->assertSame($capabilities, resolve(KomerceCapabilities::class), 'scoped per request / job');
        $this->assertTrue(Komerce::shippingCostEnabled());

        // A write through another path is not re-read mid-request...
        Cache::put('komerce:capabilities:v1', ['shipping_cost' => ['entitled' => false]], now()->addDay());
        $this->assertTrue(Komerce::shippingCostEnabled());

        // ...while observations made through the instance apply at once.
        $capabilities->observe('shipping_cost', 401, '{"meta":{"message":"Invalid API key"}}');
        $this->assertFalse(Komerce::shippingCostEnabled());

        // forget() drops the memo too.
        $capabilities->forget();
        $this->assertTrue(Komerce::shippingCostEnabled());
    }

    private function seedBaseGeography(): void
    {
        Currency::query()->where('code', 'IDR')->first()
            ?? Currency::factory()->create(['code' => 'IDR', 'name' => 'Rupiah', 'symbol' => 'Rp', 'format' => 'Rp1.234']);
        Country::factory()->create(['cca2' => 'ID', 'cca3' => 'IDN', 'name' => 'Indonesia']);
    }
}
