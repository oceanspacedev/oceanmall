<?php

declare(strict_types=1);

namespace Tests\Feature\Komerce\Checkout;

use App\Actions\Checkout\BuildShippingPackages;
use App\Actions\Checkout\FetchDeliveryRates;
use App\Actions\Checkout\FetchPaymentMethods;
use App\Actions\CreateOrder;
use App\Integrations\Komerce\Actions\Payment\CreateKomercePayment;
use App\Integrations\Komerce\Actions\Payment\MarkOrderPaidFromKomerce;
use App\Integrations\Komerce\Exceptions\KomercePaidAfterCancellation;
use App\Integrations\Komerce\Jobs\CreateRajaOngkirDeliveryForShipment;
use App\Integrations\Komerce\Support\KomerceCapabilities;
use App\Jobs\FulfillOrderShipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Shopper\Cart\CartManager;
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
use Shopper\Payment\Enum\TransactionType;
use Shopper\Payment\Models\PaymentTransaction;
use Tests\Feature\Komerce\Concerns\SignsKomercePaymentCallbacks;
use Tests\TestCase;

final class KomerceCheckoutTest extends TestCase
{
    use RefreshDatabase;
    use SignsKomercePaymentCallbacks;

    protected function setUp(): void
    {
        parent::setUp();

        // Raw X-Inertia requests get a 409 (asset-version mismatch) once a Vite
        // manifest exists, because they carry no version. Send the version the
        // Inertia middleware computes so these requests are accepted.
        $manifest = public_path('build/manifest.json');
        $this->withHeader(
            'X-Inertia-Version',
            file_exists($manifest) ? hash_file('xxh128', $manifest) : '',
        );
    }

    private function komerceFakeConfig(): void
    {
        config()->set('komerce.payment_api_key', 'test-komerce-key');
        config()->set('komerce.payment_base_url', 'https://payment.example.test/user');
        config()->set('komerce.webhook_secret', 'webhook-secret');
        config()->set('shopper.payment.drivers.stripe.enabled', false);
    }

    private function seedCountryZoneAndPaymentMethod(string $driver = 'komerce', array $metadata = []): array
    {
        $country = Country::factory()->create();
        $zone = Zone::factory()->create(['is_enabled' => true]);
        $zone->countries()->attach($country->id);

        $effectiveMeta = $metadata ?: ['channel_code' => 'BCA', 'payment_type' => 'bank_transfer'];

        $paymentMethod = PaymentMethod::factory()->create([
            'title' => 'BCA Virtual Account',
            'driver' => $driver,
            'is_enabled' => true,
            'metadata' => json_encode($effectiveMeta),
        ]);
        $zone->paymentMethods()->attach($paymentMethod->id);

        return [$country, $zone, $paymentMethod];
    }

    private function makeCheckoutSession(int $countryId, int $paymentMethodId, array $extra = []): array
    {
        return [
            'shipping_address' => [
                'first_name' => 'Budi',
                'last_name' => 'Santoso',
                'street_address' => 'Jl. Merdeka 1',
                'postal_code' => '10110',
                'city' => 'Jakarta',
                'country_id' => $countryId,
                'phone_number' => '081234567890',
            ],
            'shipping_option' => [
                [
                    'id' => 'JNE-REG',
                    'name' => 'JNE Reguler',
                    'price' => 15000,
                    'service_code' => 'JNE-REG',
                    'carrier_code' => 'JNE',
                    'currency' => 'IDR',
                    'estimated_days' => 3,
                ],
            ],
            'payment' => [
                array_merge([
                    'id' => $paymentMethodId,
                    'driver' => 'komerce',
                    'channel_code' => 'BCA',
                    'payment_type' => 'bank_transfer',
                    'title' => 'BCA Virtual Account',
                ], $extra),
            ],
        ];
    }

    private function makeOrderPayable(Order $order, ?int $unitPrice = null): void
    {
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'name' => 'Produk OceanMall',
            'quantity' => 1,
            'unit_price_amount' => $unitPrice ?? (int) $order->price_amount,
        ]);
    }

    public function test_checkout_keeps_place_order_available_for_every_non_stripe_driver(): void
    {
        $checkoutPage = file_get_contents(resource_path('js/pages/shop/checkout.vue'));

        $this->assertIsString($checkoutPage);
        $this->assertStringContainsString('const canPlaceOrder = computed<boolean>', $checkoutPage);
        // Instructions are shown after the order exists (success / order page),
        // so placing the order never waits for a provider-specific prop.
        $this->assertMatchesRegularExpression(
            '/Boolean\(currentPaymentMethod\.value\)\s*&&\s*!isInPageSelected\.value/s',
            $checkoutPage,
        );
        // Confirm-in-page gateways are a neutral flag + paymentAction kind, not per-vendor props.
        $this->assertStringNotContainsString('stripeData', $checkoutPage);
        $this->assertStringNotContainsString("driver === 'stripe'", $checkoutPage);
        $this->assertStringContainsString('v-if="canPlaceOrder"', $checkoutPage);
        $this->assertStringNotContainsString('komercePayment', $checkoutPage);
        $this->assertStringNotContainsString('isKomerceSelected', $checkoutPage);
        // Only methods the server flags confirm_in_page hide the place-order button.
        $this->assertMatchesRegularExpression(
            '/Boolean\(currentPaymentMethod\.value\?\.confirm_in_page\)/s',
            $checkoutPage,
        );
    }

    public function test_checkout_renders_selected_komerce_without_payment_instructions(): void
    {
        $this->komerceFakeConfig();
        $this->fakeKomercePaymentHttp();

        $user = User::factory()->create();
        [$country, , $paymentMethod] = $this->seedCountryZoneAndPaymentMethod();

        $cart = Cart::query()->create([
            'currency_code' => 'IDR',
            'customer_id' => $user->id,
        ]);
        $product = Product::factory()->standard()->create(['name' => 'Batik Shirt']);
        CartLine::query()->create([
            'cart_id' => $cart->id,
            'purchasable_type' => $product->getMorphClass(),
            'purchasable_id' => $product->id,
            'quantity' => 1,
            'unit_price_amount' => 100000,
        ]);

        $this->app->instance(BuildShippingPackages::class, new class
        {
            public function handle(): array
            {
                return [];
            }
        });
        $this->app->instance(FetchDeliveryRates::class, new class
        {
            public function handle(): array
            {
                return [[
                    'id' => 'JNE-REG',
                    'service_code' => 'JNE-REG',
                    'service_name' => 'JNE Reguler',
                    'carrier_code' => 'JNE',
                    'carrier_name' => 'JNE',
                    'carrier_logo' => null,
                    'description' => null,
                    'estimated_days' => 3,
                    'amount' => 15000,
                    'currency' => 'IDR',
                ]];
            }
        });
        $this->app->instance(CartManager::class, new class
        {
            public function calculate(Cart $cart): array
            {
                return [
                    'total' => 100000,
                    'taxTotal' => 0,
                    'discountTotal' => 0,
                ];
            }
        });

        $this->actingAs($user)
            ->withSession([
                config('shopper.cart.session.key', 'shopper_cart') => $cart->id,
                'checkout' => $this->makeCheckoutSession($country->id, $paymentMethod->id),
            ])
            ->get(route('shop.checkout.index', ['step' => 3]), ['X-Inertia' => 'true'])
            ->assertOk()
            ->assertHeader('X-Inertia', 'true')
            ->assertJsonPath('component', 'shop/checkout')
            ->assertJsonPath('props.selectedPaymentMethod', $paymentMethod->id)
            ->assertJsonMissingPath('props.komercePayment')
            ->assertJsonPath('props.paymentAction', null);
    }

    public function test_place_order_creates_komerce_va_and_pending_transaction(): void
    {
        $this->komerceFakeConfig();

        $user = User::factory()->create([
            'first_name' => 'Budi',
            'last_name' => 'Santoso',
            'email' => 'budi@example.test',
        ]);

        [$country, , $paymentMethod] = $this->seedCountryZoneAndPaymentMethod();

        $order = Order::factory()->create([
            'number' => 'ORD-TEST-001',
            'price_amount' => 150000,
            'currency_code' => 'IDR',
            'customer_id' => $user->id,
            'payment_method_id' => $paymentMethod->id,
            'payment_status' => PaymentStatus::Pending,
            'status' => OrderStatus::New,
        ]);
        $this->makeOrderPayable($order);

        $this->app->instance(CreateOrder::class, new class($order)
        {
            public function __construct(private readonly Order $order) {}

            public function handle(): Order
            {
                return $this->order;
            }
        });

        $this->fakeKomercePaymentHttp([
            'https://payment.example.test/user/api/v1/user/payment/create' => Http::response([
                'success' => true,
                'data' => [
                    'payment_id' => 'KOMPAY-VA-001',
                    'virtual_account_number' => '8808001234567890',
                    'bank_code' => 'BCA',
                    'expiry_date' => '2026-08-04 23:59:59',
                    'amount' => 150000,
                ],
            ]),
        ]);

        $session = ['checkout' => $this->makeCheckoutSession($country->id, $paymentMethod->id)];

        $response = $this->actingAs($user)
            ->withSession($session)
            ->post(route('shop.checkout.place-order'), [
                'payment_method_id' => $paymentMethod->id,
            ]);

        $response->assertRedirect(route('shop.checkout.success', ['order' => $order->id]));

        $this->assertDatabaseHas(
            (new PaymentTransaction)->getTable(),
            [
                'order_id' => $order->id,
                'driver' => 'komerce',
                'reference' => 'KOMPAY-VA-001',
                'status' => TransactionStatus::Pending->value,
                'type' => TransactionType::Initiate->value,
            ],
        );

        $order->refresh();
        $this->assertSame(PaymentStatus::Pending, $order->payment_status);

        Http::assertSent(function (ClientRequest $req): bool {
            return str_contains($req->url(), '/api/v1/user/payment/create')
                && $req->method() === 'POST'
                && $req->hasHeader('x-api-key', 'test-komerce-key');
        });
    }

    public function test_place_order_creates_komerce_qris_payment(): void
    {
        $this->komerceFakeConfig();

        $user = User::factory()->create(['first_name' => 'Sari', 'last_name' => 'Wulandari', 'email' => 'sari@example.test']);

        [$country, , $paymentMethod] = $this->seedCountryZoneAndPaymentMethod(
            'komerce',
            ['payment_type' => 'qris'],
        );

        $order = Order::factory()->create([
            'number' => 'ORD-TEST-002',
            'price_amount' => 200000,
            'currency_code' => 'IDR',
            'customer_id' => $user->id,
            'payment_method_id' => $paymentMethod->id,
            'payment_status' => PaymentStatus::Pending,
            'status' => OrderStatus::New,
        ]);
        $this->makeOrderPayable($order);

        $this->app->instance(CreateOrder::class, new class($order)
        {
            public function __construct(private readonly Order $order) {}

            public function handle(): Order
            {
                return $this->order;
            }
        });

        $this->fakeKomercePaymentHttp([
            'https://payment.example.test/user/api/v1/user/payment/create' => Http::response([
                'success' => true,
                'data' => [
                    'payment_id' => 'KOMPAY-QRIS-001',
                    'qris_string' => '00020101021226640013...',
                    'expiry_date' => '2026-08-04 23:59:59',
                    'amount' => 200000,
                ],
            ]),
        ]);

        $session = [
            'checkout' => $this->makeCheckoutSession($country->id, $paymentMethod->id, [
                'payment_type' => 'qris',
                'channel_code' => null,
            ]),
        ];

        $response = $this->actingAs($user)
            ->withSession($session)
            ->post(route('shop.checkout.place-order'), [
                'payment_method_id' => $paymentMethod->id,
            ]);

        $response->assertRedirect(route('shop.checkout.success', ['order' => $order->id]));

        $this->assertDatabaseHas(
            (new PaymentTransaction)->getTable(),
            [
                'order_id' => $order->id,
                'driver' => 'komerce',
                'reference' => 'KOMPAY-QRIS-001',
                'status' => TransactionStatus::Pending->value,
            ],
        );
    }

    public function test_place_order_komerce_then_webhook_marks_paid(): void
    {
        $this->komerceFakeConfig();

        $user = User::factory()->create(['first_name' => 'Andi', 'last_name' => 'Prasetyo', 'email' => 'andi@example.test']);

        [$country, , $paymentMethod] = $this->seedCountryZoneAndPaymentMethod();

        $order = Order::factory()->create([
            'number' => 'ORD-TEST-003',
            'price_amount' => 175000,
            'currency_code' => 'IDR',
            'customer_id' => $user->id,
            'payment_method_id' => $paymentMethod->id,
            'payment_status' => PaymentStatus::Pending,
            'status' => OrderStatus::New,
        ]);
        $this->makeOrderPayable($order);

        $this->app->instance(CreateOrder::class, new class($order)
        {
            public function __construct(private readonly Order $order) {}

            public function handle(): Order
            {
                return $this->order;
            }
        });

        $this->fakeKomercePaymentHttp([
            'https://payment.example.test/user/api/v1/user/payment/create' => Http::response([
                'success' => true,
                'data' => [
                    'payment_id' => 'KOMPAY-E2E-001',
                    'virtual_account_number' => '8808009876543210',
                    'bank_code' => 'BCA',
                    'expiry_date' => '2026-08-04 23:59:59',
                    'amount' => 175000,
                ],
            ]),
            'https://payment.example.test/user/api/v1/user/payment/status/KOMPAY-E2E-001' => Http::response([
                'success' => true,
                'data' => [
                    'payment_id' => 'KOMPAY-E2E-001',
                    'status' => 'PAID',
                    'amount' => 175000,
                ],
            ]),
        ]);

        // Step 1: place order
        $this->actingAs($user)
            ->withSession(['checkout' => $this->makeCheckoutSession($country->id, $paymentMethod->id)])
            ->post(route('shop.checkout.place-order'), ['payment_method_id' => $paymentMethod->id])
            ->assertRedirect(route('shop.checkout.success', ['order' => $order->id]));

        $this->assertDatabaseHas(
            (new PaymentTransaction)->getTable(),
            ['order_id' => $order->id, 'reference' => 'KOMPAY-E2E-001', 'status' => TransactionStatus::Pending->value],
        );

        // Step 2: webhook callback marks paid
        $this->postSignedKomercePaymentWebhook([
            'payment_id' => 'KOMPAY-E2E-001',
            'order_id' => 'ORD-TEST-003',
            'status' => 'PAID',
            'amount' => 175000,
        ])
            ->assertOk()
            ->assertJson(['status' => 'handled']);

        $order->refresh();
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame(OrderStatus::Processing, $order->status);

        $transaction = PaymentTransaction::query()
            ->where('order_id', $order->id)
            ->where('reference', 'KOMPAY-E2E-001')
            ->firstOrFail();
        $this->assertSame(TransactionStatus::Success, $transaction->status);
        $this->assertSame(TransactionType::Capture, $transaction->type);
    }

    public function test_web_place_order_refuses_a_method_below_its_minimum_before_the_order_exists(): void
    {
        $this->komerceFakeConfig();
        $user = User::factory()->create();
        [$country, , $paymentMethod] = $this->seedCountryZoneAndPaymentMethod();
        $this->fakeKomercePaymentHttp();

        $created = false;
        $this->app->instance(CreateOrder::class, new class($created)
        {
            public function __construct(private bool &$created) {}

            public function handle(): never
            {
                $this->created = true;

                throw new RuntimeException('The order must not be created.');
            }
        });

        // A Rp5.000 cart with free shipping: below the channel's Rp10.000 minimum.
        $product = Product::factory()->standard()->create(['published_at' => now()->subDay()]);
        $cart = Cart::query()->create(['currency_code' => 'IDR', 'customer_id' => $user->id]);
        CartLine::query()->create([
            'cart_id' => $cart->id,
            'purchasable_type' => $product->getMorphClass(),
            'purchasable_id' => $product->id,
            'quantity' => 1,
            'unit_price_amount' => 5000,
        ]);
        $session = $this->makeCheckoutSession($country->id, $paymentMethod->id);
        $session['shipping_option'][0]['price'] = 0;

        $this->actingAs($user)
            ->withSession(['checkout' => $session, config('shopper.cart.session.key', 'shopper_cart') => $cart->id])
            ->from(route('shop.checkout.index'))
            ->post(route('shop.checkout.place-order'), ['payment_method_id' => $paymentMethod->id])
            ->assertRedirect(route('shop.checkout.index'))
            ->assertSessionHasErrors('payment');

        $this->assertFalse($created);
        $this->assertStringContainsString('minimal Rp10.000', (string) session('errors')->first('payment'));
    }

    public function test_a_paid_callback_that_cannot_be_verified_is_answered_with_an_error_so_it_is_retried(): void
    {
        $this->komerceFakeConfig();
        $user = User::factory()->create();
        [, , $paymentMethod] = $this->seedCountryZoneAndPaymentMethod();

        $order = Order::factory()->create([
            'number' => 'ORD-TEST-RETRY',
            'price_amount' => 175000,
            'currency_code' => 'IDR',
            'customer_id' => $user->id,
            'payment_method_id' => $paymentMethod->id,
            'payment_status' => PaymentStatus::Pending,
            'status' => OrderStatus::New,
        ]);
        PaymentTransaction::query()->create([
            'order_id' => $order->id,
            'reference' => 'KOMPAY-RETRY-1',
            'driver' => 'komerce',
            'type' => TransactionType::Initiate,
            'amount' => 175000,
            'currency_code' => 'IDR',
            'status' => TransactionStatus::Pending,
        ]);

        $status = 'https://payment.example.test/user/api/v1/user/payment/status/KOMPAY-RETRY-1';
        $callback = ['payment_id' => 'KOMPAY-RETRY-1', 'order_id' => 'ORD-TEST-RETRY', 'status' => 'PAID', 'amount' => 175000];

        // The status API is down: the callback must come again.
        $this->fakeKomercePaymentHttp([$status => Http::response(['message' => 'down'], 500)]);
        $this->postSignedKomercePaymentWebhook($callback)->assertStatus(503)->assertJson(['status' => 'lookup_failed']);
        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);

        // The status API still answers "pending" (and that answer is cached).
        Http::swap(new Factory);
        $this->fakeKomercePaymentHttp([$status => Http::response(['success' => true, 'data' => ['payment_id' => 'KOMPAY-RETRY-1', 'status' => 'PENDING', 'amount' => 175000]])]);
        $this->postSignedKomercePaymentWebhook($callback)->assertStatus(503)->assertJson(['status' => 'not_paid']);

        // The retry right after reads the status fresh, not the cached "pending".
        Http::swap(new Factory);
        $this->fakeKomercePaymentHttp([$status => Http::response(['success' => true, 'data' => ['payment_id' => 'KOMPAY-RETRY-1', 'status' => 'PAID', 'amount' => 175000]])]);
        $this->postSignedKomercePaymentWebhook($callback)->assertOk()->assertJson(['status' => 'handled']);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
    }

    public function test_fetch_payment_methods_excludes_stripe_when_disabled(): void
    {
        $this->komerceFakeConfig();
        $this->fakeKomercePaymentHttp();

        $country = Country::factory()->create();
        $zone = Zone::factory()->create(['is_enabled' => true]);
        $zone->countries()->attach($country->id);

        $komerce = PaymentMethod::factory()->create([
            'driver' => 'komerce',
            'is_enabled' => true,
            'metadata' => json_encode(['channel_code' => 'BCA', 'payment_type' => 'bank_transfer']),
        ]);
        $stripe = PaymentMethod::factory()->create([
            'driver' => 'stripe',
            'is_enabled' => true,
        ]);
        $zone->paymentMethods()->attach([$komerce->id, $stripe->id]);

        $methods = resolve(FetchPaymentMethods::class)->handle($country->id);

        $drivers = array_column($methods, 'driver');
        $this->assertContains('komerce', $drivers);
        $this->assertNotContains('stripe', $drivers);
    }

    public function test_fetch_payment_methods_keeps_only_official_komerce_catalog_channels(): void
    {
        $this->komerceFakeConfig();

        $this->fakeKomercePaymentHttp();

        $country = Country::factory()->create();
        $zone = Zone::factory()->create(['is_enabled' => true]);
        $zone->countries()->attach($country->id);

        $bca = PaymentMethod::factory()->create([
            'driver' => 'komerce',
            'is_enabled' => true,
            'metadata' => json_encode(['channel_code' => 'BCA', 'payment_type' => 'bank_transfer']),
        ]);
        $bri = PaymentMethod::factory()->create([
            'driver' => 'komerce',
            'is_enabled' => true,
            'metadata' => json_encode(['channel_code' => 'BRIVA', 'payment_type' => 'bank_transfer']),
        ]);
        $zone->paymentMethods()->attach([$bca->id, $bri->id]);

        $methods = resolve(FetchPaymentMethods::class)->handle($country->id);
        $channels = array_column($methods, 'channel_code');

        $this->assertContains('BCA', $channels);
        $this->assertNotContains('BRIVA', $channels);
        $this->assertSame(10000, $methods[0]['min_amount']);
    }

    public function test_fetch_payment_methods_includes_stripe_when_enabled(): void
    {
        config()->set('komerce.payment_api_key', 'test-komerce-key');
        config()->set('komerce.payment_base_url', 'https://payment.example.test/user');
        config()->set('shopper.payment.drivers.stripe.enabled', true);
        config()->set('shopper.stripe.secret_key', 'sk_test_oceanmall');
        // One gateway sells at a time; next to a keyed Komerce, Stripe has to
        // be named (App\Support\ActiveProvider).
        config()->set('shopper.payment.provider', 'stripe');
        $this->fakeKomercePaymentHttp();

        $country = Country::factory()->create();
        $zone = Zone::factory()->create(['is_enabled' => true]);
        $zone->countries()->attach($country->id);

        $stripe = PaymentMethod::factory()->create([
            'driver' => 'stripe',
            'is_enabled' => true,
        ]);
        $zone->paymentMethods()->attach([$stripe->id]);

        $methods = resolve(FetchPaymentMethods::class)->handle($country->id);
        $drivers = array_column($methods, 'driver');

        $this->assertContains('stripe', $drivers);
    }

    public function test_fetch_payment_methods_excludes_unconfigured_default_drivers(): void
    {
        $this->komerceFakeConfig();
        $this->fakeKomercePaymentHttp();

        $country = Country::factory()->create();
        $zone = Zone::factory()->create(['is_enabled' => true]);
        $zone->countries()->attach($country->id);

        $manual = PaymentMethod::factory()->create([
            'driver' => 'manual',
            'is_enabled' => true,
        ]);
        $paypal = PaymentMethod::factory()->create([
            'driver' => 'paypal',
            'is_enabled' => true,
        ]);
        $zone->paymentMethods()->attach([$manual->id, $paypal->id]);

        $methods = resolve(FetchPaymentMethods::class)->handle($country->id);
        $drivers = array_column($methods, 'driver');

        $this->assertContains('manual', $drivers);
        $this->assertNotContains('paypal', $drivers);
    }

    public function test_place_order_rejects_stripe_driver_with_error(): void
    {
        // Stripe IS enabled in the zone, but placeOrder should reject the stripe path.
        config()->set('shopper.payment.drivers.stripe.enabled', true);
        config()->set('shopper.stripe.secret_key', 'sk_test_oceanmall');
        config()->set('shopper.payment.provider', 'stripe');
        config()->set('komerce.payment_api_key', 'test-komerce-key');
        config()->set('komerce.payment_base_url', 'https://payment.example.test/user');
        $this->fakeKomercePaymentHttp();

        $user = User::factory()->create();

        $country = Country::factory()->create();
        $zone = Zone::factory()->create(['is_enabled' => true]);
        $zone->countries()->attach($country->id);

        $stripeMethod = PaymentMethod::factory()->create(['driver' => 'stripe', 'is_enabled' => true]);
        $zone->paymentMethods()->attach($stripeMethod->id);

        $session = [
            'checkout' => [
                'shipping_address' => ['country_id' => $country->id, 'first_name' => 'Test', 'last_name' => 'User', 'street_address' => 'Jl. 1', 'postal_code' => '10110', 'city' => 'Jakarta'],
                'shipping_option' => [['id' => 'JNE-REG', 'name' => 'JNE', 'price' => 15000]],
                'payment' => [['id' => $stripeMethod->id, 'driver' => 'stripe', 'title' => 'Stripe']],
            ],
        ];

        $this->actingAs($user)
            ->withSession($session)
            ->post(route('shop.checkout.place-order'), ['payment_method_id' => $stripeMethod->id])
            ->assertSessionHasErrors('payment');
    }

    public function test_create_komerce_payment_action_builds_va_payload_correctly(): void
    {
        $this->komerceFakeConfig();

        $user = User::factory()->create(['first_name' => 'Test', 'last_name' => 'User', 'email' => 'test@example.test']);

        $paymentMethod = PaymentMethod::factory()->create(['driver' => 'komerce', 'is_enabled' => true]);

        $order = Order::factory()->create([
            'number' => 'ORD-ACTION-001',
            'price_amount' => 100000,
            'currency_code' => 'IDR',
            'customer_id' => $user->id,
            'payment_method_id' => $paymentMethod->id,
            'payment_status' => PaymentStatus::Pending,
            'status' => OrderStatus::New,
        ]);
        $this->makeOrderPayable($order);

        $this->fakeKomercePaymentHttp([
            'https://payment.example.test/user/api/v1/user/payment/create' => Http::response([
                'success' => true,
                'data' => [
                    'payment_id' => 'KOMPAY-ACTION-001',
                    'virtual_account_number' => '8808001111111111',
                    'bank_code' => 'BCA',
                    'expiry_date' => '2026-08-04 23:59:59',
                    'amount' => 100000,
                ],
            ]),
        ]);

        $instructions = resolve(CreateKomercePayment::class)->handle($order, [
            'id' => $paymentMethod->id,
            'driver' => 'komerce',
            'channel_code' => 'BCA',
            'payment_type' => 'bank_transfer',
        ]);

        $this->assertSame('KOMPAY-ACTION-001', $instructions['payment_id']);
        $this->assertSame('bank_transfer', $instructions['payment_type']);
        $this->assertSame('8808001111111111', $instructions['virtual_account_number']);
        $this->assertSame('BCA', $instructions['bank_code']);

        $this->assertDatabaseHas(
            (new PaymentTransaction)->getTable(),
            [
                'order_id' => $order->id,
                'driver' => 'komerce',
                'reference' => 'KOMPAY-ACTION-001',
                'status' => TransactionStatus::Pending->value,
            ],
        );

        Http::assertSent(function (ClientRequest $req): bool {
            $body = $req->data();

            return str_contains($req->url(), '/api/v1/user/payment/create')
                && $req->method() === 'POST'
                && data_get($body, 'payment_type') === 'bank_transfer'
                && data_get($body, 'channel_code') === 'BCA'
                && data_get($body, 'order_id') === 'ORD-ACTION-001'
                && data_get($body, 'amount') === 100000
                && data_get($body, 'callback_API_KEY') === 'webhook-secret'
                && ! array_key_exists('callback_api_key', $body)
                && is_array(data_get($body, 'items'))
                && count(data_get($body, 'items')) >= 1
                && data_get($body, 'items.0.name') !== null
                && data_get($body, 'items.0.quantity') >= 1
                && data_get($body, 'items.0.price') >= 0
                && data_get($body, 'expiry_duration') === 86400;
        });
    }

    public function test_create_komerce_payment_maps_collaborator_va_and_qris_field_names(): void
    {
        $this->komerceFakeConfig();

        $user = User::factory()->create(['first_name' => 'Uat', 'last_name' => 'User', 'email' => 'uat-map@example.test']);
        $paymentMethod = PaymentMethod::factory()->create(['driver' => 'komerce', 'is_enabled' => true]);

        $vaOrder = Order::factory()->create([
            'number' => 'ORD-COLLAB-VA',
            'price_amount' => 15000,
            'currency_code' => 'IDR',
            'customer_id' => $user->id,
            'payment_method_id' => $paymentMethod->id,
            'payment_status' => PaymentStatus::Pending,
            'status' => OrderStatus::New,
        ]);
        $this->makeOrderPayable($vaOrder);

        $this->fakeKomercePaymentHttp([
            'https://payment.example.test/user/api/v1/user/payment/create' => Http::sequence()
                ->push([
                    'meta' => ['message' => 'success create payment', 'code' => 200, 'status' => 'success'],
                    'data' => [
                        'payment_id' => 'KPAY-5292/KM/2026',
                        'payment_url' => 'https://pay-sandbox.komerce.my.id/example',
                        'va_number' => '381659999574893',
                        'qr_string' => '',
                        'bank_code' => 'BCA',
                        'amount' => 15000,
                        'status' => 'PENDING',
                        'expired_at' => '2026-08-05T05:04:54.252204638+07:00',
                    ],
                ])
                ->push([
                    'meta' => ['message' => 'success create payment', 'code' => 200, 'status' => 'success'],
                    'data' => [
                        'payment_id' => 'KPAY-b307/KM/2026',
                        'payment_url' => 'https://pay-sandbox.komerce.my.id/qris-example',
                        'va_number' => '',
                        'qr_string' => '00020101021226640013ID.CO.QRIS.WWW',
                        'bank_code' => '',
                        'amount' => 12000,
                        'status' => 'PENDING',
                        'expired_at' => '2026-08-05T04:09:55.141807+07:00',
                    ],
                ]),
        ]);

        $va = resolve(CreateKomercePayment::class)->handle($vaOrder, [
            'driver' => 'komerce',
            'channel_code' => 'BCA',
            'payment_type' => 'bank_transfer',
        ]);

        $this->assertSame('KPAY-5292/KM/2026', $va['payment_id']);
        $this->assertSame('381659999574893', $va['virtual_account_number']);
        $this->assertSame('BCA', $va['bank_code']);
        $this->assertSame('2026-08-05T05:04:54.252204638+07:00', $va['expiry_date']);
        $this->assertSame('https://pay-sandbox.komerce.my.id/example', $va['payment_url']);

        $qrisOrder = Order::factory()->create([
            'number' => 'ORD-COLLAB-QRIS',
            'price_amount' => 12000,
            'currency_code' => 'IDR',
            'customer_id' => $user->id,
            'payment_method_id' => $paymentMethod->id,
            'payment_status' => PaymentStatus::Pending,
            'status' => OrderStatus::New,
        ]);
        $this->makeOrderPayable($qrisOrder);

        $qris = resolve(CreateKomercePayment::class)->handle($qrisOrder, [
            'driver' => 'komerce',
            'payment_type' => 'qris',
        ]);

        $this->assertSame('KPAY-b307/KM/2026', $qris['payment_id']);
        $this->assertSame('00020101021226640013ID.CO.QRIS.WWW', $qris['qris_string']);
        $this->assertSame('2026-08-05T04:09:55.141807+07:00', $qris['expiry_date']);
        $this->assertSame('https://pay-sandbox.komerce.my.id/qris-example', $qris['payment_url']);
    }

    public function test_place_order_komerce_payment_failure_redirects_to_account_order_with_pending_order(): void
    {
        $this->komerceFakeConfig();

        $user = User::factory()->create(['first_name' => 'Test', 'last_name' => 'User', 'email' => 'fail@example.test']);

        [$country, , $paymentMethod] = $this->seedCountryZoneAndPaymentMethod();

        $order = Order::factory()->create([
            'number' => 'ORD-PAYFAIL-001',
            'price_amount' => 120000,
            'currency_code' => 'IDR',
            'customer_id' => $user->id,
            'payment_method_id' => $paymentMethod->id,
            'payment_status' => PaymentStatus::Pending,
            'status' => OrderStatus::New,
        ]);
        $this->makeOrderPayable($order);

        $this->app->instance(CreateOrder::class, new class($order)
        {
            public function __construct(private readonly Order $order) {}

            public function handle(): Order
            {
                return $this->order;
            }
        });

        // Komerce payment creation fails (e.g. gateway error)
        $this->fakeKomercePaymentHttp([
            'https://payment.example.test/user/api/v1/user/payment/create' => Http::response([
                'meta' => ['status' => 'error', 'code' => 500, 'message' => 'Gateway unavailable.'],
                'data' => ['payment_id' => ''],
            ], 200),
        ]);

        $session = ['checkout' => $this->makeCheckoutSession($country->id, $paymentMethod->id)];

        $response = $this->actingAs($user)
            ->withSession($session)
            ->post(route('shop.checkout.place-order'), [
                'payment_method_id' => $paymentMethod->id,
            ]);

        // Order exists — send customer to account order so they can retry payment.
        $response->assertRedirect(route('account.orders.show', ['order' => $order->id]));
        $response->assertSessionHas('error');
    }

    public function test_create_komerce_payment_rejects_failed_response_without_payment_id(): void
    {
        $this->komerceFakeConfig();

        $user = User::factory()->create(['first_name' => 'Test', 'last_name' => 'User', 'email' => 'test@example.test']);

        $paymentMethod = PaymentMethod::factory()->create(['driver' => 'komerce', 'is_enabled' => true]);

        $order = Order::factory()->create([
            'number' => 'ORD-ACTION-FAILED',
            'price_amount' => 100000,
            'currency_code' => 'IDR',
            'customer_id' => $user->id,
            'payment_method_id' => $paymentMethod->id,
            'payment_status' => PaymentStatus::Pending,
            'status' => OrderStatus::New,
        ]);
        $this->makeOrderPayable($order);

        $this->fakeKomercePaymentHttp([
            'https://payment.example.test/user/api/v1/user/payment/create' => Http::response([
                'meta' => [
                    'status' => 'error',
                    'code' => 400,
                    'message' => 'Payment channel is unavailable.',
                ],
                'data' => [
                    'payment_id' => '',
                ],
            ], 200),
        ]);

        try {
            resolve(CreateKomercePayment::class)->handle($order, [
                'id' => $paymentMethod->id,
                'driver' => 'komerce',
                'channel_code' => 'BCA',
                'payment_type' => 'bank_transfer',
            ]);

            $this->fail('Expected failed Komerce response to throw.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Komerce payment creation failed', $e->getMessage());
        }

        $this->assertDatabaseMissing(
            (new PaymentTransaction)->getTable(),
            [
                'order_id' => $order->id,
                'driver' => 'komerce',
                'reference' => '',
            ],
        );
        $this->assertSame(0, PaymentTransaction::query()->where('order_id', $order->id)->count());
    }

    public function test_late_paid_callback_cannot_revive_a_cancelled_order(): void
    {
        $this->komerceFakeConfig();
        Bus::fake();

        $order = Order::factory()->create([
            'number' => 'ORD-CANCELLED-LATE-PAID',
            'price_amount' => 100000,
            'currency_code' => 'IDR',
            'payment_status' => PaymentStatus::Voided,
            'status' => OrderStatus::Cancelled,
        ]);

        PaymentTransaction::query()->create([
            'order_id' => $order->id,
            'driver' => 'komerce',
            'type' => TransactionType::Initiate,
            'status' => TransactionStatus::Failed,
            'amount' => 100000,
            'currency_code' => 'IDR',
            'reference' => 'KOMPAY-LATE-PAID',
        ]);

        Http::fake([
            'https://payment.example.test/user/api/v1/user/payment/status/KOMPAY-LATE-PAID' => Http::response([
                'success' => true,
                'data' => ['payment_id' => 'KOMPAY-LATE-PAID', 'status' => 'EXPIRED', 'amount' => 100000],
            ]),
        ]);

        $status = resolve(MarkOrderPaidFromKomerce::class)->handle('KOMPAY-LATE-PAID');

        $this->assertSame('cancelled_order', $status);
        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);
        $this->assertSame(PaymentStatus::Voided, $order->payment_status);
        Bus::assertNotDispatched(CreateRajaOngkirDeliveryForShipment::class);
        Bus::assertNotDispatched(FulfillOrderShipment::class);
    }

    public function test_money_captured_after_cancellation_raises_an_alert_without_reviving_the_order(): void
    {
        $this->komerceFakeConfig();
        Bus::fake();
        Exceptions::fake();

        $order = Order::factory()->create([
            'number' => 'ORD-PAID-AFTER-CANCEL',
            'price_amount' => 100000,
            'currency_code' => 'IDR',
            'payment_status' => PaymentStatus::Voided,
            'status' => OrderStatus::Cancelled,
        ]);

        PaymentTransaction::query()->create([
            'order_id' => $order->id,
            'driver' => 'komerce',
            'type' => TransactionType::Initiate,
            'status' => TransactionStatus::Failed,
            'amount' => 100000,
            'currency_code' => 'IDR',
            'reference' => 'KOMPAY-PAID-AFTER-CANCEL',
        ]);

        Http::fake([
            'https://payment.example.test/user/api/v1/user/payment/status/KOMPAY-PAID-AFTER-CANCEL' => Http::response([
                'success' => true,
                'data' => ['payment_id' => 'KOMPAY-PAID-AFTER-CANCEL', 'status' => 'PAID', 'amount' => 100000],
            ]),
        ]);

        $status = resolve(MarkOrderPaidFromKomerce::class)->handle('KOMPAY-PAID-AFTER-CANCEL');

        $this->assertSame('paid_after_cancel', $status);
        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);
        $this->assertSame(PaymentStatus::Voided, $order->payment_status);
        Bus::assertNotDispatched(CreateRajaOngkirDeliveryForShipment::class);
        Bus::assertNotDispatched(FulfillOrderShipment::class);
        Exceptions::assertReported(
            fn (KomercePaidAfterCancellation $e): bool => $e->orderNumber === 'ORD-PAID-AFTER-CANCEL'
                && $e->paymentId === 'KOMPAY-PAID-AFTER-CANCEL',
        );
    }

    public function test_401_and_403_payment_catalog_revocation_never_leaks_first_komerce_method(): void
    {
        $this->withoutVite();

        $country = Country::factory()->create();
        $zone = Zone::factory()->create(['is_enabled' => true]);
        $zone->countries()->attach($country->id);

        // Seed Komerce VA FIRST (regression target for the first-method leak),
        // followed by a second Komerce VA, Komerce QRIS, and manual COD.
        $vaBca = PaymentMethod::factory()->create([
            'slug' => 'komerce-va-bca',
            'title' => 'BCA Virtual Account',
            'driver' => 'komerce',
            'is_enabled' => true,
            'metadata' => json_encode(['channel_code' => 'BCA', 'payment_type' => 'bank_transfer']),
        ]);
        $vaBri = PaymentMethod::factory()->create([
            'slug' => 'komerce-va-bri',
            'title' => 'BRI Virtual Account',
            'driver' => 'komerce',
            'is_enabled' => true,
            'metadata' => json_encode(['channel_code' => 'BRI', 'payment_type' => 'bank_transfer']),
        ]);
        $qris = PaymentMethod::factory()->create([
            'slug' => 'komerce-qris',
            'title' => 'QRIS',
            'driver' => 'komerce',
            'is_enabled' => true,
            'metadata' => json_encode(['payment_type' => 'qris']),
        ]);
        $manual = PaymentMethod::factory()->create([
            'slug' => 'cod',
            'title' => 'Cash on delivery',
            'driver' => 'manual',
            'is_enabled' => true,
        ]);
        $zone->paymentMethods()->attach([$vaBca->id, $vaBri->id, $qris->id, $manual->id]);

        $user = User::factory()->create();
        $product = Product::factory()->standard()->create(['published_at' => now()->subDay()]);
        $cart = Cart::query()->create(['currency_code' => 'IDR', 'customer_id' => $user->id]);
        CartLine::query()->create([
            'cart_id' => $cart->id,
            'purchasable_type' => $product->getMorphClass(),
            'purchasable_id' => $product->id,
            'quantity' => 1,
            'unit_price_amount' => 50000,
        ]);

        foreach ([
            401 => ['meta' => ['message' => 'Invalid API Key']],
            403 => ['meta' => ['message' => 'Forbidden']],
            400 => ['meta' => ['message' => 'Invalid API key']],
        ] as $status => $payload) {
            // Case A: Payment API key set, QRISLY off -> ZERO Komerce methods leak anywhere.
            Cache::flush();
            resolve(KomerceCapabilities::class)->forget();
            $this->komerceFakeConfig();
            config()->set('komerce.qrisly_api_key', '');
            config()->set('komerce.qrisly_qris_id', '');

            Http::swap(new Factory);
            Http::fake([
                'https://payment.example.test/user/api/v1/user/methods' => Http::response($payload, $status),
            ]);

            $methods = resolve(FetchPaymentMethods::class)->handle($country->id);
            $this->assertSame(['cod'], array_column($methods, 'slug'), "HTTP {$status} must not leak first Komerce method");

            // Footer Inertia props (fresh cache, first request hits /user/methods and gets 401/403).
            Cache::flush();
            resolve(KomerceCapabilities::class)->forget();
            $this->get(route('home'))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->has('shop.payment_methods', 1)
                    ->where('shop.payment_methods.0.key', 'cod')
                );

            // Web checkout (fresh cache, first request hits /user/methods and gets 401/403).
            Cache::flush();
            resolve(KomerceCapabilities::class)->forget();
            $this->actingAs($user)
                ->withSession([
                    config('shopper.cart.session.key', 'shopper_cart') => $cart->id,
                    'checkout' => $this->makeCheckoutSession($country->id, $manual->id),
                ])
                ->get(route('shop.checkout.index', ['step' => 3]), ['X-Inertia' => 'true'])
                ->assertOk()
                ->assertJsonPath('props.paymentOptions.0.slug', 'cod')
                ->assertJsonCount(1, 'props.paymentOptions');

            // API v1 checkout (fresh cache, first request hits /user/methods and gets 401/403).
            Cache::flush();
            resolve(KomerceCapabilities::class)->forget();
            Sanctum::actingAs($user);
            $this->getJson('/api/v1/checkout')
                ->assertOk()
                ->assertJsonCount(1, 'data.payment_methods')
                ->assertJsonPath('data.payment_methods.0.slug', 'cod');

            // Case B: Payment API key set, QRISLY enabled -> VA #1 & #2 excluded, QRIS kept with 1k..100M bounds.
            Cache::flush();
            resolve(KomerceCapabilities::class)->forget();
            config()->set('komerce.qrisly_api_key', 'qrisly-key');
            config()->set('komerce.qrisly_qris_id', '18');
            config()->set('komerce.qrisly_base_url', 'https://qrisly.example.test/user');

            $methodsWithQrisly = resolve(FetchPaymentMethods::class)->handle($country->id);
            $slugsWithQrisly = array_column($methodsWithQrisly, 'slug');
            $this->assertNotContains('komerce-va-bca', $slugsWithQrisly);
            $this->assertNotContains('komerce-va-bri', $slugsWithQrisly);
            $this->assertSame(['komerce-qris', 'cod'], $slugsWithQrisly);
            $this->assertSame(1000, $methodsWithQrisly[0]['min_amount']);
            $this->assertSame(100_000_000, $methodsWithQrisly[0]['max_amount']);

            Cache::flush();
            resolve(KomerceCapabilities::class)->forget();
            $this->get(route('home'))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->has('shop.payment_methods', 2)
                    ->where('shop.payment_methods.0.key', 'komerce-qris')
                    ->where('shop.payment_methods.1.key', 'cod')
                );
        }
    }
}
