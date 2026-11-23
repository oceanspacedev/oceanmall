<?php

declare(strict_types=1);

namespace Tests\Feature\QaRegression;

use App\Actions\Orders\CancelOrderWithProviders;
use App\Actions\Shipping\NormalizeShipmentStatus;
use App\Actions\Stock\ReleaseOrderShipmentStock;
use App\Enums\OrderNotificationType;
use App\Livewire\Shopper\Pages\OrderDetail;
use App\Models\OrderShipment;
use App\Models\Product;
use App\Models\User;
use App\Payment\Contracts\ExpiresPayments;
use App\Payment\Contracts\ReconcilesPayments;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Enum\ShipmentStatus;
use Shopper\Core\Enum\ShippingStatus;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\OrderShipping;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Payment\DataTransferObjects\PaymentResult;
use Shopper\Payment\Drivers\Driver;
use Shopper\Payment\Facades\Payment;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Staff cancel / archive in /cpanel of orders no provider handles (manual /
 * COD payment, zone-option shipping) go through the same provider-neutral
 * cancel as provider orders, and the stock release happens exactly once
 * without wiping the order metadata.
 */
final class CpanelCancelReleaseStockTest extends TestCase
{
    use RefreshDatabase;

    private Inventory $inventory;

    private Product $product;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->inventory = Inventory::factory()->create();
        $this->product = Product::factory()->standard()->create();
        $this->product->mutateStock($this->inventory->id, 10);
        $this->customer = User::factory()->create();
    }

    public function test_cpanel_cancel_of_unpaid_cod_order_releases_stock_voids_payment_closes_shipment_and_notifies(): void
    {
        $order = $this->order('manual', PaymentStatus::Pending, OrderStatus::New);
        $this->assertSame(8, $this->stock());

        Livewire::actingAs($this->admin())
            ->test(OrderDetail::class, ['order' => $order])
            ->callAction('cancelOrder');

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(PaymentStatus::Voided, $order->payment_status);
        $this->assertSame(10, $this->stock(), 'the 2 reserved units go back to the warehouse');
        $this->assertSame(
            NormalizeShipmentStatus::CANCELLED,
            OrderShipment::query()->where('order_id', $order->id)->value('status'),
        );
        $this->assertSame(1, $this->cancelledNotifications($order));

        $metadata = json_decode((string) $order->getAttributes()['metadata'], true);
        $this->assertSame('QA', data_get($metadata, 'shipping_address.first_name'), 'address snapshot kept');
        $this->assertIsString(data_get($metadata, 'stock_released_at'));
    }

    public function test_cpanel_cancel_of_paid_manual_order_releases_stock_and_keeps_it_paid(): void
    {
        $order = $this->order('manual', PaymentStatus::Paid, OrderStatus::Processing);

        Livewire::actingAs($this->admin())
            ->test(OrderDetail::class, ['order' => $order])
            ->callAction('cancelOrder');

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame(10, $this->stock());
        $this->assertSame(1, $this->cancelledNotifications($order));
    }

    public function test_cpanel_cancel_of_paid_provider_payment_order_with_zone_carrier_releases_stock(): void
    {
        $order = $this->order('xendit', PaymentStatus::Paid, OrderStatus::Processing);

        Livewire::actingAs($this->admin())
            ->test(OrderDetail::class, ['order' => $order])
            ->callAction('cancelOrder');

        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);
        $this->assertSame(10, $this->stock());
    }

    public function test_cpanel_archive_of_unpaid_manual_order_releases_stock_once(): void
    {
        $order = $this->order('manual', PaymentStatus::Pending, OrderStatus::New);

        Livewire::actingAs($this->admin())
            ->test(OrderDetail::class, ['order' => $order])
            ->callAction('archive');

        $order->refresh();
        $this->assertSame(OrderStatus::Archived, $order->status);
        $this->assertNotNull($order->archived_at);
        $this->assertSame(PaymentStatus::Voided, $order->payment_status);
        $this->assertSame(10, $this->stock());
        $this->assertSame(1, $this->cancelledNotifications($order));
    }

    public function test_archive_of_an_already_cancelled_order_does_not_release_again(): void
    {
        $order = $this->order('manual', PaymentStatus::Pending, OrderStatus::New);
        resolve(CancelOrderWithProviders::class)->handle($order);
        $this->assertSame(10, $this->stock());

        Livewire::actingAs($this->admin())
            ->test(OrderDetail::class, ['order' => $order->refresh()])
            ->callAction('archive');

        $this->assertSame(OrderStatus::Archived, $order->refresh()->status);
        $this->assertSame(10, $this->stock());
        $this->assertSame(1, $this->cancelledNotifications($order));
    }

    public function test_cancel_keeps_awb_parcel_stock_and_shipment(): void
    {
        $order = $this->order('manual', PaymentStatus::Paid, OrderStatus::Processing);
        OrderShipment::query()->where('order_id', $order->id)->update(['awb' => 'JNE123', 'status' => 'in_transit']);

        resolve(CancelOrderWithProviders::class)->handle($order);

        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);
        $this->assertSame(8, $this->stock(), 'a parcel with an AWB already left the warehouse');
        $this->assertSame('in_transit', OrderShipment::query()->where('order_id', $order->id)->value('status'));
    }

    public function test_release_merges_into_order_metadata_instead_of_wiping_it(): void
    {
        $order = $this->order('manual', PaymentStatus::Pending, OrderStatus::New);

        resolve(ReleaseOrderShipmentStock::class)->handle($order->refresh());

        $metadata = json_decode((string) $order->refresh()->getAttributes()['metadata'], true);
        $this->assertSame(10, $this->stock());
        $this->assertSame('QA', data_get($metadata, 'shipping_address.first_name'));
        $this->assertSame('inv_123', data_get($metadata, 'xendit.invoice_id'));
        $this->assertSame('KP-1', data_get($metadata, 'komerce.payment_ref'));
        $this->assertIsString(data_get($metadata, 'stock_released_at'));
    }

    public function test_second_release_is_a_no_op(): void
    {
        $order = $this->order('manual', PaymentStatus::Pending, OrderStatus::New);

        resolve(ReleaseOrderShipmentStock::class)->handle($order->refresh());
        resolve(ReleaseOrderShipmentStock::class)->handle($order->refresh());

        $this->assertSame(10, $this->stock());
    }

    public function test_legacy_komerce_release_marker_is_honoured(): void
    {
        $order = $this->order('manual', PaymentStatus::Pending, OrderStatus::New, [
            'komerce' => ['stock_released_at' => '2026-11-01T10:00:00+07:00'],
        ]);

        resolve(ReleaseOrderShipmentStock::class)->handle($order->refresh());

        $this->assertSame(8, $this->stock());
    }

    public function test_release_after_a_cancel_already_released_is_a_no_op(): void
    {
        $order = $this->order('manual', PaymentStatus::Pending, OrderStatus::New);

        resolve(CancelOrderWithProviders::class)->handle($order, throughPaymentProvider: false);
        // e.g. a provider expiry path releasing again after the staff cancel.
        resolve(ReleaseOrderShipmentStock::class)->handle(Order::query()->findOrFail($order->id));

        $this->assertSame(10, $this->stock());
        $this->assertSame(1, $this->cancelledNotifications($order));
    }

    public function test_cancel_after_manual_label_without_tracking_picked_up_keeps_stock(): void
    {
        // "Kurir Toko" parcel handed over without a tracking number: nothing
        // is mirrored onto the warehouse shipment (no AWB, still pending).
        $order = $this->order('manual', PaymentStatus::Pending, OrderStatus::Processing);
        $this->label($order, ShipmentStatus::PickedUp);

        Livewire::actingAs($this->admin())
            ->test(OrderDetail::class, ['order' => $order])
            ->callAction('cancelOrder');

        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);
        $this->assertSame(8, $this->stock(), 'a parcel already with the courier must not go back to stock');
        $this->assertSame('pending', OrderShipment::query()->where('order_id', $order->id)->value('status'));
    }

    public function test_cancel_after_pending_label_with_unmirrored_tracking_keeps_stock(): void
    {
        $order = $this->order('manual', PaymentStatus::Paid, OrderStatus::Processing);
        $this->label($order, ShipmentStatus::Pending, 'KT-0001');
        // Mirroring is skipped when the target shipment is ambiguous.
        OrderShipment::query()->where('order_id', $order->id)->update(['awb' => null, 'tracking_number' => null]);

        resolve(CancelOrderWithProviders::class)->handle($order);

        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);
        $this->assertSame(8, $this->stock());
    }

    public function test_cancel_with_only_a_pending_label_without_tracking_still_releases_stock(): void
    {
        $order = $this->order('manual', PaymentStatus::Pending, OrderStatus::Processing);
        $this->label($order, ShipmentStatus::Pending);

        resolve(CancelOrderWithProviders::class)->handle($order);

        $this->assertSame(10, $this->stock(), 'a label alone does not mean the goods left');
        $this->assertSame(
            NormalizeShipmentStatus::CANCELLED,
            OrderShipment::query()->where('order_id', $order->id)->value('status'),
        );
    }

    public function test_archive_of_delivered_unpaid_cod_order_only_archives(): void
    {
        $order = $this->order('manual', PaymentStatus::Pending, OrderStatus::Processing);
        $this->label($order, ShipmentStatus::Delivered);
        $order->forceFill(['shipping_status' => ShippingStatus::Delivered])->save();

        Livewire::actingAs($this->admin())
            ->test(OrderDetail::class, ['order' => $order->refresh()])
            ->callAction('archive');

        $order->refresh();
        $this->assertSame(OrderStatus::Archived, $order->status);
        $this->assertSame(PaymentStatus::Pending, $order->payment_status, 'cash collected on delivery is not voided');
        $this->assertSame(8, $this->stock(), 'delivered goods must not go back to stock');
        $this->assertSame(0, $this->cancelledNotifications($order));
    }

    public function test_archive_of_in_transit_unpaid_cod_order_only_archives(): void
    {
        $order = $this->order('manual', PaymentStatus::Pending, OrderStatus::Processing);
        $this->label($order, ShipmentStatus::InTransit);

        Livewire::actingAs($this->admin())
            ->test(OrderDetail::class, ['order' => $order])
            ->callAction('archive');

        $order->refresh();
        $this->assertSame(OrderStatus::Archived, $order->status);
        $this->assertSame(PaymentStatus::Pending, $order->payment_status);
        $this->assertSame(8, $this->stock());
        $this->assertSame(0, $this->cancelledNotifications($order));
    }

    public function test_staff_cancel_through_an_expiring_payment_driver_closes_manual_shipments(): void
    {
        // A driver whose own cancel (like Komerce's) voids and releases stock
        // but leaves the zone-carrier shipment untouched.
        Payment::extend('expirepay', static fn (): ExpiringPayDriver => new ExpiringPayDriver);
        config()->set('shopper.payment.drivers.expirepay.enabled', true);
        $order = $this->order('expirepay', PaymentStatus::Pending, OrderStatus::New);

        Livewire::actingAs($this->admin())
            ->test(OrderDetail::class, ['order' => $order])
            ->callAction('cancelOrder');

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(PaymentStatus::Voided, $order->payment_status);
        $this->assertSame(
            NormalizeShipmentStatus::CANCELLED,
            OrderShipment::query()->where('order_id', $order->id)->value('status'),
        );
    }

    /**
     * Shopper's own "create shipping label" row (sh_order_shippings), as the
     * cpanel writes it for a manual carrier; the tracking number is optional.
     */
    private function label(Order $order, ShipmentStatus $status, ?string $tracking = null): OrderShipping
    {
        $carrier = Carrier::query()->firstOrCreate(
            ['slug' => 'kurir-toko'],
            ['name' => 'Kurir Toko', 'driver' => 'manual', 'is_enabled' => true],
        );

        return OrderShipping::query()->create([
            'order_id' => $order->id,
            'carrier_id' => $carrier->id,
            'tracking_number' => $tracking,
            'status' => $status,
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

    /**
     * A checkout-like order: 2 units reserved from the warehouse on a manual
     * (zone option) shipment.
     *
     * @param  array<string, mixed>  $extraMetadata
     */
    private function order(string $paymentDriver, PaymentStatus $payment, OrderStatus $status, array $extraMetadata = []): Order
    {
        $method = PaymentMethod::factory()->create(['driver' => $paymentDriver, 'is_enabled' => true]);

        $order = Order::factory()->create([
            'number' => 'ORD-QA-'.uniqid(),
            'customer_id' => $this->customer->id,
            'currency_code' => 'IDR',
            'price_amount' => 115000,
            'status' => $status,
            'payment_status' => $payment,
            'shipping_status' => 'unfulfilled',
            'payment_method_id' => $method->id,
            'metadata' => json_encode([
                'shipping_address' => ['first_name' => 'QA', 'last_name' => 'Cancel', 'city' => 'Bandung'],
                'xendit' => ['invoice_id' => 'inv_123', 'mode' => 'test'],
                'komerce' => ['payment_ref' => 'KP-1', ...($extraMetadata['komerce'] ?? [])],
            ], JSON_THROW_ON_ERROR),
        ]);

        $shipment = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $this->inventory->id,
            'driver' => 'manual',
            'carrier_code' => 'manual',
            'service_code' => '1',
            'cost' => 15000,
            'currency_code' => 'IDR',
            'status' => 'pending',
        ]);
        $shipment->lines()->create([
            'purchasable_type' => $this->product->getMorphClass(),
            'purchasable_id' => $this->product->id,
            'qty' => 2,
        ]);

        // Reserved at checkout.
        $this->product->decreaseStock($this->inventory->id, 2);

        return $order;
    }

    private function stock(): int
    {
        return (int) $this->product->fresh()->stockInventory($this->inventory->id);
    }

    private function cancelledNotifications(Order $order): int
    {
        return $this->customer->notifications()
            ->where('data->order_id', $order->id)
            ->where('data->type', OrderNotificationType::Cancelled->value)
            ->count();
    }
}

/**
 * A payment driver that expires unpaid orders itself and, like Komerce's
 * CancelUnpaidKomerceOrder, does not close the order's local shipments.
 */
final class ExpiringPayDriver extends Driver implements ExpiresPayments, ReconcilesPayments
{
    public function code(): string
    {
        return 'expirepay';
    }

    public function name(): string
    {
        return 'ExpirePay';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function initiatePayment(int $amount, string $currency, array $context = []): PaymentResult
    {
        return new PaymentResult(success: true, status: 'pending', reference: 'exp_1', amount: $amount);
    }

    public function paymentExpiresAt(Order $order): ?CarbonInterface
    {
        return null;
    }

    public function canExpireUnpaidOrder(Order $order): bool
    {
        return true;
    }

    public function expireUnpaidOrder(Order $order, string $reason = 'Payment expired'): Order
    {
        $order->forceFill([
            'status' => OrderStatus::Cancelled,
            'payment_status' => PaymentStatus::Voided,
        ])->save();

        resolve(ReleaseOrderShipmentStock::class)->handle($order->refresh());

        return $order->refresh();
    }

    public function reconcilePayment(Order $order): string
    {
        return self::PENDING;
    }
}
