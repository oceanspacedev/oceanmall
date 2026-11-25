<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Actions\Orders\MarkOrderPaid;
use App\Integrations\Komerce\Actions\Payment\MarkOrderPaidFromKomerce;
use App\Jobs\FulfillOrderShipment;
use App\Livewire\Shopper\Pages\OrderDetail;
use App\Models\OrderShipment;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Events\Orders\OrderPaid;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Payment\Enum\TransactionStatus;
use Shopper\Payment\Enum\TransactionType;
use Shopper\Payment\Models\PaymentTransaction;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Payment confirmation goes through Shopper's OrderPaid for every payment
 * path; notification and shipment fulfilment are OrderPaid listeners.
 */
final class OrderPaidFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_mark_order_paid_fires_order_paid_once_and_captures_the_transaction(): void
    {
        Event::fake([OrderPaid::class]);
        $order = $this->order();
        PaymentTransaction::query()->create([
            'order_id' => $order->id,
            'driver' => 'gateway',
            'type' => TransactionType::Initiate,
            'status' => TransactionStatus::Pending,
            'amount' => 120000,
            'currency_code' => 'IDR',
            'reference' => 'GW-1',
        ]);

        $action = resolve(MarkOrderPaid::class);

        $this->assertSame(MarkOrderPaid::HANDLED, $action->handle($order, 'gateway', 'GW-1', 120000));
        $this->assertSame(MarkOrderPaid::ALREADY_PROCESSED, $action->handle($order, 'gateway', 'GW-1', 120000));

        Event::assertDispatchedTimes(OrderPaid::class, 1);
        $order->refresh();
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame(OrderStatus::Processing, $order->status);
        $this->assertDatabaseHas((new PaymentTransaction)->getTable(), [
            'order_id' => $order->id,
            'driver' => 'gateway',
            'reference' => 'GW-1',
            'type' => TransactionType::Capture->value,
            'status' => TransactionStatus::Success->value,
        ]);
    }

    public function test_mark_order_paid_refuses_a_wrong_amount_and_closed_orders_without_firing(): void
    {
        Event::fake([OrderPaid::class]);
        $action = resolve(MarkOrderPaid::class);

        $order = $this->order();
        $this->assertSame(MarkOrderPaid::AMOUNT_MISMATCH, $action->handle($order, 'gateway', 'GW-2', 1000));
        $this->assertSame(PaymentStatus::Pending, $order->refresh()->payment_status);

        $cancelled = $this->order(['status' => OrderStatus::Cancelled]);
        $this->assertSame(MarkOrderPaid::CANCELLED_ORDER, $action->handle($cancelled, 'gateway', 'GW-3'));

        Event::assertNotDispatched(OrderPaid::class);
    }

    public function test_komerce_payment_confirmation_fires_order_paid_and_listeners_notify_and_fulfil(): void
    {
        $this->requireModules('Komerce');

        $this->komerceConfig();
        Bus::fake([FulfillOrderShipment::class]);
        $user = User::factory()->create();
        $order = $this->order(['customer_id' => $user->id]);
        $shipment = $this->shipment($order, 'komerce');
        PaymentTransaction::query()->create([
            'order_id' => $order->id,
            'driver' => 'komerce',
            'type' => TransactionType::Initiate,
            'status' => TransactionStatus::Pending,
            'amount' => 120000,
            'currency_code' => 'IDR',
            'reference' => 'KOMPAY-EVT-1',
        ]);
        Http::fake([
            'https://payment.example.test/user/api/v1/user/payment/status/KOMPAY-EVT-1' => Http::response([
                'success' => true,
                'data' => ['payment_id' => 'KOMPAY-EVT-1', 'status' => 'PAID', 'amount' => 120000],
            ]),
        ]);

        $paid = [];
        Event::listen(OrderPaid::class, static function (OrderPaid $event) use (&$paid): void {
            $paid[] = $event->order->id;
        });

        $this->assertSame('handled', resolve(MarkOrderPaidFromKomerce::class)->handle('KOMPAY-EVT-1'));

        $this->assertSame([$order->id], $paid, 'Shopper OrderPaid fired once for the gateway payment.');
        $this->assertSame(1, $user->notifications()->where('data->type', 'paid')->count());
        Bus::assertDispatchedTimes(FulfillOrderShipment::class, 1);
        Bus::assertDispatched(FulfillOrderShipment::class, fn (FulfillOrderShipment $job): bool => $job->orderShipmentId === $shipment->id);
    }

    public function test_order_paid_from_shoppers_cpanel_and_a_webhook_never_registers_a_shipment_twice(): void
    {
        $this->requireModules('Komerce');

        $this->komerceConfig();
        Http::fake();
        Bus::fake([FulfillOrderShipment::class]);
        $order = $this->order(['payment_status' => PaymentStatus::Paid]);
        $shipment = $this->shipment($order, 'komerce');

        event(new OrderPaid($order));
        Bus::assertDispatchedTimes(FulfillOrderShipment::class, 1);

        // The provider issued the AWB; a second OrderPaid (e.g. staff "mark paid"
        // after the webhook) finds nothing left to register.
        $shipment->forceFill(['awb' => 'JNE-1', 'tracking_number' => 'JNE-1'])->save();
        event(new OrderPaid($order));

        Bus::assertDispatchedTimes(FulfillOrderShipment::class, 1);
        $this->assertContains(ShouldBeUnique::class, class_implements(FulfillOrderShipment::class));
    }

    public function test_manual_carrier_shipments_stay_with_staff_when_a_provider_is_configured(): void
    {
        $this->komerceConfig();
        Http::fake();
        Bus::fake([FulfillOrderShipment::class]);
        $order = $this->order(['payment_status' => PaymentStatus::Paid]);
        $this->shipment($order, 'manual');

        event(new OrderPaid($order));

        Bus::assertNotDispatched(FulfillOrderShipment::class);
    }

    public function test_cash_order_keeps_shoppers_mark_paid_next_to_komerce_and_reaches_order_paid_listeners(): void
    {
        $this->komerceConfig();
        Http::fake();
        Bus::fake([FulfillOrderShipment::class]);
        $admin = $this->admin();
        $customer = User::factory()->create();
        $cod = PaymentMethod::factory()->create(['driver' => 'manual', 'is_enabled' => true, 'title' => 'COD']);
        $order = $this->order(['customer_id' => $customer->id, 'payment_method_id' => $cod->id]);
        $this->shipment($order, 'manual');

        Livewire::actingAs($admin)
            ->test(OrderDetail::class, ['order' => $order])
            ->assertActionHasLabel('markPaid', __('shopper::forms.actions.mark_paid'))
            ->callAction('markPaid');

        $this->assertSame(PaymentStatus::Paid, $order->refresh()->payment_status);
        $this->assertSame(1, $customer->notifications()->where('data->type', 'paid')->count());
        Bus::assertNotDispatched(FulfillOrderShipment::class);
        Http::assertNothingSent();
    }

    public function test_komerce_order_mark_paid_asks_the_provider_instead(): void
    {
        $this->requireModules('Komerce');

        $this->komerceConfig();
        Http::fake();
        $admin = $this->admin();
        $method = PaymentMethod::factory()->create(['driver' => 'komerce', 'is_enabled' => true]);
        $order = $this->order(['payment_method_id' => $method->id]);

        Livewire::actingAs($admin)
            ->test(OrderDetail::class, ['order' => $order])
            ->assertActionHasLabel('markPaid', 'Sinkronkan pembayaran Komerce');
    }

    private function komerceConfig(): void
    {
        config()->set('komerce.payment_api_key', 'test-payment-key');
        config()->set('komerce.payment_base_url', 'https://payment.example.test/user');
        config()->set('komerce.shipping_delivery_api_key', 'test-delivery-key');
        config()->set('komerce.rajaongkir.delivery_base_url', 'https://delivery.example.test');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function order(array $attributes = []): Order
    {
        return Order::factory()->create([
            'currency_code' => 'IDR',
            'price_amount' => 120000,
            'status' => OrderStatus::New,
            'payment_status' => PaymentStatus::Pending,
            ...$attributes,
        ]);
    }

    private function shipment(Order $order, string $driver): OrderShipment
    {
        return OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => Inventory::factory()->create()->id,
            'driver' => $driver,
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'cost' => 15000,
            'currency_code' => 'IDR',
            'status' => 'pending',
        ]);
    }

    private function admin(): User
    {
        $this->configureShopperCpanel();
        $admin = User::factory()->create();
        Role::query()->firstOrCreate(['name' => config('shopper.admin.roles.admin'), 'guard_name' => 'web']);
        $admin->assignRole(config('shopper.admin.roles.admin'));

        return $admin;
    }
}
