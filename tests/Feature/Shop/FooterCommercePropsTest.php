<?php

declare(strict_types=1);

namespace Tests\Feature\Shop;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\PaymentMethod;
use Tests\TestCase;

final class FooterCommercePropsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config()->set('komerce.enabled', true);
        config()->set('komerce.payment_api_key', 'payment-key');
        config()->set('komerce.payment_base_url', 'https://payment.example.test/user');
        Http::preventStrayRequests();
        config()->set('komerce.shipping_cost_api_key', 'shipping-cost-key');
        config()->set('shopper.payment.drivers.stripe.enabled', false);
    }

    /**
     * Official Komerce catalog as returned by GET /api/v1/user/methods.
     *
     * @return array<string, mixed>
     */
    private function officialCatalog(): array
    {
        return [
            'meta' => ['code' => 200, 'status' => 'success'],
            'data' => [
                ['payment_type' => 'va', 'display_name' => 'Bank Central Asia', 'bank_code' => 'BCA', 'logo_url' => 'https://storage.googleapis.com/komerce/assets/logo/bca.png', 'min_amount' => 10000],
                ['payment_type' => 'qris', 'display_name' => 'QRIS', 'bank_code' => '', 'logo_url' => 'https://storage.googleapis.com/komerce/assets/logo/QRIS.png', 'min_amount' => 10000],
            ],
        ];
    }

    private function seedKomerceMethods(): void
    {
        PaymentMethod::factory()->create([
            'slug' => 'cod',
            'title' => 'Cash on delivery',
            'driver' => 'manual',
            'is_enabled' => true,
        ]);

        PaymentMethod::factory()->create([
            'slug' => 'komerce-va-bca',
            'title' => 'BCA Virtual Account',
            'driver' => 'komerce',
            'is_enabled' => true,
            'metadata' => json_encode(['payment_type' => 'bank_transfer', 'channel_code' => 'BCA'], JSON_THROW_ON_ERROR),
        ]);

        // Enabled locally but NOT offered by the provider catalog.
        PaymentMethod::factory()->create([
            'slug' => 'komerce-va-bri',
            'title' => 'BRI Virtual Account',
            'driver' => 'komerce',
            'is_enabled' => true,
            'metadata' => json_encode(['payment_type' => 'bank_transfer', 'channel_code' => 'BRI'], JSON_THROW_ON_ERROR),
        ]);

        PaymentMethod::factory()->create([
            'slug' => 'komerce-qris',
            'title' => 'QRIS',
            'driver' => 'komerce',
            'is_enabled' => true,
            'metadata' => json_encode(['payment_type' => 'qris'], JSON_THROW_ON_ERROR),
        ]);

        PaymentMethod::factory()->create([
            'slug' => 'stripe',
            'title' => 'Stripe Card',
            'driver' => 'stripe',
            'is_enabled' => true,
        ]);
    }

    public function test_footer_payment_methods_mirror_the_official_komerce_catalog(): void
    {
        $this->fakeKomercePaymentHttp([
            'https://payment.example.test/user/api/v1/user/methods' => Http::response($this->officialCatalog()),
        ]);
        $this->seedKomerceMethods();

        $this->get(route('home'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('shop.payment_methods', 3)
                ->where('shop.payment_methods.0.key', 'cod')
                ->where('shop.payment_methods.0.logo', fn ($logo) => is_string($logo) && str_contains($logo, 'images/payments/cod.svg'))
                ->where('shop.payment_methods.1.key', 'komerce-va-bca')
                ->where('shop.payment_methods.1.title', 'Bank Central Asia')
                ->where('shop.payment_methods.1.logo', 'https://storage.googleapis.com/komerce/assets/logo/bca.png')
                ->where('shop.payment_methods.2.key', 'komerce-qris')
                ->where('shop.payment_methods.2.title', 'QRIS')
                ->where('shop.payment_methods.2.logo', 'https://storage.googleapis.com/komerce/assets/logo/QRIS.png')
            );
    }

    public function test_footer_falls_back_to_local_payment_data_when_catalog_is_unavailable(): void
    {
        $this->fakeKomercePaymentHttp([
            'https://payment.example.test/user/api/v1/user/methods' => Http::response(['meta' => ['code' => 500]], 500),
        ]);
        $this->seedKomerceMethods();

        $this->get(route('home'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('shop.payment_methods', 4)
                ->where('shop.payment_methods.1.key', 'komerce-va-bca')
                ->where('shop.payment_methods.1.title', 'BCA Virtual Account')
                ->where('shop.payment_methods.1.logo', fn ($logo) => is_string($logo) && str_contains($logo, 'images/payments/bca.svg'))
                ->where('shop.payment_methods.2.key', 'komerce-va-bri')
            );
    }

    public function test_footer_couriers_are_exactly_the_official_rajaongkir_couriers_checkout_quotes(): void
    {
        config()->set('komerce.couriers', ['jne', 'jnt', 'sicepat', 'ide']);

        foreach ([
            'manual' => ['Kurir Toko', 'manual'],
            'jne' => ['JNE Express', 'rajaongkir'],
            'jnt' => ['J&T Express', 'rajaongkir'],
            'sicepat' => ['SiCepat Ekspres', 'rajaongkir'],
            'gosend' => ['GoSend', 'rajaongkir'], // not a RajaOngkir Shipping Cost courier
            'ninja' => ['Ninja Xpress', 'rajaongkir'], // official, but not in RAJAONGKIR_COURIERS
        ] as $slug => [$name, $driver]) {
            Carrier::query()->create([
                'slug' => $slug,
                'name' => $name,
                'driver' => $driver,
                'is_enabled' => true,
            ]);
        }

        Carrier::query()->create([
            'slug' => 'ide',
            'name' => 'IDexpress',
            'driver' => 'rajaongkir',
            'is_enabled' => false, // configured, but disabled in cpanel
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('shop.shipping_couriers', 3)
                ->where('shop.shipping_couriers.0.code', 'jne')
                ->where('shop.shipping_couriers.0.label', 'JNE')
                ->where('shop.shipping_couriers.1.code', 'jnt')
                ->where('shop.shipping_couriers.1.label', 'J&T Express')
                ->where('shop.shipping_couriers.2.code', 'sicepat')
                ->where('shop.shipping_couriers.2.label', 'SiCepat')
            );
    }

    public function test_shipping_couriers_empty_when_komerce_disabled(): void
    {
        config()->set('komerce.enabled', false);

        Carrier::query()->create([
            'slug' => 'jne',
            'name' => 'JNE Express',
            'driver' => 'rajaongkir',
            'is_enabled' => true,
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('shop.shipping_couriers', 0)
            );
    }

    public function test_shipping_couriers_include_manual_when_komerce_disabled(): void
    {
        config()->set('komerce.enabled', false);

        Carrier::query()->create([
            'slug' => 'manual',
            'name' => 'Kurir Toko',
            'driver' => 'manual',
            'is_enabled' => true,
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('shop.shipping_couriers', 1)
                ->where('shop.shipping_couriers.0.code', 'manual')
            );
    }
}
