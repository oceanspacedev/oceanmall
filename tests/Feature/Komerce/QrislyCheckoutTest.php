<?php

declare(strict_types=1);

namespace Tests\Feature\Komerce;

use App\Actions\Checkout\FetchPaymentMethods;
use App\Actions\CreateOrder;
use App\Integrations\Komerce\Actions\Payment\CreateKomercePayment;
use App\Integrations\Komerce\Komerce;
use App\Integrations\Komerce\Support\KomerceCapabilities;
use App\Models\User;
use App\Payment\PaymentMethodCatalog;
use App\Support\CustomerCart;
use App\Support\CustomerCheckoutState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Shopper\Cart\Models\Cart;
use Shopper\Cart\Models\CartLine;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Country;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\OrderItem;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Core\Models\Product;
use Shopper\Core\Models\Zone;
use Shopper\Payment\Enum\TransactionStatus;
use Shopper\Payment\Models\PaymentTransaction;
use Tests\TestCase;

final class QrislyCheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_qrisly_enabled_requires_key_and_qris_id(): void
    {
        config()->set('komerce.enabled', null);
        config()->set('komerce.qrisly_api_key', '');
        config()->set('komerce.qrisly_qris_id', '');
        $this->assertFalse(Komerce::qrislyEnabled());

        config()->set('komerce.qrisly_api_key', 'qrisly-key');
        config()->set('komerce.qrisly_qris_id', '');
        $this->assertFalse(Komerce::qrislyEnabled());

        config()->set('komerce.qrisly_qris_id', '18');
        $this->assertTrue(Komerce::qrislyEnabled());
    }

    public function test_create_qris_uses_qrisly_when_enabled(): void
    {
        config()->set('komerce.payment_api_key', 'payment-key');
        config()->set('komerce.qrisly_api_key', 'qrisly-key');
        config()->set('komerce.qrisly_qris_id', '18');
        config()->set('komerce.qrisly_base_url', 'https://qrisly.example.test/user');
        config()->set('komerce.payment_base_url', 'https://payment.example.test/user');
        config()->set('komerce.webhook_secret', 'webhook-secret');

        $paymentMethod = PaymentMethod::factory()->create(['driver' => 'komerce', 'is_enabled' => true]);
        $order = Order::factory()->create([
            'number' => 'ORD-QRISLY-1',
            'price_amount' => 50000,
            'currency_code' => 'IDR',
            'payment_method_id' => $paymentMethod->id,
            'payment_status' => PaymentStatus::Pending,
            'status' => OrderStatus::New,
        ]);

        Http::fake([
            'https://qrisly.example.test/user/api/v1/qrisly/generate-qris' => Http::response([
                'success' => true,
                'data' => [
                    'history_id' => 9001,
                    'qris_string' => 'QRISLY-STRING',
                    'final_amount' => 50001,
                    'expiry_time' => '2026-08-04 23:00:00',
                ],
            ]),
            'https://payment.example.test/user/*' => Http::response(['success' => false], 500),
        ]);

        $instructions = resolve(CreateKomercePayment::class)->handle($order, [
            'payment_type' => 'qris',
            'driver' => 'komerce',
        ]);

        $this->assertSame('9001', $instructions['payment_id']);
        $this->assertSame('qrisly', $instructions['provider']);
        $this->assertSame('QRISLY-STRING', $instructions['qris_string']);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/qrisly/generate-qris')
            && $request->hasHeader('x-api-key', 'qrisly-key'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/payment/create'));

        $this->assertDatabaseHas((new PaymentTransaction)->getTable(), [
            'order_id' => $order->id,
            'reference' => '9001',
            'status' => TransactionStatus::Pending->value,
        ]);
    }

    public function test_create_qris_falls_back_to_payment_api_when_qrisly_key_empty(): void
    {
        config()->set('komerce.payment_api_key', 'payment-key');
        config()->set('komerce.qrisly_api_key', '');
        config()->set('komerce.qrisly_qris_id', '');
        config()->set('komerce.payment_base_url', 'https://payment.example.test/user');
        config()->set('komerce.webhook_secret', 'webhook-secret');

        $paymentMethod = PaymentMethod::factory()->create(['driver' => 'komerce', 'is_enabled' => true]);
        $customer = User::factory()->create();
        $order = Order::factory()->create([
            'number' => 'ORD-QRIS-FALLBACK',
            'price_amount' => 25000,
            'currency_code' => 'IDR',
            'payment_method_id' => $paymentMethod->id,
            'customer_id' => $customer->id,
            'payment_status' => PaymentStatus::Pending,
            'status' => OrderStatus::New,
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'name' => 'Produk OceanMall',
            'quantity' => 1,
            'unit_price_amount' => 25000,
        ]);

        Http::fake([
            'https://payment.example.test/user/api/v1/user/payment/create' => Http::response([
                'success' => true,
                'data' => [
                    'payment_id' => 'KOMPAY-QRIS-1',
                    'qris_string' => 'PAYMENT-API-QRIS',
                    'expiry_date' => '2026-08-04 23:00:00',
                    'amount' => 25000,
                ],
            ]),
        ]);

        $instructions = resolve(CreateKomercePayment::class)->handle($order, [
            'payment_type' => 'qris',
            'driver' => 'komerce',
        ]);

        $this->assertSame('KOMPAY-QRIS-1', $instructions['payment_id']);
        $this->assertSame('payment_api', $instructions['provider']);
        $this->assertSame('PAYMENT-API-QRIS', $instructions['qris_string']);
    }

    public function test_qrisly_only_fetch_payment_methods_and_checkout_enforce_1k_to_100m_bounds(): void
    {
        $this->withoutVite();
        Http::fake();

        config()->set('komerce.payment_api_key', '');
        config()->set('komerce.qrisly_api_key', 'qrisly-key');
        config()->set('komerce.qrisly_qris_id', '18');
        config()->set('komerce.qrisly_base_url', 'https://qrisly.example.test/user');

        $country = Country::factory()->create();
        $zone = Zone::factory()->create(['is_enabled' => true]);
        $zone->countries()->attach($country->id);

        $vaBca = PaymentMethod::factory()->create([
            'slug' => 'komerce-va-bca',
            'title' => 'BCA Virtual Account',
            'driver' => 'komerce',
            'is_enabled' => true,
            'metadata' => json_encode(['payment_type' => 'bank_transfer', 'channel_code' => 'BCA']),
        ]);
        $qris = PaymentMethod::factory()->create([
            'slug' => 'komerce-qris',
            'title' => 'QRIS',
            'driver' => 'komerce',
            'is_enabled' => true,
            'metadata' => json_encode(['payment_type' => 'qris']),
        ]);
        $zone->paymentMethods()->attach([$vaBca->id, $qris->id]);

        $methods = resolve(FetchPaymentMethods::class)->handle($country->id);

        $this->assertCount(1, $methods);
        $this->assertSame('komerce-qris', $methods[0]['slug']);
        $this->assertSame(1000, $methods[0]['min_amount']);
        $this->assertSame(100_000_000, $methods[0]['max_amount']);
        $this->assertNull(PaymentMethodCatalog::amountOutOfRange($methods[0], 1000));
        $this->assertNull(PaymentMethodCatalog::amountOutOfRange($methods[0], 100_000_000));
        Http::assertNothingSent();

        // Footer shows local QRIS title and local logo, excluding VA methods.
        $this->get(route('home'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('shop.payment_methods', 1)
                ->where('shop.payment_methods.0.key', 'komerce-qris')
                ->where('shop.payment_methods.0.title', 'QRIS')
                ->where('shop.payment_methods.0.logo', fn ($logo) => is_string($logo) && str_contains($logo, 'images/payments/qris.svg'))
            );

        $created = false;
        $this->app->instance(CreateOrder::class, new class($created)
        {
            public function __construct(private bool &$created) {}

            public function handle(): never
            {
                $this->created = true;

                throw new RuntimeException('CreateOrder must not run when amount is out of range.');
            }
        });

        $user = User::factory()->create();
        $product = Product::factory()->standard()->create(['published_at' => now()->subDay()]);

        foreach ([999 => 'minimal Rp1.000', 100_000_001 => 'maksimal Rp100.000.000'] as $badAmount => $expectedMessage) {
            // Web placeOrder rejects out-of-range before CreateOrder runs.
            $cart = Cart::query()->create(['currency_code' => 'IDR', 'customer_id' => $user->id]);
            CartLine::query()->create([
                'cart_id' => $cart->id,
                'purchasable_type' => $product->getMorphClass(),
                'purchasable_id' => $product->id,
                'quantity' => 1,
                'unit_price_amount' => $badAmount,
            ]);

            $webSession = [
                config('shopper.cart.session.key', 'shopper_cart') => $cart->id,
                'checkout' => [
                    'shipping_address' => [
                        'first_name' => 'Test',
                        'last_name' => 'User',
                        'street_address' => 'Jl. 1',
                        'postal_code' => '10110',
                        'city' => 'Jakarta',
                        'country_id' => $country->id,
                        'phone_number' => '081234567890',
                    ],
                    'shipping_option' => [['id' => 'FREE', 'name' => 'Free', 'price' => 0]],
                    'payment' => [['id' => $qris->id, 'driver' => 'komerce', 'payment_type' => 'qris', 'title' => 'QRIS']],
                ],
            ];

            $this->actingAs($user)
                ->withSession($webSession)
                ->from(route('shop.checkout.index'))
                ->post(route('shop.checkout.place-order'), ['payment_method_id' => $qris->id])
                ->assertRedirect(route('shop.checkout.index'))
                ->assertSessionHasErrors('payment');

            $this->assertStringContainsString($expectedMessage, (string) session('errors')->first('payment'));

            // API v1 placeOrder also rejects out-of-range before CreateOrder runs.
            Sanctum::actingAs($user);
            $apiCart = resolve(CustomerCart::class)->current($user);
            $apiCart->lines()->delete();
            CartLine::query()->create([
                'cart_id' => $apiCart->id,
                'purchasable_type' => $product->getMorphClass(),
                'purchasable_id' => $product->id,
                'quantity' => 1,
                'unit_price_amount' => $badAmount,
            ]);
            resolve(CustomerCheckoutState::class)->putShippingAddress($user, [
                'first_name' => 'Test',
                'last_name' => 'User',
                'street_address' => 'Jl. 1',
                'postal_code' => '10110',
                'city' => 'Jakarta',
                'country_id' => $country->id,
                'phone_number' => '081234567890',
            ]);
            resolve(CustomerCheckoutState::class)->putShippingOption($user, [[
                'id' => 'FREE',
                'name' => 'Free',
                'price' => 0,
                'amount' => 0,
            ]]);

            $this->postJson('/api/v1/checkout/place-order', ['payment_method_id' => $qris->id])
                ->assertStatus(422)
                ->assertJsonPath('message', fn (string $msg): bool => str_contains($msg, $expectedMessage));
        }

        $this->assertFalse($created);
        Http::assertNothingSent();
    }

    public function test_payment_plus_qrisly_overrides_higher_qris_bounds_and_handles_omitted_qris_and_transient_500(): void
    {
        config()->set('komerce.payment_api_key', 'payment-key');
        config()->set('komerce.payment_base_url', 'https://payment.example.test/user');
        config()->set('komerce.qrisly_api_key', 'qrisly-key');
        config()->set('komerce.qrisly_qris_id', '18');
        config()->set('komerce.qrisly_base_url', 'https://qrisly.example.test/user');

        $country = Country::factory()->create();
        $zone = Zone::factory()->create(['is_enabled' => true]);
        $zone->countries()->attach($country->id);

        $vaBca = PaymentMethod::factory()->create([
            'slug' => 'komerce-va-bca',
            'title' => 'BCA Virtual Account',
            'driver' => 'komerce',
            'is_enabled' => true,
            'metadata' => json_encode(['payment_type' => 'bank_transfer', 'channel_code' => 'BCA']),
        ]);
        $qris = PaymentMethod::factory()->create([
            'slug' => 'komerce-qris',
            'title' => 'QRIS',
            'driver' => 'komerce',
            'is_enabled' => true,
            'metadata' => json_encode(['payment_type' => 'qris']),
        ]);
        $zone->paymentMethods()->attach([$vaBca->id, $qris->id]);

        // Case A: /user/methods reports qris min_amount=10000 max_amount=10000000 -> aligned to 1000..100000000, preserving logo.
        Http::fake([
            'https://payment.example.test/user/api/v1/user/methods' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => [
                    ['payment_type' => 'va', 'bank_code' => 'BCA', 'display_name' => 'Bank Central Asia', 'logo_url' => 'https://example.test/bca.png', 'min_amount' => 10000],
                    ['payment_type' => 'qris', 'bank_code' => '', 'display_name' => 'QRIS Official', 'logo_url' => 'https://example.test/qris.png', 'min_amount' => 10000, 'max_amount' => 10000000],
                ],
            ]),
        ]);

        $methods = collect(resolve(FetchPaymentMethods::class)->handle($country->id))->keyBy('slug');
        $this->assertSame(1000, $methods['komerce-qris']['min_amount']);
        $this->assertSame(100_000_000, $methods['komerce-qris']['max_amount']);
        $this->assertSame('https://example.test/qris.png', $methods['komerce-qris']['logo']);
        $this->assertSame(10000, $methods['komerce-va-bca']['min_amount']);

        // Case B: /user/methods omits QRIS -> komerce-qris is still offered with 1000..100000000 alongside BCA.
        Cache::flush();
        Http::swap(new Factory);
        Http::fake([
            'https://payment.example.test/user/api/v1/user/methods' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => [
                    ['payment_type' => 'va', 'bank_code' => 'BCA', 'min_amount' => 10000],
                ],
            ]),
        ]);

        $methodsOmitted = collect(resolve(FetchPaymentMethods::class)->handle($country->id))->keyBy('slug');
        $this->assertTrue($methodsOmitted->has('komerce-va-bca'));
        $this->assertTrue($methodsOmitted->has('komerce-qris'));
        $this->assertSame(1000, $methodsOmitted['komerce-qris']['min_amount']);
        $this->assertSame(100_000_000, $methodsOmitted['komerce-qris']['max_amount']);

        // Case C: /user/methods returns transient 500 -> VA methods are NOT hidden AND bounds still apply.
        Cache::flush();
        Http::swap(new Factory);
        Http::fake([
            'https://payment.example.test/user/api/v1/user/methods' => Http::response(['message' => 'server error'], 500),
        ]);

        $methods500 = collect(resolve(FetchPaymentMethods::class)->handle($country->id))->keyBy('slug');
        $this->assertTrue($methods500->has('komerce-va-bca'));
        $this->assertSame(10000, $methods500['komerce-va-bca']['min_amount']);
        $this->assertTrue($methods500->has('komerce-qris'));
        $this->assertSame(1000, $methods500['komerce-qris']['min_amount']);
        $this->assertSame(100_000_000, $methods500['komerce-qris']['max_amount']);

        // Case D: Payment-only (QRISLY off) + transient 500 still enforces Payment API min 10,000 for QRIS & VA.
        Cache::flush();
        config()->set('komerce.qrisly_api_key', '');
        config()->set('komerce.qrisly_qris_id', '');
        $methodsPayOnly500 = collect(resolve(FetchPaymentMethods::class)->handle($country->id))->keyBy('slug');
        $this->assertSame(10000, $methodsPayOnly500['komerce-va-bca']['min_amount']);
        $this->assertSame(10000, $methodsPayOnly500['komerce-qris']['min_amount']);
        $this->assertStringContainsString('minimal Rp10.000', (string) PaymentMethodCatalog::amountOutOfRange($methodsPayOnly500['komerce-qris'], 5000));
    }

    public function test_qrisly_in_flight_401_403_and_400_invalid_api_key_fall_back_to_payment_api_for_10k_plus(): void
    {
        config()->set('komerce.payment_api_key', 'payment-key');
        config()->set('komerce.qrisly_api_key', 'qrisly-key');
        config()->set('komerce.qrisly_qris_id', '18');
        config()->set('komerce.qrisly_base_url', 'https://qrisly.example.test/user');
        config()->set('komerce.payment_base_url', 'https://payment.example.test/user');
        config()->set('komerce.webhook_secret', 'webhook-secret');

        $paymentMethod = PaymentMethod::factory()->create(['driver' => 'komerce', 'is_enabled' => true]);
        $customer = User::factory()->create([
            'first_name' => 'Sari',
            'last_name' => 'Wulandari',
            'email' => 'sari-fallback@example.com',
            'phone_number' => '081234567890',
        ]);

        $scenarios = [
            ['status' => 401, 'body' => ['meta' => ['message' => 'Unauthorized']], 'expect_disabled' => true],
            ['status' => 403, 'body' => ['meta' => ['message' => 'Forbidden']], 'expect_disabled' => true],
            ['status' => 400, 'body' => ['meta' => ['message' => 'Invalid API key']], 'expect_disabled' => true],
            ['status' => 400, 'body' => ['message' => 'Invalid API Key'], 'expect_disabled' => false],
        ];

        foreach ($scenarios as $index => $scenario) {
            Cache::flush();
            resolve(KomerceCapabilities::class)->forget();
            Http::swap(new Factory);

            $order = Order::factory()->create([
                'number' => 'ORD-QRISLY-FB-'.$index,
                'price_amount' => 25000,
                'currency_code' => 'IDR',
                'payment_method_id' => $paymentMethod->id,
                'customer_id' => $customer->id,
                'payment_status' => PaymentStatus::Pending,
                'status' => OrderStatus::New,
            ]);
            OrderItem::factory()->create([
                'order_id' => $order->id,
                'name' => 'Produk OceanMall',
                'quantity' => 1,
                'unit_price_amount' => 25000,
            ]);

            Http::fake([
                'https://qrisly.example.test/user/api/v1/qrisly/generate-qris' => Http::response($scenario['body'], $scenario['status']),
                'https://payment.example.test/user/api/v1/user/payment/create' => Http::response([
                    'success' => true,
                    'data' => [
                        'payment_id' => 'KOMPAY-FALLBACK-'.$index,
                        'qris_string' => 'PAYMENT-API-QRIS-'.$index,
                        'expiry_date' => '2026-08-04 23:00:00',
                        'amount' => 25000,
                    ],
                ], 200),
            ]);

            $instructions = resolve(CreateKomercePayment::class)->handle($order, [
                'payment_type' => 'qris',
                'driver' => 'komerce',
            ]);

            $this->assertSame('KOMPAY-FALLBACK-'.$index, $instructions['payment_id']);
            $this->assertSame('payment_api', $instructions['provider']);
            $this->assertSame('PAYMENT-API-QRIS-'.$index, $instructions['qris_string']);

            if ($scenario['expect_disabled']) {
                $this->assertFalse(Komerce::qrislyEnabled());
            }

            $this->assertSame(1, PaymentTransaction::query()->where('order_id', $order->id)->count());
            $this->assertDatabaseHas((new PaymentTransaction)->getTable(), [
                'order_id' => $order->id,
                'reference' => 'KOMPAY-FALLBACK-'.$index,
                'status' => TransactionStatus::Pending->value,
            ]);
        }
    }

    public function test_qrisly_in_flight_401_rejects_under_10k_without_calling_payment_api_or_persisting(): void
    {
        config()->set('komerce.payment_api_key', 'payment-key');
        config()->set('komerce.qrisly_api_key', 'qrisly-key');
        config()->set('komerce.qrisly_qris_id', '18');
        config()->set('komerce.qrisly_base_url', 'https://qrisly.example.test/user');
        config()->set('komerce.payment_base_url', 'https://payment.example.test/user');

        $paymentMethod = PaymentMethod::factory()->create(['driver' => 'komerce', 'is_enabled' => true]);
        $customer = User::factory()->create([
            'first_name' => 'Budi',
            'last_name' => 'Santoso',
            'email' => 'budi-5k@example.com',
            'phone_number' => '081234567890',
        ]);
        $order = Order::factory()->create([
            'number' => 'ORD-QRISLY-5K',
            'price_amount' => 5000,
            'currency_code' => 'IDR',
            'payment_method_id' => $paymentMethod->id,
            'customer_id' => $customer->id,
            'payment_status' => PaymentStatus::Pending,
            'status' => OrderStatus::New,
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'name' => 'Produk Kecil',
            'quantity' => 1,
            'unit_price_amount' => 5000,
        ]);

        Http::fake([
            'https://qrisly.example.test/user/api/v1/qrisly/generate-qris' => Http::response(['meta' => ['message' => 'Unauthorized']], 401),
            'https://payment.example.test/user/*' => Http::response(['success' => true], 200),
        ]);

        try {
            resolve(CreateKomercePayment::class)->handle($order, [
                'payment_type' => 'qris',
                'driver' => 'komerce',
            ]);
            $this->fail('Expected Payment API minimum error for Rp 5,000.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Rp10,000', $e->getMessage());
        }

        $this->assertFalse(Komerce::qrislyEnabled());
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/payment/create'));
        $this->assertSame(0, PaymentTransaction::query()->where('order_id', $order->id)->count());
    }

    public function test_qrisly_in_flight_401_rethrows_when_payment_api_disabled_and_500_does_not_fallback(): void
    {
        config()->set('komerce.payment_api_key', '');
        config()->set('komerce.qrisly_api_key', 'qrisly-key');
        config()->set('komerce.qrisly_qris_id', '18');
        config()->set('komerce.qrisly_base_url', 'https://qrisly.example.test/user');

        $paymentMethod = PaymentMethod::factory()->create(['driver' => 'komerce', 'is_enabled' => true]);
        $order = Order::factory()->create([
            'number' => 'ORD-QRISLY-NO-FALLBACK',
            'price_amount' => 25000,
            'currency_code' => 'IDR',
            'payment_method_id' => $paymentMethod->id,
            'payment_status' => PaymentStatus::Pending,
            'status' => OrderStatus::New,
        ]);

        Http::fake([
            'https://qrisly.example.test/user/api/v1/qrisly/generate-qris' => Http::response(['message' => 'Unauthorized'], 401),
        ]);

        try {
            resolve(CreateKomercePayment::class)->handle($order, [
                'payment_type' => 'qris',
                'driver' => 'komerce',
            ]);
            $this->fail('Expected RequestException when Payment API is disabled.');
        } catch (RequestException $e) {
            $this->assertSame(401, $e->response->status());
        }

        $this->assertFalse(Komerce::qrislyEnabled());

        // Transient 500 does NOT trigger fallback even when Payment API is enabled.
        Cache::flush();
        resolve(KomerceCapabilities::class)->forget();
        Http::swap(new Factory);
        config()->set('komerce.payment_api_key', 'payment-key');
        config()->set('komerce.payment_base_url', 'https://payment.example.test/user');

        Http::fake([
            'https://qrisly.example.test/user/api/v1/qrisly/generate-qris' => Http::response(['message' => 'Server Error'], 500),
            'https://payment.example.test/user/*' => Http::response(['success' => true], 200),
        ]);

        try {
            resolve(CreateKomercePayment::class)->handle($order, [
                'payment_type' => 'qris',
                'driver' => 'komerce',
            ]);
            $this->fail('Expected RequestException on QRISLY 500.');
        } catch (RequestException $e) {
            $this->assertSame(500, $e->response->status());
        }

        $this->assertTrue(Komerce::qrislyEnabled());
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/payment/create'));
    }

    public function test_qris_over_100m_uses_payment_api_when_dual_enabled_and_throws_runtime_exception_when_qrisly_only(): void
    {
        config()->set('komerce.payment_api_key', 'payment-key');
        config()->set('komerce.qrisly_api_key', 'qrisly-key');
        config()->set('komerce.qrisly_qris_id', '18');
        config()->set('komerce.qrisly_base_url', 'https://qrisly.example.test/user');
        config()->set('komerce.payment_base_url', 'https://payment.example.test/user');
        config()->set('komerce.webhook_secret', 'webhook-secret');

        $paymentMethod = PaymentMethod::factory()->create(['driver' => 'komerce', 'is_enabled' => true]);
        $customer = User::factory()->create([
            'first_name' => 'Andi',
            'last_name' => 'Wijaya',
            'email' => 'andi-100m@example.com',
            'phone_number' => '081234567890',
        ]);
        $order = Order::factory()->create([
            'number' => 'ORD-QRIS-100M-DIRECT',
            'price_amount' => 100_000_001,
            'currency_code' => 'IDR',
            'payment_method_id' => $paymentMethod->id,
            'customer_id' => $customer->id,
            'payment_status' => PaymentStatus::Pending,
            'status' => OrderStatus::New,
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'name' => 'Produk Premium',
            'quantity' => 1,
            'unit_price_amount' => 100_000_001,
        ]);

        Http::fake([
            'https://payment.example.test/user/api/v1/user/payment/create' => Http::response([
                'success' => true,
                'data' => [
                    'payment_id' => 'KOMPAY-100M-1',
                    'qris_string' => 'PAYMENT-API-100M',
                    'expiry_date' => '2026-08-05T19:00:00+07:00',
                    'amount' => 100_000_001,
                ],
            ], 200),
            'https://qrisly.example.test/user/*' => Http::response(['success' => false], 500),
        ]);

        $instructions = resolve(CreateKomercePayment::class)->handle($order, [
            'payment_type' => 'qris',
            'driver' => 'komerce',
        ]);

        $this->assertSame('payment_api', $instructions['provider']);
        $this->assertSame('KOMPAY-100M-1', $instructions['payment_id']);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/qrisly/'));

        // When Payment API is disabled and only QRISLY is enabled, > 100M fails cleanly with RuntimeException before HTTP.
        config()->set('komerce.payment_api_key', '');
        Http::swap(new Factory);
        Http::fake();

        try {
            resolve(CreateKomercePayment::class)->handle($order, [
                'payment_type' => 'qris',
                'driver' => 'komerce',
            ]);
            $this->fail('Expected RuntimeException for QRISLY > 100M.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('100,000,000', $e->getMessage());
        }

        Http::assertNothingSent();
    }
}
