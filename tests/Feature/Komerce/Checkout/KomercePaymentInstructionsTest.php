<?php

declare(strict_types=1);

namespace Tests\Feature\Komerce\Checkout;

use App\Integrations\Komerce\Actions\Payment\CancelUnpaidKomerceOrder;
use App\Integrations\Komerce\Actions\Payment\CreateKomercePayment;
use App\Integrations\Komerce\Actions\Payment\ResolveKomercePaymentInstructions;
use App\Integrations\Komerce\Actions\Payment\RetryKomercePayment;
use App\Integrations\Komerce\Actions\Payment\SyncKomercePaymentStatus;
use App\Integrations\Komerce\Komerce;
use App\Integrations\Komerce\Support\KomerceCapabilities;
use App\Models\PaymentDispute;
use App\Models\User;
use App\Payment\Exceptions\PaymentRetryRefused;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Shopper\Cart\Models\Cart;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\OrderItem;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Payment\Enum\TransactionStatus;
use Shopper\Payment\Enum\TransactionType;
use Shopper\Payment\Models\PaymentTransaction;
use Tests\Feature\Komerce\Concerns\SignsKomercePaymentCallbacks;
use Tests\TestCase;

final class KomercePaymentInstructionsTest extends TestCase
{
    use RefreshDatabase;
    use SignsKomercePaymentCallbacks;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('komerce.payment_api_key', 'test-komerce-key');
        config()->set('komerce.payment_base_url', 'https://payment.example.test/user');
        config()->set('komerce.webhook_secret', 'webhook-secret');
        config()->set('shopper.payment.drivers.stripe.enabled', false);
    }

    public function test_create_payment_persists_instructions_on_order_metadata(): void
    {
        Http::fake([
            'https://payment.example.test/user/api/v1/user/payment/create' => Http::response([
                'success' => true,
                'data' => [
                    'payment_id' => 'KOMPAY-PERSIST-1',
                    'virtual_account_number' => '1234567890',
                    'bank_code' => 'BCA',
                    'amount' => 150000,
                    'expiry_date' => '2026-08-05T12:00:00+07:00',
                ],
            ], 200),
        ]);

        $user = User::factory()->create();
        $method = PaymentMethod::factory()->create([
            'driver' => 'komerce',
            'is_enabled' => true,
            'metadata' => json_encode(['channel_code' => 'BCA', 'payment_type' => 'bank_transfer']),
        ]);

        $order = Order::factory()->create([
            'customer_id' => $user->id,
            'payment_method_id' => $method->id,
            'payment_status' => PaymentStatus::Pending,
            'status' => OrderStatus::New,
            'price_amount' => 150000,
            'currency_code' => 'IDR',
            'number' => 'ORD-PERSIST-1',
            'metadata' => json_encode([]),
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'name' => 'Produk OceanMall',
            'quantity' => 1,
            'unit_price_amount' => 150000,
        ]);

        $instructions = resolve(CreateKomercePayment::class)->handle($order, [
            'channel_code' => 'BCA',
            'payment_type' => 'bank_transfer',
        ]);

        $order->refresh();
        $meta = json_decode((string) $order->getAttribute('metadata'), true);
        $this->assertIsArray($meta);
        $this->assertSame('1234567890', data_get($meta, 'komerce.payment_instructions.virtual_account_number'));
        $this->assertSame(150000, data_get($meta, 'komerce.payment_instructions.amount'));
        $this->assertSame($instructions['payment_id'], data_get($meta, 'komerce.payment_instructions.payment_id'));
    }

    public function test_resolve_instructions_from_order_after_session_cleared(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'customer_id' => $user->id,
            'payment_status' => PaymentStatus::Pending,
            'status' => OrderStatus::New,
            'price_amount' => 99000,
            'currency_code' => 'IDR',
            'metadata' => json_encode([
                'komerce' => [
                    'payment_ref' => 'KOMPAY-RESOLVE-1',
                    'provider' => 'payment_api',
                    'payment_type' => 'bank_transfer',
                    'payment_instructions' => [
                        'payment_id' => 'KOMPAY-RESOLVE-1',
                        'payment_type' => 'bank_transfer',
                        'provider' => 'payment_api',
                        'virtual_account_number' => '9988776655',
                        'bank_code' => 'BCA',
                        'qris_string' => null,
                        'expiry_date' => '2026-08-05T12:00:00+07:00',
                        'amount' => 99000,
                        'currency_code' => 'IDR',
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        $resolved = resolve(ResolveKomercePaymentInstructions::class)->handle($order);

        $this->assertNotNull($resolved);
        $this->assertSame('9988776655', $resolved['virtual_account_number']);
        $this->assertSame(99000, $resolved['amount']);
    }

    public function test_checkout_success_reads_instructions_from_order_when_session_empty(): void
    {
        $this->withoutVite();

        $user = User::factory()->create();
        $order = Order::factory()->create([
            'customer_id' => $user->id,
            // The action comes from the order's own payment driver.
            'payment_method_id' => PaymentMethod::factory()->create(['driver' => 'komerce', 'is_enabled' => true])->id,
            'payment_status' => PaymentStatus::Pending,
            'status' => OrderStatus::New,
            'price_amount' => 50000,
            'currency_code' => 'IDR',
            'metadata' => json_encode([
                'komerce' => [
                    'payment_instructions' => [
                        'payment_id' => 'KOMPAY-SUCCESS-1',
                        'payment_type' => 'bank_transfer',
                        'provider' => 'payment_api',
                        'virtual_account_number' => '1122334455',
                        'bank_code' => 'BCA',
                        'qris_string' => null,
                        'expiry_date' => null,
                        'amount' => 50000,
                        'currency_code' => 'IDR',
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        $this->actingAs($user)
            ->get(route('shop.checkout.success', ['order' => $order->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('shop/checkout-success')
                ->where('paymentAction.driver', 'komerce')
                ->where('paymentAction.kind', 'instructions')
                ->where('paymentAction.data.virtual_account_number', '1122334455')
                ->where('paymentAction.data.amount', 50000)
                ->missing('komercePayment')
            );
    }

    public function test_account_order_show_includes_pending_komerce_payment(): void
    {
        $this->withoutVite();

        $user = User::factory()->create();
        $order = Order::factory()->create([
            'customer_id' => $user->id,
            'payment_method_id' => PaymentMethod::factory()->create(['driver' => 'komerce', 'is_enabled' => true])->id,
            'payment_status' => PaymentStatus::Pending,
            'status' => OrderStatus::New,
            'price_amount' => 75000,
            'currency_code' => 'IDR',
            'metadata' => json_encode([
                'komerce' => [
                    'payment_instructions' => [
                        'payment_id' => 'KOMPAY-ACCOUNT-1',
                        'payment_type' => 'qris',
                        'provider' => 'payment_api',
                        'virtual_account_number' => null,
                        'bank_code' => null,
                        'qris_string' => '00020101021226650016COM.EXAMPLE.WWW01189360000000000000002015ID20260604000000303UME52045',
                        'expiry_date' => '2026-08-05T12:00:00+07:00',
                        'amount' => 75000,
                        'currency_code' => 'IDR',
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        $this->actingAs($user)
            ->get(route('account.orders.show', $order))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('account/order-show')
                ->where('paymentAction.kind', 'qr')
                ->where('paymentAction.data.payment_type', 'qris')
                ->where('paymentAction.data.amount', 75000)
                ->where('canRetryPayment', true)
            );
    }

    public function test_customer_can_retry_komerce_payment_for_pending_order(): void
    {
        Http::fake([
            'https://payment.example.test/user/api/v1/user/payment/status/KOMPAY-RETRY-OLD' => Http::response([
                'data' => [
                    'payment_id' => 'KOMPAY-RETRY-OLD',
                    'status' => 'PENDING',
                ],
            ], 200),
            'https://payment.example.test/user/api/v1/user/payment/cancel' => Http::response([
                'data' => [
                    'payment_id' => 'KOMPAY-RETRY-OLD',
                    'status' => 'CANCELED',
                ],
            ], 200),
            'https://payment.example.test/user/api/v1/user/payment/create' => Http::response([
                'success' => true,
                'data' => [
                    'payment_id' => 'KOMPAY-RETRY-2',
                    'virtual_account_number' => '5555666677',
                    'bank_code' => 'BCA',
                    'amount' => 88000,
                    'expiry_date' => '2026-08-05T18:00:00+07:00',
                ],
            ], 200),
        ]);

        $user = User::factory()->create();
        $method = PaymentMethod::factory()->create([
            'driver' => 'komerce',
            'is_enabled' => true,
            'metadata' => json_encode(['channel_code' => 'BCA', 'payment_type' => 'bank_transfer']),
        ]);

        $order = Order::factory()->create([
            'customer_id' => $user->id,
            'payment_method_id' => $method->id,
            'payment_status' => PaymentStatus::Pending,
            'status' => OrderStatus::New,
            'price_amount' => 88000,
            'currency_code' => 'IDR',
            'number' => 'ORD-RETRY-1',
            'metadata' => json_encode([
                'komerce' => [
                    'payment_instructions' => null,
                ],
            ], JSON_THROW_ON_ERROR),
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'name' => 'Produk OceanMall',
            'quantity' => 1,
            'unit_price_amount' => 88000,
        ]);

        PaymentTransaction::query()->create([
            'order_id' => $order->id,
            'payment_method_id' => $method->id,
            'driver' => 'komerce',
            'reference' => 'KOMPAY-RETRY-OLD',
            'type' => TransactionType::Initiate,
            'amount' => 88000,
            'currency_code' => 'IDR',
            'status' => TransactionStatus::Pending,
            'metadata' => ['komerce_payment_ref' => 'KOMPAY-RETRY-OLD'],
        ]);

        $this->actingAs($user)
            ->post(route('account.orders.retry-payment', $order))
            ->assertRedirect(route('account.orders.show', $order));

        $order->refresh();
        $meta = json_decode((string) $order->getAttribute('metadata'), true);
        $this->assertSame('5555666677', data_get($meta, 'komerce.payment_instructions.virtual_account_number'));
        $this->assertSame('KOMPAY-RETRY-2', data_get($meta, 'komerce.payment_instructions.payment_id'));
        $this->assertDatabaseHas((new PaymentTransaction)->getTable(), [
            'reference' => 'KOMPAY-RETRY-OLD',
            'status' => TransactionStatus::Failed->value,
        ]);
        Http::assertSentCount(3);
    }

    public function test_customer_can_sync_pending_komerce_payment_to_paid(): void
    {
        Http::fake([
            'https://payment.example.test/user/api/v1/user/payment/status/KOMPAY-SYNC-1' => Http::response([
                'success' => true,
                'data' => [
                    'payment_id' => 'KOMPAY-SYNC-1',
                    'status' => 'PAID',
                    'amount' => 66000,
                ],
            ], 200),
        ]);

        $user = User::factory()->create();
        $method = PaymentMethod::factory()->create([
            'driver' => 'komerce',
            'is_enabled' => true,
            'metadata' => json_encode(['channel_code' => 'BCA', 'payment_type' => 'bank_transfer']),
        ]);

        $order = Order::factory()->create([
            'customer_id' => $user->id,
            'payment_method_id' => $method->id,
            'payment_status' => PaymentStatus::Pending,
            'status' => OrderStatus::New,
            'price_amount' => 66000,
            'currency_code' => 'IDR',
            'metadata' => json_encode([
                'komerce' => [
                    'payment_ref' => 'KOMPAY-SYNC-1',
                    'provider' => 'payment_api',
                    'payment_instructions' => [
                        'payment_id' => 'KOMPAY-SYNC-1',
                        'payment_type' => 'bank_transfer',
                        'provider' => 'payment_api',
                        'virtual_account_number' => '1212121212',
                        'bank_code' => 'BCA',
                        'qris_string' => null,
                        'expiry_date' => null,
                        'amount' => 66000,
                        'currency_code' => 'IDR',
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        PaymentTransaction::query()->create([
            'order_id' => $order->id,
            'payment_method_id' => $method->id,
            'driver' => 'komerce',
            'reference' => 'KOMPAY-SYNC-1',
            'type' => TransactionType::Initiate,
            'amount' => 66000,
            'currency_code' => 'IDR',
            'status' => TransactionStatus::Pending,
            'metadata' => ['komerce_payment_ref' => 'KOMPAY-SYNC-1'],
        ]);

        $this->actingAs($user)
            ->from(route('shop.checkout.success', ['order' => $order->id]))
            ->post(route('account.orders.sync-payment', $order))
            ->assertRedirect(route('shop.checkout.success', ['order' => $order->id]))
            ->assertSessionHas('success');

        $order->refresh();
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
    }

    public function test_silent_sync_does_not_flash_when_still_unpaid(): void
    {
        Http::fake([
            'https://payment.example.test/user/api/v1/user/payment/status/KOMPAY-SYNC-2' => Http::response([
                'success' => true,
                'data' => [
                    'payment_id' => 'KOMPAY-SYNC-2',
                    'status' => 'PENDING',
                    'amount' => 44000,
                ],
            ], 200),
        ]);

        $user = User::factory()->create();
        $order = Order::factory()->create([
            'customer_id' => $user->id,
            'payment_status' => PaymentStatus::Pending,
            'status' => OrderStatus::New,
            'price_amount' => 44000,
            'currency_code' => 'IDR',
            'metadata' => json_encode([
                'komerce' => [
                    'payment_ref' => 'KOMPAY-SYNC-2',
                    'provider' => 'payment_api',
                    'payment_instructions' => [
                        'payment_id' => 'KOMPAY-SYNC-2',
                        'payment_type' => 'bank_transfer',
                        'provider' => 'payment_api',
                        'virtual_account_number' => '3434343434',
                        'bank_code' => 'BCA',
                        'amount' => 44000,
                        'currency_code' => 'IDR',
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        PaymentTransaction::query()->create([
            'order_id' => $order->id,
            'driver' => 'komerce',
            'reference' => 'KOMPAY-SYNC-2',
            'type' => TransactionType::Initiate,
            'amount' => 44000,
            'currency_code' => 'IDR',
            'status' => TransactionStatus::Pending,
        ]);

        $this->actingAs($user)
            ->from(route('account.orders.show', $order))
            ->post(route('account.orders.sync-payment', $order), ['silent' => 1])
            ->assertRedirect(route('account.orders.show', $order))
            ->assertSessionMissing('info')
            ->assertSessionMissing('success');

        $order->refresh();
        $this->assertSame(PaymentStatus::Pending, $order->payment_status);
    }

    public function test_checkout_success_revisit_does_not_wipe_active_cart(): void
    {
        $this->withoutVite();

        $user = User::factory()->create();
        $order = Order::factory()->create([
            'customer_id' => $user->id,
            'payment_status' => PaymentStatus::Pending,
            'status' => OrderStatus::New,
            'price_amount' => 25000,
            'currency_code' => 'IDR',
            'metadata' => json_encode([
                'komerce' => [
                    'payment_instructions' => [
                        'payment_id' => 'KOMPAY-CART-1',
                        'payment_type' => 'bank_transfer',
                        'provider' => 'payment_api',
                        'virtual_account_number' => '7778889990',
                        'bank_code' => 'BCA',
                        'amount' => 25000,
                        'currency_code' => 'IDR',
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        $cart = Cart::query()->create([
            'currency_code' => 'IDR',
            'customer_id' => $user->id,
        ]);

        $this->actingAs($user);
        session()->put(config('shopper.cart.session.key', 'shopper_cart'), $cart->id);

        $this->get(route('shop.checkout.success', ['order' => $order->id]))
            ->assertOk();

        $this->assertSame(
            $cart->id,
            session()->get(config('shopper.cart.session.key', 'shopper_cart')),
        );
    }

    public function test_qrisly_order_can_retry_via_payment_api_and_supports_sync_webhook_and_expiry(): void
    {
        config()->set('komerce.payment_api_key', 'test-komerce-key');
        config()->set('komerce.qrisly_api_key', '');
        config()->set('komerce.qrisly_qris_id', '');

        $user = User::factory()->create(['first_name' => 'Rina', 'last_name' => 'Sari', 'email' => 'rina@example.com', 'phone_number' => '081234567890']);
        $qrisMethod = PaymentMethod::factory()->create([
            'slug' => 'komerce-qris',
            'driver' => 'komerce',
            'is_enabled' => true,
            'metadata' => json_encode(['payment_type' => 'qris']),
        ]);

        // Under Rp10.000 cannot fall back to Payment API.
        $smallOrder = Order::factory()->create([
            'customer_id' => $user->id,
            'payment_method_id' => $qrisMethod->id,
            'payment_status' => PaymentStatus::Pending,
            'status' => OrderStatus::New,
            'price_amount' => 5000,
            'currency_code' => 'IDR',
            'metadata' => json_encode([
                'komerce' => [
                    'provider' => 'qrisly',
                    'payment_type' => 'qris',
                    'payment_ref' => '9000',
                    'qrisly_history_id' => '9000',
                ],
            ]),
        ]);
        $this->assertFalse(resolve(ResolveKomercePaymentInstructions::class)->canRetry($smallOrder));

        // >= Rp10.000 can cross-provider retry via Payment API.
        $order = Order::factory()->create([
            'customer_id' => $user->id,
            'payment_method_id' => $qrisMethod->id,
            'payment_status' => PaymentStatus::Pending,
            'status' => OrderStatus::New,
            'price_amount' => 50000,
            'currency_code' => 'IDR',
            'number' => 'ORD-QRIS-CROSS-1',
            'metadata' => json_encode([
                'komerce' => [
                    'provider' => 'qrisly',
                    'payment_type' => 'qris',
                    'payment_ref' => '9001',
                    'qrisly_history_id' => '9001',
                    'qrisly_qris_id' => '18',
                    'payment_instructions' => [
                        'payment_id' => '9001',
                        'payment_type' => 'qris',
                        'provider' => 'qrisly',
                        'qris_string' => 'OLD-QRISLY',
                        'amount' => 50001,
                        'currency_code' => 'IDR',
                    ],
                ],
            ]),
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'name' => 'Produk QRIS',
            'quantity' => 1,
            'unit_price_amount' => 50000,
        ]);
        PaymentTransaction::query()->create([
            'order_id' => $order->id,
            'payment_method_id' => $qrisMethod->id,
            'driver' => 'komerce',
            'reference' => '9001',
            'type' => TransactionType::Initiate,
            'amount' => 50000,
            'currency_code' => 'IDR',
            'status' => TransactionStatus::Pending,
            'metadata' => ['komerce_provider' => 'qrisly'],
        ]);

        $this->assertTrue(resolve(ResolveKomercePaymentInstructions::class)->canRetry($order));

        Http::fake([
            'https://payment.example.test/user/api/v1/user/payment/create' => Http::response([
                'success' => true,
                'data' => [
                    'payment_id' => 'KOMPAY-QRIS-NEW',
                    'qris_string' => 'NEW-PAYMENT-API-QRIS',
                    'expiry_date' => '2026-08-05T18:00:00+07:00',
                    'amount' => 50000,
                ],
            ], 200),
            'https://payment.example.test/user/api/v1/user/payment/status/KOMPAY-QRIS-NEW' => Http::response([
                'success' => true,
                'data' => [
                    'payment_id' => 'KOMPAY-QRIS-NEW',
                    'status' => 'PAID',
                    'amount' => 50000,
                ],
            ], 200),
        ]);

        $this->actingAs($user)
            ->post(route('account.orders.retry-payment', $order))
            ->assertRedirect(route('account.orders.show', $order))
            ->assertSessionHas('success');

        $order->refresh();
        $meta = json_decode((string) $order->getAttribute('metadata'), true);
        $this->assertSame('payment_api', data_get($meta, 'komerce.provider'));
        $this->assertSame('KOMPAY-QRIS-NEW', data_get($meta, 'komerce.payment_ref'));
        $this->assertSame('payment_api', data_get($meta, 'komerce.payment_instructions.provider'));
        $this->assertNull(data_get($meta, 'komerce.qrisly_history_id'));
        $this->assertNull(data_get($meta, 'komerce.qrisly_qris_id'));

        $this->assertDatabaseHas((new PaymentTransaction)->getTable(), [
            'order_id' => $order->id,
            'reference' => '9001',
            'status' => TransactionStatus::Failed->value,
        ]);
        $this->assertDatabaseHas((new PaymentTransaction)->getTable(), [
            'order_id' => $order->id,
            'reference' => 'KOMPAY-QRIS-NEW',
            'status' => TransactionStatus::Pending->value,
        ]);

        // Status sync uses the new Payment API provider.
        $this->assertSame('handled', resolve(SyncKomercePaymentStatus::class)->handle($order));
        $this->assertSame(PaymentStatus::Paid, $order->refresh()->payment_status);

        // Webhook idempotency + expiry on another switched order also target payment_api.
        $expireOrder = Order::factory()->create([
            'customer_id' => $user->id,
            'payment_method_id' => $qrisMethod->id,
            'payment_status' => PaymentStatus::Pending,
            'status' => OrderStatus::New,
            'price_amount' => 50000,
            'currency_code' => 'IDR',
            'number' => 'ORD-QRIS-EXPIRE-1',
            'metadata' => json_encode([
                'komerce' => [
                    'provider' => 'payment_api',
                    'payment_type' => 'qris',
                    'payment_ref' => 'KOMPAY-QRIS-EXP',
                    'payment_instructions' => [
                        'payment_id' => 'KOMPAY-QRIS-EXP',
                        'payment_type' => 'qris',
                        'provider' => 'payment_api',
                    ],
                ],
            ]),
        ]);
        Http::swap(new Factory);
        Http::fake([
            'https://payment.example.test/user/api/v1/user/payment/status/KOMPAY-QRIS-EXP' => Http::response([
                'success' => true,
                'data' => ['payment_id' => 'KOMPAY-QRIS-EXP', 'status' => 'UNPAID', 'amount' => 50000],
            ], 200),
            'https://payment.example.test/user/api/v1/user/payment/cancel' => Http::response([
                'success' => true,
                'data' => ['payment_id' => 'KOMPAY-QRIS-EXP', 'status' => 'CANCELED'],
            ], 200),
        ]);
        $cancelled = resolve(CancelUnpaidKomerceOrder::class)->handle($expireOrder);
        $this->assertSame(OrderStatus::Cancelled, $cancelled->status);
    }

    public function test_payment_api_qris_can_retry_via_qrisly_and_blocks_va_when_only_qrisly_enabled(): void
    {
        config()->set('komerce.payment_api_key', '');
        config()->set('komerce.qrisly_api_key', 'qrisly-key');
        config()->set('komerce.qrisly_qris_id', '18');
        config()->set('komerce.qrisly_base_url', 'https://qrisly.example.test/user');

        $user = User::factory()->create();
        $qrisMethod = PaymentMethod::factory()->create([
            'slug' => 'komerce-qris',
            'driver' => 'komerce',
            'is_enabled' => true,
            'metadata' => json_encode(['payment_type' => 'qris']),
        ]);
        $vaMethod = PaymentMethod::factory()->create([
            'slug' => 'komerce-va-bca',
            'driver' => 'komerce',
            'is_enabled' => true,
            'metadata' => json_encode(['payment_type' => 'bank_transfer', 'channel_code' => 'BCA']),
        ]);

        // VA order cannot retry when only QRISLY is enabled.
        $vaOrder = Order::factory()->create([
            'customer_id' => $user->id,
            'payment_method_id' => $vaMethod->id,
            'payment_status' => PaymentStatus::Pending,
            'status' => OrderStatus::New,
            'price_amount' => 75000,
            'currency_code' => 'IDR',
            'metadata' => json_encode(['komerce' => ['provider' => 'payment_api', 'payment_type' => 'bank_transfer']]),
        ]);
        $this->assertFalse(resolve(ResolveKomercePaymentInstructions::class)->canRetry($vaOrder));

        // Payment API QRIS order CAN retry via QRISLY, skipping Payment API retrieve/cancel.
        $qrisOrder = Order::factory()->create([
            'customer_id' => $user->id,
            'payment_method_id' => $qrisMethod->id,
            'payment_status' => PaymentStatus::Pending,
            'status' => OrderStatus::New,
            'price_amount' => 75000,
            'currency_code' => 'IDR',
            'number' => 'ORD-PAYAPI-TO-QRISLY',
            'metadata' => json_encode([
                'komerce' => [
                    'provider' => 'payment_api',
                    'payment_type' => 'qris',
                    'payment_ref' => 'KOMPAY-OLD-QRIS',
                    'payment_instructions' => [
                        'payment_id' => 'KOMPAY-OLD-QRIS',
                        'payment_type' => 'qris',
                        'provider' => 'payment_api',
                        'qris_string' => 'OLD-PAYAPI-QRIS',
                        'amount' => 75000,
                        'currency_code' => 'IDR',
                    ],
                ],
            ]),
        ]);
        PaymentTransaction::query()->create([
            'order_id' => $qrisOrder->id,
            'payment_method_id' => $qrisMethod->id,
            'driver' => 'komerce',
            'reference' => 'KOMPAY-OLD-QRIS',
            'type' => TransactionType::Initiate,
            'amount' => 75000,
            'currency_code' => 'IDR',
            'status' => TransactionStatus::Pending,
            'metadata' => ['komerce_provider' => 'payment_api'],
        ]);

        $this->assertTrue(resolve(ResolveKomercePaymentInstructions::class)->canRetry($qrisOrder));

        Http::fake([
            'https://qrisly.example.test/user/api/v1/qrisly/generate-qris' => Http::response([
                'success' => true,
                'data' => [
                    'history_id' => 9002,
                    'qris_string' => 'NEW-QRISLY-STRING',
                    'final_amount' => 75001,
                    'expiry_time' => '2026-08-05T19:00:00+07:00',
                ],
            ], 200),
            'https://qrisly.example.test/user/api/v1/qrisly/payment-status/9002' => Http::response([
                'data' => [
                    'history_id' => 9002,
                    'payment_status' => 'paid',
                    'final_amount' => 75001,
                ],
            ], 200),
        ]);

        $this->actingAs($user)
            ->post(route('account.orders.retry-payment', $qrisOrder))
            ->assertRedirect(route('account.orders.show', $qrisOrder))
            ->assertSessionHas('success');

        Http::assertNotSent(fn (Request $req): bool => str_contains($req->url(), 'payment.example.test'));
        $qrisOrder->refresh();
        $meta = json_decode((string) $qrisOrder->getAttribute('metadata'), true);
        $this->assertSame('qrisly', data_get($meta, 'komerce.provider'));
        $this->assertSame('9002', data_get($meta, 'komerce.payment_ref'));
        $this->assertSame('9002', data_get($meta, 'komerce.qrisly_history_id'));
        $this->assertDatabaseHas((new PaymentTransaction)->getTable(), [
            'order_id' => $qrisOrder->id,
            'reference' => 'KOMPAY-OLD-QRIS',
            'status' => TransactionStatus::Failed->value,
        ]);

        // Subsequent QRISLY webhook marks the order paid.
        $this->postJson(route('webhooks.komerce.qrisly'), [
            'event' => 'payment.success',
            'data' => ['history_id' => '9002', 'status' => 'paid'],
        ])->assertOk();
        $this->assertSame(PaymentStatus::Paid, $qrisOrder->refresh()->payment_status);

        // Failed initial QRIS initiation (no metadata.komerce) in QRISLY-only mode can retry cleanly.
        $failedInitOrder = Order::factory()->create([
            'customer_id' => $user->id,
            'payment_method_id' => $qrisMethod->id,
            'payment_status' => PaymentStatus::Pending,
            'status' => OrderStatus::New,
            'price_amount' => 5000,
            'currency_code' => 'IDR',
            'metadata' => json_encode([]),
        ]);
        $this->assertTrue(resolve(ResolveKomercePaymentInstructions::class)->canRetry($failedInitOrder));

        Http::swap(new Factory);
        Http::fake([
            'https://qrisly.example.test/user/api/v1/qrisly/generate-qris' => Http::response([
                'success' => true,
                'data' => ['history_id' => 9003, 'qris_string' => 'INIT-RETRY-QRISLY', 'final_amount' => 5001],
            ], 200),
        ]);
        $this->actingAs($user)
            ->post(route('account.orders.retry-payment', $failedInitOrder))
            ->assertRedirect(route('account.orders.show', $failedInitOrder));
        $this->assertSame('9003', data_get(json_decode((string) $failedInitOrder->refresh()->getAttribute('metadata'), true), 'komerce.payment_ref'));
    }

    public function test_retry_handles_mid_call_401_during_close_previous_charge_and_paid_refusal(): void
    {
        $user = User::factory()->create(['first_name' => 'Dina', 'last_name' => 'Putri', 'email' => 'dina@example.com', 'phone_number' => '081234567890']);
        $qrisMethod = PaymentMethod::factory()->create([
            'slug' => 'komerce-qris',
            'driver' => 'komerce',
            'is_enabled' => true,
            'metadata' => json_encode(['payment_type' => 'qris']),
        ]);

        // Direct RetryKomercePayment::handle() on Paid order throws PaymentRetryRefused::alreadyPaid().
        $paidOrder = Order::factory()->create([
            'customer_id' => $user->id,
            'payment_method_id' => $qrisMethod->id,
            'payment_status' => PaymentStatus::Paid,
            'status' => OrderStatus::Processing,
            'price_amount' => 20000,
            'currency_code' => 'IDR',
        ]);
        try {
            resolve(RetryKomercePayment::class)->handle($paidOrder);
            $this->fail('Expected PaymentRetryRefused::alreadyPaid().');
        } catch (PaymentRetryRefused $e) {
            $this->assertSame(PaymentRetryRefused::ALREADY_PAID, $e->reason);
        }

        // Scenario A: QRISLY starts enabled, status check returns 401 -> switches to Payment API.
        config()->set('komerce.payment_api_key', 'test-komerce-key');
        config()->set('komerce.qrisly_api_key', 'qrisly-key');
        config()->set('komerce.qrisly_qris_id', '18');
        config()->set('komerce.qrisly_base_url', 'https://qrisly.example.test/user');

        $orderQrisly401 = Order::factory()->create([
            'customer_id' => $user->id,
            'payment_method_id' => $qrisMethod->id,
            'payment_status' => PaymentStatus::Pending,
            'status' => OrderStatus::New,
            'price_amount' => 30000,
            'currency_code' => 'IDR',
            'number' => 'ORD-MID-401-A',
            'metadata' => json_encode([
                'komerce' => [
                    'provider' => 'qrisly',
                    'payment_type' => 'qris',
                    'payment_ref' => '9010',
                    'qrisly_history_id' => '9010',
                ],
            ]),
        ]);
        OrderItem::factory()->create(['order_id' => $orderQrisly401->id, 'name' => 'Item', 'quantity' => 1, 'unit_price_amount' => 30000]);
        PaymentTransaction::query()->create([
            'order_id' => $orderQrisly401->id,
            'payment_method_id' => $qrisMethod->id,
            'driver' => 'komerce',
            'reference' => '9010',
            'type' => TransactionType::Initiate,
            'amount' => 30000,
            'currency_code' => 'IDR',
            'status' => TransactionStatus::Pending,
            'metadata' => ['komerce_provider' => 'qrisly'],
        ]);

        Http::fake([
            'https://qrisly.example.test/user/api/v1/qrisly/payment-status/9010' => Http::response(['message' => 'Unauthorized'], 401),
            'https://payment.example.test/user/api/v1/user/payment/create' => Http::response([
                'success' => true,
                'data' => ['payment_id' => 'KOMPAY-MID-401', 'qris_string' => 'PAYAPI-QRIS', 'amount' => 30000],
            ], 200),
        ]);

        $resultA = resolve(RetryKomercePayment::class)->handle($orderQrisly401);
        $this->assertSame('payment_api', $resultA['provider']);
        $this->assertSame('KOMPAY-MID-401', $resultA['payment_id']);
        $this->assertFalse(Komerce::qrislyEnabled());
        $this->assertDatabaseHas((new PaymentTransaction)->getTable(), [
            'order_id' => $orderQrisly401->id,
            'reference' => '9010',
            'status' => TransactionStatus::Failed->value,
        ]);

        // Scenario B: Payment API starts enabled, status check returns 401 -> switches to QRISLY.
        Cache::flush();
        resolve(KomerceCapabilities::class)->forget();
        config()->set('komerce.payment_api_key', 'test-komerce-key');
        config()->set('komerce.qrisly_api_key', 'qrisly-key');
        config()->set('komerce.qrisly_qris_id', '18');

        $orderPayApi401 = Order::factory()->create([
            'customer_id' => $user->id,
            'payment_method_id' => $qrisMethod->id,
            'payment_status' => PaymentStatus::Pending,
            'status' => OrderStatus::New,
            'price_amount' => 30000,
            'currency_code' => 'IDR',
            'number' => 'ORD-MID-401-B',
            'metadata' => json_encode([
                'komerce' => [
                    'provider' => 'payment_api',
                    'payment_type' => 'qris',
                    'payment_ref' => 'KOMPAY-MID-OLD',
                ],
            ]),
        ]);
        PaymentTransaction::query()->create([
            'order_id' => $orderPayApi401->id,
            'payment_method_id' => $qrisMethod->id,
            'driver' => 'komerce',
            'reference' => 'KOMPAY-MID-OLD',
            'type' => TransactionType::Initiate,
            'amount' => 30000,
            'currency_code' => 'IDR',
            'status' => TransactionStatus::Pending,
            'metadata' => ['komerce_provider' => 'payment_api'],
        ]);

        Http::swap(new Factory);
        Http::fake([
            'https://payment.example.test/user/api/v1/user/payment/status/KOMPAY-MID-OLD' => Http::response(['message' => 'Unauthorized'], 401),
            'https://qrisly.example.test/user/api/v1/qrisly/generate-qris' => Http::response([
                'success' => true,
                'data' => ['history_id' => 9020, 'qris_string' => 'QRISLY-MID-401', 'final_amount' => 30001],
            ], 200),
        ]);

        $resultB = resolve(RetryKomercePayment::class)->handle($orderPayApi401);
        $this->assertSame('qrisly', $resultB['provider']);
        $this->assertSame('9020', $resultB['payment_id']);
        $this->assertFalse(Komerce::paymentEnabled());
        $this->assertDatabaseHas((new PaymentTransaction)->getTable(), [
            'order_id' => $orderPayApi401->id,
            'reference' => 'KOMPAY-MID-OLD',
            'status' => TransactionStatus::Failed->value,
        ]);

        // Scenario C: Payment API 500 during closePreviousCharge() still aborts (no double charge).
        Cache::flush();
        resolve(KomerceCapabilities::class)->forget();
        $orderPayApi500 = Order::factory()->create([
            'customer_id' => $user->id,
            'payment_method_id' => $qrisMethod->id,
            'payment_status' => PaymentStatus::Pending,
            'status' => OrderStatus::New,
            'price_amount' => 30000,
            'currency_code' => 'IDR',
            'number' => 'ORD-MID-500',
            'metadata' => json_encode([
                'komerce' => [
                    'provider' => 'payment_api',
                    'payment_type' => 'qris',
                    'payment_ref' => 'KOMPAY-MID-500',
                ],
            ]),
        ]);
        Http::swap(new Factory);
        Http::fake([
            'https://payment.example.test/user/api/v1/user/payment/status/KOMPAY-MID-500' => Http::response(['message' => 'Down'], 500),
            'https://qrisly.example.test/user/*' => Http::response(['success' => true], 200),
        ]);
        $this->expectException(RuntimeException::class);
        resolve(RetryKomercePayment::class)->handle($orderPayApi500);
    }

    public function test_dual_enabled_qris_retry_over_100m_routes_to_payment_api_without_calling_qrisly(): void
    {
        config()->set('komerce.payment_api_key', 'test-komerce-key');
        config()->set('komerce.payment_base_url', 'https://payment.example.test/user');
        config()->set('komerce.qrisly_api_key', 'qrisly-key');
        config()->set('komerce.qrisly_qris_id', '18');
        config()->set('komerce.qrisly_base_url', 'https://qrisly.example.test/user');

        $user = User::factory()->create([
            'first_name' => 'Raka',
            'last_name' => 'Pratama',
            'email' => 'raka-100m@example.com',
            'phone_number' => '081234567890',
        ]);
        $qrisMethod = PaymentMethod::factory()->create([
            'slug' => 'komerce-qris',
            'driver' => 'komerce',
            'is_enabled' => true,
            'metadata' => json_encode(['payment_type' => 'qris']),
        ]);

        $order = Order::factory()->create([
            'customer_id' => $user->id,
            'payment_method_id' => $qrisMethod->id,
            'payment_status' => PaymentStatus::Pending,
            'status' => OrderStatus::New,
            'price_amount' => 100_000_001,
            'currency_code' => 'IDR',
            'number' => 'ORD-QRIS-100M-RETRY',
            'metadata' => json_encode([
                'komerce' => [
                    'provider' => 'payment_api',
                    'payment_type' => 'qris',
                    'payment_ref' => 'KOMPAY-100M-OLD',
                ],
            ]),
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'name' => 'Produk High Value',
            'quantity' => 1,
            'unit_price_amount' => 100_000_001,
        ]);
        PaymentTransaction::query()->create([
            'order_id' => $order->id,
            'payment_method_id' => $qrisMethod->id,
            'driver' => 'komerce',
            'reference' => 'KOMPAY-100M-OLD',
            'type' => TransactionType::Initiate,
            'amount' => 100_000_001,
            'currency_code' => 'IDR',
            'status' => TransactionStatus::Pending,
            'metadata' => ['komerce_provider' => 'payment_api'],
        ]);

        $this->assertTrue(resolve(ResolveKomercePaymentInstructions::class)->canRetry($order));

        Http::fake([
            'https://payment.example.test/user/api/v1/user/payment/status/KOMPAY-100M-OLD' => Http::response([
                'success' => true,
                'data' => [
                    'payment_id' => 'KOMPAY-100M-OLD',
                    'status' => 'EXPIRED',
                    'amount' => 100_000_001,
                ],
            ], 200),
            'https://payment.example.test/user/api/v1/user/payment/create' => Http::response([
                'success' => true,
                'data' => [
                    'payment_id' => 'KOMPAY-100M-NEW',
                    'qris_string' => 'PAYMENT-API-100M-QRIS',
                    'expiry_date' => '2026-08-05T19:00:00+07:00',
                    'amount' => 100_000_001,
                ],
            ], 200),
            'https://qrisly.example.test/user/*' => Http::response(['success' => false], 500),
        ]);

        $result = resolve(RetryKomercePayment::class)->handle($order);

        $this->assertSame('payment_api', $result['provider']);
        $this->assertSame('KOMPAY-100M-NEW', $result['payment_id']);
        $this->assertSame('PAYMENT-API-100M-QRIS', $result['qris_string']);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/qrisly/'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/payment/create'));

        $this->assertDatabaseHas((new PaymentTransaction)->getTable(), [
            'order_id' => $order->id,
            'reference' => 'KOMPAY-100M-OLD',
            'status' => TransactionStatus::Failed->value,
        ]);
        $this->assertDatabaseHas((new PaymentTransaction)->getTable(), [
            'order_id' => $order->id,
            'reference' => 'KOMPAY-100M-NEW',
            'status' => TransactionStatus::Pending->value,
        ]);
    }

    public function test_qrisly_retry_refuses_on_amount_mismatch_without_creating_duplicate_charge(): void
    {
        config()->set('komerce.payment_api_key', '');
        config()->set('komerce.qrisly_api_key', 'qrisly-key');
        config()->set('komerce.qrisly_qris_id', '18');
        config()->set('komerce.qrisly_base_url', 'https://qrisly.example.test/user');

        $user = User::factory()->create([
            'first_name' => 'Maya',
            'last_name' => 'Lestari',
            'email' => 'maya-mismatch@example.com',
            'phone_number' => '081234567890',
        ]);
        $qrisMethod = PaymentMethod::factory()->create([
            'slug' => 'komerce-qris',
            'driver' => 'komerce',
            'is_enabled' => true,
            'metadata' => json_encode(['payment_type' => 'qris']),
        ]);

        $order = Order::factory()->create([
            'customer_id' => $user->id,
            'payment_method_id' => $qrisMethod->id,
            'payment_status' => PaymentStatus::Pending,
            'status' => OrderStatus::New,
            'price_amount' => 50_000,
            'currency_code' => 'IDR',
            'number' => 'ORD-QRISLY-MISMATCH',
            'metadata' => json_encode([
                'komerce' => [
                    'provider' => 'qrisly',
                    'payment_type' => 'qris',
                    'payment_ref' => '9050',
                    'qrisly_history_id' => '9050',
                    'qrisly_qris_id' => '18',
                ],
            ]),
        ]);
        $tx = PaymentTransaction::query()->create([
            'order_id' => $order->id,
            'payment_method_id' => $qrisMethod->id,
            'driver' => 'komerce',
            'reference' => '9050',
            'type' => TransactionType::Initiate,
            'amount' => 50_000,
            'currency_code' => 'IDR',
            'status' => TransactionStatus::Pending,
            'metadata' => ['komerce_provider' => 'qrisly'],
        ]);

        Http::fake([
            'https://qrisly.example.test/user/api/v1/qrisly/payment-status/9050' => Http::response([
                'success' => true,
                'data' => [
                    'history_id' => 9050,
                    'payment_status' => 'paid',
                    'final_amount' => 10_000,
                ],
            ], 200),
            'https://qrisly.example.test/user/api/v1/qrisly/generate-qris' => Http::response([
                'success' => true,
                'data' => [
                    'history_id' => 8888,
                    'qris_string' => 'SHOULD-NOT-BE-CREATED',
                    'final_amount' => 50_001,
                ],
            ], 200),
        ]);

        try {
            resolve(RetryKomercePayment::class)->handle($order);
            $this->fail('Expected PaymentRetryRefused on QRISLY amount_mismatch.');
        } catch (PaymentRetryRefused $e) {
            $this->assertSame(PaymentRetryRefused::NOT_ELIGIBLE, $e->reason);
            $this->assertStringContainsString('selisih nominal', $e->getMessage());
        }

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/qrisly/generate-qris'));
        $this->assertSame(TransactionStatus::Pending, $tx->fresh()->status);
        $this->assertSame(1, PaymentTransaction::query()->where('order_id', $order->id)->count());
        $this->assertDatabaseHas((new PaymentDispute)->getTable(), [
            'order_id' => $order->id,
            'type' => PaymentDispute::TYPE_AMOUNT_MISMATCH,
            'reference' => '9050',
        ]);

        // Even if QRISLY is later disabled and Payment API is enabled, an open mismatch dispute still blocks retry.
        config()->set('komerce.qrisly_api_key', '');
        config()->set('komerce.qrisly_qris_id', '');
        config()->set('komerce.payment_api_key', 'test-komerce-key');
        Http::swap(new Factory);
        Http::fake();

        try {
            resolve(RetryKomercePayment::class)->handle($order->fresh());
            $this->fail('Expected PaymentRetryRefused when open amount_mismatch dispute exists.');
        } catch (PaymentRetryRefused $e) {
            $this->assertSame(PaymentRetryRefused::NOT_ELIGIBLE, $e->reason);
        }

        Http::assertNothingSent();
        $this->assertSame(TransactionStatus::Pending, $tx->fresh()->status);
    }

    public function test_qrisly_retry_succeeds_after_amount_mismatch_dispute_resolved_or_dismissed_and_still_blocks_active_or_applied_disputes(): void
    {
        config()->set('komerce.payment_api_key', '');
        config()->set('komerce.qrisly_api_key', 'qrisly-key');
        config()->set('komerce.qrisly_qris_id', '18');
        config()->set('komerce.qrisly_base_url', 'https://qrisly.example.test/user');

        $user = User::factory()->create([
            'first_name' => 'Sinta',
            'last_name' => 'Wijaya',
            'email' => 'sinta-dispute@example.com',
            'phone_number' => '081234567890',
        ]);
        $qrisMethod = PaymentMethod::factory()->create([
            'slug' => 'komerce-qris',
            'driver' => 'komerce',
            'is_enabled' => true,
            'metadata' => json_encode(['payment_type' => 'qris']),
        ]);

        // Case 1: STATUS_RESOLVED_REFUNDED allows retry while QRISLY remains enabled,
        // marks old Pending tx Failed, creates new charge, and does not create any STATUS_OPEN dispute.
        $refundedOrder = Order::factory()->create([
            'customer_id' => $user->id,
            'payment_method_id' => $qrisMethod->id,
            'payment_status' => PaymentStatus::Pending,
            'status' => OrderStatus::New,
            'price_amount' => 50_000,
            'currency_code' => 'IDR',
            'number' => 'ORD-QRISLY-RESOLVED-REFUND',
            'metadata' => json_encode([
                'komerce' => [
                    'provider' => 'qrisly',
                    'payment_type' => 'qris',
                    'payment_ref' => '9050',
                    'qrisly_history_id' => '9050',
                    'qrisly_qris_id' => '18',
                ],
            ]),
        ]);
        $refundedOldTx = PaymentTransaction::query()->create([
            'order_id' => $refundedOrder->id,
            'payment_method_id' => $qrisMethod->id,
            'driver' => 'komerce',
            'reference' => '9050',
            'type' => TransactionType::Initiate,
            'amount' => 50_000,
            'currency_code' => 'IDR',
            'status' => TransactionStatus::Pending,
            'metadata' => ['komerce_provider' => 'qrisly'],
        ]);
        PaymentDispute::query()->create([
            'order_id' => $refundedOrder->id,
            'driver' => 'komerce',
            'reference' => '9050',
            'type' => PaymentDispute::TYPE_AMOUNT_MISMATCH,
            'status' => PaymentDispute::STATUS_RESOLVED_REFUNDED,
            'amount' => 10_000,
            'expected_amount' => 50_000,
            'currency_code' => 'IDR',
            'reason' => 'Refund manual sudah dicatat.',
        ]);

        Http::fake([
            'https://qrisly.example.test/user/api/v1/qrisly/payment-status/9050' => Http::response([
                'success' => true,
                'data' => [
                    'history_id' => 9050,
                    'payment_status' => 'paid',
                    'final_amount' => 10_000,
                ],
            ], 200),
            'https://qrisly.example.test/user/api/v1/qrisly/generate-qris' => Http::response([
                'success' => true,
                'data' => [
                    'history_id' => 9051,
                    'qris_string' => 'NEW-QRISLY-AFTER-REFUND',
                    'final_amount' => 50_001,
                    'expiry_time' => '2026-08-05T19:00:00+07:00',
                ],
            ], 200),
        ]);

        $refundedResult = resolve(RetryKomercePayment::class)->handle($refundedOrder);
        $this->assertSame('qrisly', $refundedResult['provider']);
        $this->assertSame('9051', $refundedResult['payment_id']);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/qrisly/generate-qris'));
        $this->assertSame(TransactionStatus::Failed, $refundedOldTx->fresh()->status);
        $this->assertDatabaseHas((new PaymentTransaction)->getTable(), [
            'order_id' => $refundedOrder->id,
            'reference' => '9051',
            'status' => TransactionStatus::Pending->value,
        ]);
        $this->assertSame(
            0,
            PaymentDispute::query()
                ->where('order_id', $refundedOrder->id)
                ->where('status', PaymentDispute::STATUS_OPEN)
                ->count(),
        );
        $this->assertSame(1, PaymentDispute::query()->where('order_id', $refundedOrder->id)->count());

        // A subsequent mismatch on the NEW reference (9051) still refuses retry cleanly.
        Http::swap(new Factory);
        Http::fake([
            'https://qrisly.example.test/user/api/v1/qrisly/payment-status/9051' => Http::response([
                'success' => true,
                'data' => [
                    'history_id' => 9051,
                    'payment_status' => 'paid',
                    'final_amount' => 20_000,
                ],
            ], 200),
            'https://qrisly.example.test/user/api/v1/qrisly/generate-qris' => Http::response([
                'success' => true,
                'data' => ['history_id' => 9052, 'qris_string' => 'SHOULD-NOT-RUN', 'final_amount' => 50_001],
            ], 200),
        ]);

        try {
            resolve(RetryKomercePayment::class)->handle($refundedOrder->fresh());
            $this->fail('Expected PaymentRetryRefused on new mismatch for reference 9051.');
        } catch (PaymentRetryRefused $e) {
            $this->assertSame(PaymentRetryRefused::NOT_ELIGIBLE, $e->reason);
        }
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/qrisly/generate-qris'));
        $this->assertDatabaseHas((new PaymentDispute)->getTable(), [
            'order_id' => $refundedOrder->id,
            'reference' => '9051',
            'type' => PaymentDispute::TYPE_AMOUNT_MISMATCH,
            'status' => PaymentDispute::STATUS_OPEN,
        ]);

        // Case 2: STATUS_DISMISSED also allows retry while QRISLY remains enabled without creating an open dispute.
        $dismissedOrder = Order::factory()->create([
            'customer_id' => $user->id,
            'payment_method_id' => $qrisMethod->id,
            'payment_status' => PaymentStatus::Pending,
            'status' => OrderStatus::New,
            'price_amount' => 50_000,
            'currency_code' => 'IDR',
            'number' => 'ORD-QRISLY-DISMISSED',
            'metadata' => json_encode([
                'komerce' => [
                    'provider' => 'qrisly',
                    'payment_type' => 'qris',
                    'payment_ref' => '9060',
                    'qrisly_history_id' => '9060',
                    'qrisly_qris_id' => '18',
                ],
            ]),
        ]);
        $dismissedOldTx = PaymentTransaction::query()->create([
            'order_id' => $dismissedOrder->id,
            'payment_method_id' => $qrisMethod->id,
            'driver' => 'komerce',
            'reference' => '9060',
            'type' => TransactionType::Initiate,
            'amount' => 50_000,
            'currency_code' => 'IDR',
            'status' => TransactionStatus::Pending,
            'metadata' => ['komerce_provider' => 'qrisly'],
        ]);
        PaymentDispute::query()->create([
            'order_id' => $dismissedOrder->id,
            'driver' => 'komerce',
            'reference' => '9060',
            'type' => PaymentDispute::TYPE_AMOUNT_MISMATCH,
            'status' => PaymentDispute::STATUS_DISMISSED,
            'amount' => 10_000,
            'expected_amount' => 50_000,
            'currency_code' => 'IDR',
            'reason' => 'Ditutup oleh staf.',
        ]);

        Http::swap(new Factory);
        Http::fake([
            'https://qrisly.example.test/user/api/v1/qrisly/payment-status/9060' => Http::response([
                'success' => true,
                'data' => [
                    'history_id' => 9060,
                    'payment_status' => 'paid',
                    'final_amount' => 10_000,
                ],
            ], 200),
            'https://qrisly.example.test/user/api/v1/qrisly/generate-qris' => Http::response([
                'success' => true,
                'data' => [
                    'history_id' => 9061,
                    'qris_string' => 'NEW-QRISLY-AFTER-DISMISS',
                    'final_amount' => 50_001,
                    'expiry_time' => '2026-08-05T19:00:00+07:00',
                ],
            ], 200),
        ]);

        $dismissedResult = resolve(RetryKomercePayment::class)->handle($dismissedOrder);
        $this->assertSame('qrisly', $dismissedResult['provider']);
        $this->assertSame('9061', $dismissedResult['payment_id']);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/qrisly/generate-qris'));
        $this->assertSame(TransactionStatus::Failed, $dismissedOldTx->fresh()->status);
        $this->assertDatabaseHas((new PaymentTransaction)->getTable(), [
            'order_id' => $dismissedOrder->id,
            'reference' => '9061',
            'status' => TransactionStatus::Pending->value,
        ]);
        $this->assertSame(
            0,
            PaymentDispute::query()
                ->where('order_id', $dismissedOrder->id)
                ->where('status', PaymentDispute::STATUS_OPEN)
                ->count(),
        );

        // Case 3: STATUS_OPEN, STATUS_INVESTIGATING, and STATUS_RESOLVED_APPLIED all refuse retry cleanly.
        foreach ([
            PaymentDispute::STATUS_OPEN,
            PaymentDispute::STATUS_INVESTIGATING,
            PaymentDispute::STATUS_RESOLVED_APPLIED,
        ] as $index => $blockingStatus) {
            $ref = (string) (9070 + $index);
            $blockedOrder = Order::factory()->create([
                'customer_id' => $user->id,
                'payment_method_id' => $qrisMethod->id,
                'payment_status' => PaymentStatus::Pending,
                'status' => OrderStatus::New,
                'price_amount' => 50_000,
                'currency_code' => 'IDR',
                'number' => 'ORD-QRISLY-BLOCKED-'.$index,
                'metadata' => json_encode([
                    'komerce' => [
                        'provider' => 'qrisly',
                        'payment_type' => 'qris',
                        'payment_ref' => $ref,
                        'qrisly_history_id' => $ref,
                        'qrisly_qris_id' => '18',
                    ],
                ]),
            ]);
            $blockedTx = PaymentTransaction::query()->create([
                'order_id' => $blockedOrder->id,
                'payment_method_id' => $qrisMethod->id,
                'driver' => 'komerce',
                'reference' => $ref,
                'type' => TransactionType::Initiate,
                'amount' => 50_000,
                'currency_code' => 'IDR',
                'status' => TransactionStatus::Pending,
                'metadata' => ['komerce_provider' => 'qrisly'],
            ]);
            PaymentDispute::query()->create([
                'order_id' => $blockedOrder->id,
                'driver' => 'komerce',
                'reference' => $ref,
                'type' => PaymentDispute::TYPE_AMOUNT_MISMATCH,
                'status' => $blockingStatus,
                'amount' => 10_000,
                'expected_amount' => 50_000,
                'currency_code' => 'IDR',
            ]);

            Http::swap(new Factory);
            Http::fake();

            try {
                resolve(RetryKomercePayment::class)->handle($blockedOrder);
                $this->fail("Expected PaymentRetryRefused for dispute status {$blockingStatus}.");
            } catch (PaymentRetryRefused $e) {
                $this->assertSame(PaymentRetryRefused::NOT_ELIGIBLE, $e->reason);
            }

            Http::assertNothingSent();
            $this->assertSame(TransactionStatus::Pending, $blockedTx->fresh()->status);
        }
    }

    public function test_payment_api_retry_succeeds_after_amount_mismatch_dispute_resolved_or_dismissed(): void
    {
        config()->set('komerce.payment_api_key', 'test-komerce-key');
        config()->set('komerce.payment_base_url', 'https://payment.example.test/user');

        $user = User::factory()->create([
            'first_name' => 'Budi',
            'last_name' => 'Santoso',
            'email' => 'budi-payapi-dispute@example.com',
            'phone_number' => '081234567890',
        ]);
        $vaMethod = PaymentMethod::factory()->create([
            'slug' => 'komerce-va-bca',
            'driver' => 'komerce',
            'is_enabled' => true,
            'metadata' => json_encode(['channel_code' => 'BCA', 'payment_type' => 'bank_transfer']),
        ]);

        // Case 1: STATUS_RESOLVED_REFUNDED on payment_api allows retry via HTTP endpoint,
        // marks old Pending tx Failed, creates new charge, and never calls cancel or creates STATUS_OPEN dispute.
        $refundedOrder = Order::factory()->create([
            'customer_id' => $user->id,
            'payment_method_id' => $vaMethod->id,
            'payment_status' => PaymentStatus::Pending,
            'status' => OrderStatus::New,
            'price_amount' => 50_000,
            'currency_code' => 'IDR',
            'number' => 'ORD-PAYAPI-RESOLVED-REFUND',
            'metadata' => json_encode([
                'komerce' => [
                    'provider' => 'payment_api',
                    'payment_type' => 'bank_transfer',
                    'channel_code' => 'BCA',
                    'payment_ref' => 'KOMPAY-9050',
                ],
            ]),
        ]);
        OrderItem::factory()->create([
            'order_id' => $refundedOrder->id,
            'name' => 'Produk Payment API',
            'quantity' => 1,
            'unit_price_amount' => 50_000,
        ]);
        $refundedOldTx = PaymentTransaction::query()->create([
            'order_id' => $refundedOrder->id,
            'payment_method_id' => $vaMethod->id,
            'driver' => 'komerce',
            'reference' => 'KOMPAY-9050',
            'type' => TransactionType::Initiate,
            'amount' => 50_000,
            'currency_code' => 'IDR',
            'status' => TransactionStatus::Pending,
            'metadata' => ['komerce_provider' => 'payment_api'],
        ]);
        PaymentDispute::query()->create([
            'order_id' => $refundedOrder->id,
            'driver' => 'komerce',
            'reference' => 'KOMPAY-9050',
            'type' => PaymentDispute::TYPE_AMOUNT_MISMATCH,
            'status' => PaymentDispute::STATUS_RESOLVED_REFUNDED,
            'amount' => 10_000,
            'expected_amount' => 50_000,
            'currency_code' => 'IDR',
            'reason' => 'Refund manual sudah dicatat.',
        ]);

        Http::fake([
            'https://payment.example.test/user/api/v1/user/payment/status/KOMPAY-9050' => Http::response([
                'success' => true,
                'data' => [
                    'payment_id' => 'KOMPAY-9050',
                    'status' => 'PAID',
                    'amount' => 10_000,
                ],
            ], 200),
            'https://payment.example.test/user/api/v1/user/payment/create' => Http::response([
                'success' => true,
                'data' => [
                    'payment_id' => 'KOMPAY-9051',
                    'virtual_account_number' => '8800112233',
                    'bank_code' => 'BCA',
                    'amount' => 50_000,
                    'expiry_date' => '2026-08-05T19:00:00+07:00',
                ],
            ], 200),
        ]);

        $this->actingAs($user)
            ->post(route('account.orders.retry-payment', $refundedOrder))
            ->assertRedirect(route('account.orders.show', $refundedOrder))
            ->assertSessionHas('success');

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/user/payment/create'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/user/payment/cancel'));
        $this->assertSame(TransactionStatus::Failed, $refundedOldTx->fresh()->status);
        $this->assertDatabaseHas((new PaymentTransaction)->getTable(), [
            'order_id' => $refundedOrder->id,
            'reference' => 'KOMPAY-9051',
            'status' => TransactionStatus::Pending->value,
        ]);
        $this->assertSame('KOMPAY-9051', data_get(json_decode((string) $refundedOrder->fresh()->getAttribute('metadata'), true), 'komerce.payment_ref'));
        $this->assertSame(
            0,
            PaymentDispute::query()
                ->where('order_id', $refundedOrder->id)
                ->where('status', PaymentDispute::STATUS_OPEN)
                ->count(),
        );

        // Resolved dispute on KOMPAY-9050 does NOT whitelist the new reference KOMPAY-9051.
        Http::swap(new Factory);
        Http::fake([
            'https://payment.example.test/user/api/v1/user/payment/status/KOMPAY-9051' => Http::response([
                'success' => true,
                'data' => [
                    'payment_id' => 'KOMPAY-9051',
                    'status' => 'PAID',
                    'amount' => 20_000,
                ],
            ], 200),
        ]);

        try {
            resolve(RetryKomercePayment::class)->handle($refundedOrder->fresh());
            $this->fail('Expected PaymentRetryRefused::alreadyPaid() for new reference KOMPAY-9051.');
        } catch (PaymentRetryRefused $e) {
            $this->assertSame(PaymentRetryRefused::ALREADY_PAID, $e->reason);
        }
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/user/payment/create'));

        // Case 2: STATUS_DISMISSED on payment_api also allows retry cleanly.
        $dismissedOrder = Order::factory()->create([
            'customer_id' => $user->id,
            'payment_method_id' => $vaMethod->id,
            'payment_status' => PaymentStatus::Pending,
            'status' => OrderStatus::New,
            'price_amount' => 50_000,
            'currency_code' => 'IDR',
            'number' => 'ORD-PAYAPI-DISMISSED',
            'metadata' => json_encode([
                'komerce' => [
                    'provider' => 'payment_api',
                    'payment_type' => 'bank_transfer',
                    'channel_code' => 'BCA',
                    'payment_ref' => 'KOMPAY-9060',
                ],
            ]),
        ]);
        OrderItem::factory()->create([
            'order_id' => $dismissedOrder->id,
            'name' => 'Produk Payment API Dismissed',
            'quantity' => 1,
            'unit_price_amount' => 50_000,
        ]);
        $dismissedOldTx = PaymentTransaction::query()->create([
            'order_id' => $dismissedOrder->id,
            'payment_method_id' => $vaMethod->id,
            'driver' => 'komerce',
            'reference' => 'KOMPAY-9060',
            'type' => TransactionType::Initiate,
            'amount' => 50_000,
            'currency_code' => 'IDR',
            'status' => TransactionStatus::Pending,
            'metadata' => ['komerce_provider' => 'payment_api'],
        ]);
        PaymentDispute::query()->create([
            'order_id' => $dismissedOrder->id,
            'driver' => 'komerce',
            'reference' => 'KOMPAY-9060',
            'type' => PaymentDispute::TYPE_AMOUNT_MISMATCH,
            'status' => PaymentDispute::STATUS_DISMISSED,
            'amount' => 10_000,
            'expected_amount' => 50_000,
            'currency_code' => 'IDR',
            'reason' => 'Ditutup oleh staf.',
        ]);

        Http::swap(new Factory);
        Http::fake([
            'https://payment.example.test/user/api/v1/user/payment/create' => Http::response([
                'success' => true,
                'data' => [
                    'payment_id' => 'KOMPAY-9061',
                    'virtual_account_number' => '8800112244',
                    'bank_code' => 'BCA',
                    'amount' => 50_000,
                    'expiry_date' => '2026-08-05T19:00:00+07:00',
                ],
            ], 200),
        ]);

        $dismissedResult = resolve(RetryKomercePayment::class)->handle($dismissedOrder);
        $this->assertSame('payment_api', $dismissedResult['provider']);
        $this->assertSame('KOMPAY-9061', $dismissedResult['payment_id']);
        $this->assertSame(TransactionStatus::Failed, $dismissedOldTx->fresh()->status);
        $this->assertDatabaseHas((new PaymentTransaction)->getTable(), [
            'order_id' => $dismissedOrder->id,
            'reference' => 'KOMPAY-9061',
            'status' => TransactionStatus::Pending->value,
        ]);

        // Case 3: Active / applied mismatch disputes on payment_api still block with zero HTTP calls.
        foreach ([
            PaymentDispute::STATUS_OPEN,
            PaymentDispute::STATUS_INVESTIGATING,
            PaymentDispute::STATUS_RESOLVED_APPLIED,
        ] as $index => $blockingStatus) {
            $ref = 'KOMPAY-907'.$index;
            $blockedOrder = Order::factory()->create([
                'customer_id' => $user->id,
                'payment_method_id' => $vaMethod->id,
                'payment_status' => PaymentStatus::Pending,
                'status' => OrderStatus::New,
                'price_amount' => 50_000,
                'currency_code' => 'IDR',
                'number' => 'ORD-PAYAPI-BLOCKED-'.$index,
                'metadata' => json_encode([
                    'komerce' => [
                        'provider' => 'payment_api',
                        'payment_type' => 'bank_transfer',
                        'channel_code' => 'BCA',
                        'payment_ref' => $ref,
                    ],
                ]),
            ]);
            PaymentDispute::query()->create([
                'order_id' => $blockedOrder->id,
                'driver' => 'komerce',
                'reference' => $ref,
                'type' => PaymentDispute::TYPE_AMOUNT_MISMATCH,
                'status' => $blockingStatus,
                'amount' => 10_000,
                'expected_amount' => 50_000,
                'currency_code' => 'IDR',
            ]);

            Http::swap(new Factory);
            Http::fake();

            try {
                resolve(RetryKomercePayment::class)->handle($blockedOrder);
                $this->fail("Expected PaymentRetryRefused for payment_api dispute status {$blockingStatus}.");
            } catch (PaymentRetryRefused $e) {
                $this->assertSame(PaymentRetryRefused::NOT_ELIGIBLE, $e->reason);
            }

            Http::assertNothingSent();
        }
    }

    public function test_integer_qrisly_history_id_without_instructions_or_transaction_reconciles_and_blocks_mismatch_on_retry(): void
    {
        config()->set('komerce.payment_api_key', '');
        config()->set('komerce.qrisly_api_key', 'qrisly-key');
        config()->set('komerce.qrisly_qris_id', '18');
        config()->set('komerce.qrisly_base_url', 'https://qrisly.example.test/user');

        $user = User::factory()->create([
            'first_name' => 'Lina',
            'last_name' => 'Kartika',
            'email' => 'lina-int-history@example.com',
            'phone_number' => '081234567890',
        ]);
        $qrisMethod = PaymentMethod::factory()->create([
            'slug' => 'komerce-qris',
            'driver' => 'komerce',
            'is_enabled' => true,
            'metadata' => json_encode(['payment_type' => 'qris']),
        ]);

        // Case 1: Integer qrisly_history_id (no payment_ref, no payment_instructions, no PaymentTransaction)
        // with paid mismatch reconciles against 9082, records OPEN dispute, and blocks duplicate charge on retry.
        $mismatchOrder = Order::factory()->create([
            'customer_id' => $user->id,
            'payment_method_id' => $qrisMethod->id,
            'payment_status' => PaymentStatus::Pending,
            'status' => OrderStatus::New,
            'price_amount' => 50_000,
            'currency_code' => 'IDR',
            'number' => 'ORD-QRISLY-INT-MISMATCH',
            'metadata' => json_encode([
                'komerce' => [
                    'provider' => 'qrisly',
                    'payment_type' => 'qris',
                    'qrisly_history_id' => 9082,
                ],
            ]),
        ]);

        Http::fake([
            'https://qrisly.example.test/user/api/v1/qrisly/payment-status/9082' => Http::response([
                'success' => true,
                'data' => [
                    'history_id' => 9082,
                    'payment_status' => 'paid',
                    'final_amount' => 10_000,
                ],
            ], 200),
            'https://qrisly.example.test/user/api/v1/qrisly/generate-qris' => Http::response([
                'success' => true,
                'data' => [
                    'history_id' => 9083,
                    'qris_string' => 'SHOULD-NOT-GENERATE',
                    'final_amount' => 50_001,
                ],
            ], 200),
        ]);

        try {
            resolve(RetryKomercePayment::class)->handle($mismatchOrder);
            $this->fail('Expected PaymentRetryRefused for integer qrisly_history_id mismatch.');
        } catch (PaymentRetryRefused $e) {
            $this->assertSame(PaymentRetryRefused::NOT_ELIGIBLE, $e->reason);
            $this->assertStringContainsString('selisih nominal', $e->getMessage());
        }

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/qrisly/payment-status/9082'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/qrisly/generate-qris'));
        $this->assertDatabaseHas((new PaymentDispute)->getTable(), [
            'order_id' => $mismatchOrder->id,
            'reference' => '9082',
            'type' => PaymentDispute::TYPE_AMOUNT_MISMATCH,
            'status' => PaymentDispute::STATUS_OPEN,
        ]);

        // Case 2: Direct SyncKomercePaymentStatus::handle() with integer qrisly_history_id marks paid order Paid.
        $paidOrder = Order::factory()->create([
            'customer_id' => $user->id,
            'payment_method_id' => $qrisMethod->id,
            'payment_status' => PaymentStatus::Pending,
            'status' => OrderStatus::New,
            'price_amount' => 50_000,
            'currency_code' => 'IDR',
            'number' => 'ORD-QRISLY-INT-SYNC',
            'metadata' => json_encode([
                'komerce' => [
                    'provider' => 'qrisly',
                    'payment_type' => 'qris',
                    'qrisly_history_id' => 9084,
                ],
            ]),
        ]);

        Http::swap(new Factory);
        Http::fake([
            'https://qrisly.example.test/user/api/v1/qrisly/payment-status/9084' => Http::response([
                'success' => true,
                'data' => [
                    'history_id' => 9084,
                    'payment_status' => 'paid',
                    'final_amount' => 50_000,
                ],
            ], 200),
        ]);

        $this->assertSame('handled', resolve(SyncKomercePaymentStatus::class)->handle($paidOrder));
        $this->assertSame(PaymentStatus::Paid, $paidOrder->fresh()->payment_status);

        // Case 3: Unpaid integer qrisly_history_id reconciles cleanly and retries to a new charge.
        $unpaidOrder = Order::factory()->create([
            'customer_id' => $user->id,
            'payment_method_id' => $qrisMethod->id,
            'payment_status' => PaymentStatus::Pending,
            'status' => OrderStatus::New,
            'price_amount' => 50_000,
            'currency_code' => 'IDR',
            'number' => 'ORD-QRISLY-INT-RETRY',
            'metadata' => json_encode([
                'komerce' => [
                    'provider' => 'qrisly',
                    'payment_type' => 'qris',
                    'qrisly_history_id' => 9085,
                ],
            ]),
        ]);

        Http::swap(new Factory);
        Http::fake([
            'https://qrisly.example.test/user/api/v1/qrisly/payment-status/9085' => Http::response([
                'success' => true,
                'data' => [
                    'history_id' => 9085,
                    'payment_status' => 'unpaid',
                    'final_amount' => 50_000,
                ],
            ], 200),
            'https://qrisly.example.test/user/api/v1/qrisly/generate-qris' => Http::response([
                'success' => true,
                'data' => [
                    'history_id' => 9086,
                    'qris_string' => 'NEW-QRISLY-9086',
                    'final_amount' => 50_001,
                    'expiry_time' => '2026-08-05T19:00:00+07:00',
                ],
            ], 200),
        ]);

        $retryResult = resolve(RetryKomercePayment::class)->handle($unpaidOrder);
        $this->assertSame('qrisly', $retryResult['provider']);
        $this->assertSame('9086', $retryResult['payment_id']);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/qrisly/payment-status/9085'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/qrisly/generate-qris'));
    }
}
