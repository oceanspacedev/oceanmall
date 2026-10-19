<?php

declare(strict_types=1);

namespace Tests\Feature\Komerce;

use App\Models\OrderShipment;
use App\Models\OrderShipmentLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\Product;
use Shopper\Payment\Enum\TransactionStatus;
use Shopper\Payment\Enum\TransactionType;
use Shopper\Payment\Models\PaymentTransaction;
use Tests\TestCase;

final class ExpireUnpaidKomerceOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_expire_command_cancels_unpaid_order_and_restores_stock(): void
    {
        config()->set('komerce.payment_api_key', 'test-komerce-key');
        config()->set('komerce.enabled', true);
        config()->set('komerce.payment_base_url', 'https://payment.example.test/user');

        Http::fake([
            'https://payment.example.test/user/api/v1/user/payment/status/pay_expire_1' => Http::response([
                'success' => true,
                'data' => ['payment_id' => 'pay_expire_1', 'status' => 'PENDING', 'amount' => 10000],
            ]),
            'https://payment.example.test/user/api/v1/user/payment/cancel' => Http::response([
                'success' => true,
                'data' => ['status' => 'CANCELLED'],
            ]),
        ]);

        $customer = User::factory()->create();
        $inventory = Inventory::factory()->create();
        /** @var Product $product */
        $product = Product::factory()->standard()->create();
        $product->mutateStock($inventory->id, 5);

        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'status' => OrderStatus::New,
            'payment_status' => PaymentStatus::Pending,
            'currency_code' => 'IDR',
            'metadata' => json_encode([
                'komerce' => [
                    'payment_ref' => 'pay_expire_1',
                    'expiry_date' => now()->subMinute()->toDateTimeString(),
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        PaymentTransaction::query()->create([
            'order_id' => $order->id,
            'reference' => 'pay_expire_1',
            'driver' => 'komerce',
            'type' => TransactionType::Initiate,
            'amount' => 10000,
            'currency_code' => 'IDR',
            'status' => TransactionStatus::Pending,
            'metadata' => [
                'komerce_payment_ref' => 'pay_expire_1',
                'expiry_date' => now()->subMinute()->toDateTimeString(),
            ],
        ]);

        $shipment = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inventory->id,
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'cost' => 0,
            'currency_code' => 'IDR',
            'status' => 'pending',
        ]);

        OrderShipmentLine::query()->create([
            'order_shipment_id' => $shipment->id,
            'purchasable_type' => $product->getMorphClass(),
            'purchasable_id' => $product->id,
            'qty' => 2,
        ]);

        // Simulate stock already decreased at place-order
        $product->decreaseStock($inventory->id, 2);

        $this->artisan('komerce:expire-unpaid-orders')
            ->assertSuccessful();

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(PaymentStatus::Voided, $order->payment_status);
        $this->assertSame(5, $product->fresh()->stockInventory($inventory->id));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://payment.example.test/user/api/v1/user/payment/cancel'
            && $request['payment_id'] === 'pay_expire_1');
    }

    public function test_expire_settles_an_order_paid_just_before_expiry_instead_of_cancelling(): void
    {
        Bus::fake();
        Notification::fake();
        $order = $this->expiredKomerceOrder('pay_paid_late');

        Http::fake([
            'https://payment.example.test/user/api/v1/user/payment/status/pay_paid_late' => Http::response([
                'success' => true,
                'data' => ['payment_id' => 'pay_paid_late', 'status' => 'PAID', 'amount' => 10000],
            ]),
        ]);

        $this->artisan('komerce:expire-unpaid-orders')->assertSuccessful();

        $order->refresh();
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertNotSame(OrderStatus::Cancelled, $order->status);
        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/payment/cancel'));
    }

    public function test_expire_leaves_order_untouched_when_komerce_status_is_unreachable(): void
    {
        Exceptions::fake();
        $order = $this->expiredKomerceOrder('pay_unreachable');

        Http::fake([
            'https://payment.example.test/user/api/v1/user/payment/status/pay_unreachable' => Http::response(['message' => 'boom'], 500),
        ]);

        $this->artisan('komerce:expire-unpaid-orders')->assertSuccessful();

        $order->refresh();
        $this->assertSame(OrderStatus::New, $order->status);
        $this->assertSame(PaymentStatus::Pending, $order->payment_status);
        Exceptions::assertReported(RuntimeException::class);
        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/payment/cancel'));
    }

    private function expiredKomerceOrder(string $paymentRef): Order
    {
        config()->set('komerce.payment_api_key', 'test-komerce-key');
        config()->set('komerce.enabled', true);
        config()->set('komerce.payment_base_url', 'https://payment.example.test/user');

        $order = Order::factory()->create([
            'customer_id' => User::factory()->create()->id,
            'status' => OrderStatus::New,
            'payment_status' => PaymentStatus::Pending,
            'price_amount' => 10000,
            'currency_code' => 'IDR',
            'metadata' => json_encode([
                'komerce' => [
                    'payment_ref' => $paymentRef,
                    'expiry_date' => now()->subMinute()->toDateTimeString(),
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        PaymentTransaction::query()->create([
            'order_id' => $order->id,
            'reference' => $paymentRef,
            'driver' => 'komerce',
            'type' => TransactionType::Initiate,
            'amount' => 10000,
            'currency_code' => 'IDR',
            'status' => TransactionStatus::Pending,
        ]);

        return $order;
    }

    public function test_expire_command_skips_orders_not_yet_expired(): void
    {
        config()->set('komerce.payment_api_key', 'test-komerce-key');
        config()->set('komerce.enabled', true);

        Http::fake();

        $order = Order::factory()->create([
            'status' => OrderStatus::New,
            'payment_status' => PaymentStatus::Pending,
            'metadata' => json_encode([
                'komerce' => [
                    'payment_ref' => 'pay_future',
                    'expiry_date' => now()->addHour()->toDateTimeString(),
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        PaymentTransaction::query()->create([
            'order_id' => $order->id,
            'reference' => 'pay_future',
            'driver' => 'komerce',
            'type' => TransactionType::Initiate,
            'amount' => 10000,
            'currency_code' => 'IDR',
            'status' => TransactionStatus::Pending,
        ]);

        $this->artisan('komerce:expire-unpaid-orders')->assertSuccessful();

        $this->assertSame(OrderStatus::New, $order->fresh()->status);
        Http::assertNothingSent();
    }
}
