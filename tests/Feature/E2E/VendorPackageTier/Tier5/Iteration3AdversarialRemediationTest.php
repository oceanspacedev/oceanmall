<?php

declare(strict_types=1);

namespace Tests\Feature\E2E\VendorPackageTier\Tier5;

use App\Actions\Orders\CancelOrderWithProviders;
use App\Actions\Shipping\NormalizeShipmentStatus;
use App\Actions\Stock\ReleaseOrderShipmentStock;
use App\Actions\Warehouse\OverrideAllocation;
use App\Integrations\Komerce\Actions\Payment\CancelUnpaidKomerceOrder;
use App\Livewire\Shopper\OrderFulfillment;
use App\Livewire\Shopper\OrderSummary;
use App\Livewire\Shopper\Pages\OrderShipments;
use App\Livewire\Shopper\SlideOvers\CreateShippingLabel;
use App\Models\OrderShipment;
use App\Shipping\AddressDestination;
use App\Shipping\ShippingCapabilities;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Enum\ShipmentStatus;
use Shopper\Core\Models\OrderItem;
use Shopper\Core\Models\OrderShipping;
use Shopper\Payment\Enum\TransactionStatus;
use Tests\Feature\E2E\VendorPackageTier\Support\VendorPackageTierTestCase;

final class Iteration3AdversarialRemediationTest extends VendorPackageTierTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireModules('Komerce');
    }

    public function test_bug1a_cancel_and_release_ignore_cancelled_shipment_with_nonzero_qty_when_active_shipment_is_in_transit(): void
    {
        $this->applyTier('keyless');
        $customer = $this->fixtures->createVerifiedCustomer();
        $product = $this->fixtures->createStockedProduct(100000, 10, 500, $this->fixtures->inventory);

        ['order' => $order, 'shipment' => $cancelled] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::Processing,
                'payment_status' => PaymentStatus::Paid,
                'payment_method_id' => $this->fixtures->paymentMethods['manual']->id,
                'metadata' => [],
            ],
            [
                'driver' => 'manual',
                'status' => NormalizeShipmentStatus::CANCELLED,
                'awb' => 'CANC-NONZERO-1',
                'tracking_number' => 'CANC-NONZERO-1',
            ],
            null,
            $product,
        );

        // Keep realistic non-zero qty (2) on the cancelled line and simulate reservation (10 -> 8).
        $cancelled->lines()->update(['qty' => 2]);
        $product->decreaseStock($this->fixtures->inventory->id, 2);

        $inTransit = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $this->fixtures->inventory->id,
            'driver' => 'manual',
            'carrier_code' => 'manual',
            'service_code' => 'Reguler',
            'cost' => 15000,
            'currency_code' => 'IDR',
            'status' => NormalizeShipmentStatus::IN_TRANSIT,
            'awb' => 'ACTIVE-INTRANSIT-1',
            'tracking_number' => 'ACTIVE-INTRANSIT-1',
        ]);
        $inTransit->lines()->create([
            'purchasable_type' => $product->getMorphClass(),
            'purchasable_id' => $product->id,
            'qty' => 2,
        ]);

        $cancel = resolve(CancelOrderWithProviders::class);
        $this->assertTrue($cancel->hasShipped($order->fresh()));

        $cancel->handle($order->fresh());

        $this->assertSame(8, (int) $product->fresh()->stockInventory($this->fixtures->inventory->id));
        $this->assertSame(NormalizeShipmentStatus::IN_TRANSIT, $inTransit->fresh()->status);

        // Direct full-order release call must also refuse to release cancelled or AWB-bearing in-transit stock.
        resolve(ReleaseOrderShipmentStock::class)->handle($order->fresh());
        $this->assertSame(8, (int) $product->fresh()->stockInventory($this->fixtures->inventory->id));
    }

    public function test_bug1b_cancel_and_release_do_not_double_release_cancelled_plus_pending_shipments(): void
    {
        $this->applyTier('keyless');
        $customer = $this->fixtures->createVerifiedCustomer();
        $product = $this->fixtures->createStockedProduct(100000, 10, 500, $this->fixtures->inventory);

        ['order' => $order, 'shipment' => $cancelled] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'payment_method_id' => $this->fixtures->paymentMethods['manual']->id,
                'metadata' => [],
            ],
            [
                'driver' => 'manual',
                'status' => NormalizeShipmentStatus::CANCELLED,
                'awb' => 'CANC-OLD-2',
                'tracking_number' => 'CANC-OLD-2',
            ],
            null,
            $product,
        );

        // Realistic non-zero qty on cancelled shipment + replacement pending shipment (10 -> 8).
        $cancelled->lines()->update(['qty' => 2]);
        $product->decreaseStock($this->fixtures->inventory->id, 2);

        $pending = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $this->fixtures->inventory->id,
            'driver' => 'manual',
            'carrier_code' => 'manual',
            'service_code' => 'Reguler',
            'cost' => 15000,
            'currency_code' => 'IDR',
            'status' => NormalizeShipmentStatus::PENDING,
            'awb' => null,
            'tracking_number' => null,
        ]);
        $pending->lines()->create([
            'purchasable_type' => $product->getMorphClass(),
            'purchasable_id' => $product->id,
            'qty' => 2,
        ]);

        $cancel = resolve(CancelOrderWithProviders::class);
        $this->assertFalse($cancel->hasShipped($order->fresh()));

        $cancel->handle($order->fresh());

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(NormalizeShipmentStatus::CANCELLED, $pending->fresh()->status);
        $this->assertSame(10, (int) $product->fresh()->stockInventory($this->fixtures->inventory->id));

        // Second release call is a no-op and direct full release on a separate cancelled+pending order also restores once.
        resolve(ReleaseOrderShipmentStock::class)->handle($order->fresh());
        $this->assertSame(10, (int) $product->fresh()->stockInventory($this->fixtures->inventory->id));

        $product2 = $this->fixtures->createStockedProduct(100000, 10, 500, $this->fixtures->inventory);
        ['order' => $order2, 'shipment' => $cancelled2] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'payment_method_id' => $this->fixtures->paymentMethods['manual']->id,
                'metadata' => [],
            ],
            [
                'driver' => 'manual',
                'status' => NormalizeShipmentStatus::CANCELLED,
            ],
            null,
            $product2,
        );
        $cancelled2->lines()->update(['qty' => 2]);
        $product2->decreaseStock($this->fixtures->inventory->id, 2);

        $pending2 = OrderShipment::query()->create([
            'order_id' => $order2->id,
            'inventory_id' => $this->fixtures->inventory->id,
            'driver' => 'manual',
            'carrier_code' => 'manual',
            'service_code' => 'Reguler',
            'cost' => 15000,
            'currency_code' => 'IDR',
            'status' => NormalizeShipmentStatus::PENDING,
        ]);
        $pending2->lines()->create([
            'purchasable_type' => $product2->getMorphClass(),
            'purchasable_id' => $product2->id,
            'qty' => 2,
        ]);

        resolve(ReleaseOrderShipmentStock::class)->handle($order2->fresh());
        $this->assertSame(10, (int) $product2->fresh()->stockInventory($this->fixtures->inventory->id));

        // 3. Cancelled-only order whose stock was never released yet restores stock once when order is cancelled.
        $product3 = $this->fixtures->createStockedProduct(100000, 10, 500, $this->fixtures->inventory);
        ['order' => $order3, 'shipment' => $cancelled3] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'payment_method_id' => $this->fixtures->paymentMethods['manual']->id,
                'metadata' => [],
            ],
            [
                'driver' => 'manual',
                'status' => NormalizeShipmentStatus::CANCELLED,
            ],
            null,
            $product3,
        );
        $cancelled3->lines()->update(['qty' => 2]);
        $product3->decreaseStock($this->fixtures->inventory->id, 2);

        $this->assertFalse($cancel->hasShipped($order3->fresh()));
        $cancel->handle($order3->fresh());
        $this->assertSame(10, (int) $product3->fresh()->stockInventory($this->fixtures->inventory->id));
    }

    public function test_bug2_cancelled_only_paid_komship_order_falls_back_to_manual_fulfillment_recovery_in_cpanel(): void
    {
        $this->applyTier('komship');
        $admin = $this->fixtures->createAdmin();
        $customer = $this->fixtures->createVerifiedCustomer();

        ['order' => $order] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::Processing,
                'payment_status' => PaymentStatus::Paid,
            ],
            [
                'driver' => 'komerce',
                'status' => NormalizeShipmentStatus::CANCELLED,
                'awb' => null,
                'external_reference' => 'RO-ALL-CANC-1',
            ],
        );

        $this->assertNull(resolve(ShippingCapabilities::class)->fulfillerForOrder($order->fresh()));

        $fulfillment = Livewire::actingAs($admin)
            ->test(OrderFulfillment::class, ['order' => $order->fresh()]);

        $this->assertTrue($fulfillment->instance()->hasUnfulfilledItems());
        $fulfillment
            ->assertSee('Semua pengiriman pada pesanan ini sudah dibatalkan.', false)
            ->assertDontSee('Terbitkan Resi Komerce', false)
            ->assertSee(__('shopper::pages/orders.create_shipping_label'), false);

        $labelSlideOver = Livewire::actingAs($admin)
            ->test(CreateShippingLabel::class, ['order' => $order->fresh()]);
        $this->assertNotNull($labelSlideOver->get('data.carrier_id'));

        $labelSlideOver
            ->set('data.tracking_number', 'MANUAL-RECOVERY-1')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas((new OrderShipping)->getTable(), [
            'order_id' => $order->id,
            'tracking_number' => 'MANUAL-RECOVERY-1',
        ]);
    }

    public function test_bug3_expire_unpaid_orders_and_cancel_unpaid_komerce_order_close_local_shipments(): void
    {
        $this->applyTier('payment_and_qrisly')
            ->withPaymentStatus('KOMPAY-EXP-1', 'PENDING', 112000)
            ->withPaymentCancel(200)
            ->withQrislyStatus('QRIS-EXP-2', 'unpaid', 112000)
            ->apply();

        $customer = $this->fixtures->createVerifiedCustomer();
        $product1 = $this->fixtures->createStockedProduct(100000, 10, 500, $this->fixtures->inventory);
        $product2 = $this->fixtures->createStockedProduct(100000, 10, 500, $this->fixtures->inventory);

        // 1. Expired Payment API order with local manual/starter shipment via orders:expire-unpaid
        ['order' => $expiredCmdOrder, 'shipment' => $cmdShipment, 'transaction' => $cmdTx] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'metadata' => [
                    'komerce' => [
                        'payment_type' => 'bank_transfer',
                        'provider' => 'payment_api',
                        'payment_ref' => 'KOMPAY-EXP-1',
                        'expiry_date' => now()->subMinute()->toDateTimeString(),
                    ],
                ],
            ],
            [
                'driver' => 'rajaongkir',
                'status' => NormalizeShipmentStatus::PENDING,
                'external_reference' => null,
            ],
            [
                'reference' => 'KOMPAY-EXP-1',
                'metadata' => ['expiry_date' => now()->subMinute()->toDateTimeString()],
            ],
            $product1,
        );
        $product1->decreaseStock($this->fixtures->inventory->id, 1);

        $this->artisan('orders:expire-unpaid')->assertSuccessful();

        $this->assertSame(OrderStatus::Cancelled, $expiredCmdOrder->fresh()->status);
        $this->assertSame(PaymentStatus::Voided, $expiredCmdOrder->fresh()->payment_status);
        $this->assertSame(TransactionStatus::Failed, $cmdTx->fresh()->status);
        $this->assertSame(NormalizeShipmentStatus::CANCELLED, $cmdShipment->fresh()->status);
        $this->assertSame(10, (int) $product1->fresh()->stockInventory($this->fixtures->inventory->id));

        // 2. Direct CancelUnpaidKomerceOrder invocation with local manual shipment
        ['order' => $directOrder, 'shipment' => $directShipment, 'transaction' => $directTx] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'payment_method_id' => $this->fixtures->paymentMethods['qris']->id,
                'metadata' => [
                    'komerce' => [
                        'payment_type' => 'qris',
                        'provider' => 'qrisly',
                        'payment_ref' => 'QRIS-EXP-2',
                        'qrisly_history_id' => 'QRIS-EXP-2',
                    ],
                ],
            ],
            [
                'driver' => 'manual',
                'status' => NormalizeShipmentStatus::PENDING,
                'external_reference' => null,
            ],
            [
                'reference' => 'QRIS-EXP-2',
                'metadata' => ['komerce_provider' => 'qrisly', 'komerce_payment_type' => 'qris'],
            ],
            $product2,
        );
        $product2->decreaseStock($this->fixtures->inventory->id, 1);

        resolve(CancelUnpaidKomerceOrder::class)->handle($directOrder->fresh(), 'Expired direct');

        $this->assertSame(OrderStatus::Cancelled, $directOrder->fresh()->status);
        $this->assertSame(PaymentStatus::Voided, $directOrder->fresh()->payment_status);
        $this->assertSame(TransactionStatus::Failed, $directTx->fresh()->status);
        $this->assertSame(NormalizeShipmentStatus::CANCELLED, $directShipment->fresh()->status);
        $this->assertSame(10, (int) $product2->fresh()->stockInventory($this->fixtures->inventory->id));
    }

    public function test_bug4_web_and_api_address_update_clear_destination_and_pin_point_without_region_change(): void
    {
        $this->applyTier('starter');
        $customer = $this->fixtures->createVerifiedCustomer();

        $address = $this->fixtures->createSavedAddress($customer);

        // 1. Web PATCH clearing destination_id, destination_label, pin_point with identical city/postal_code/state
        $this->actingAs($customer)
            ->patch(route('account.addresses.update', $address), [
                'first_name' => $address->first_name,
                'last_name' => $address->last_name,
                'street_address' => $address->street_address,
                'street_address_plus' => '',
                'postal_code' => $address->postal_code,
                'city' => $address->city,
                'state' => $address->state,
                'phone_number' => $address->phone_number,
                'country_id' => $address->country_id,
                'type' => $address->type->value,
                'destination_id' => '',
                'destination_label' => '',
                'pin_point' => '',
            ])
            ->assertRedirect(route('account.addresses'))
            ->assertSessionHasNoErrors();

        $address->refresh();
        $rawMeta = json_decode((string) $address->getRawOriginal('metadata'), true) ?? [];
        $this->assertArrayNotHasKey(AddressDestination::ID, $rawMeta);
        $this->assertArrayNotHasKey(AddressDestination::LABEL, $rawMeta);
        $this->assertArrayNotHasKey(AddressDestination::DRIVER, $rawMeta);
        $this->assertArrayNotHasKey(AddressDestination::PIN_POINT, $rawMeta);

        // 2. Partial Web PATCH clearing ONLY pin_point preserves destination_id / destination_label / destination_driver
        $address2 = $this->fixtures->createSavedAddress($customer);

        $this->actingAs($customer)
            ->patch(route('account.addresses.update', $address2), [
                'first_name' => $address2->first_name,
                'last_name' => $address2->last_name,
                'street_address' => 'Jl. Kebon Sirih 9',
                'postal_code' => $address2->postal_code,
                'city' => $address2->city,
                'state' => $address2->state,
                'phone_number' => $address2->phone_number,
                'country_id' => $address2->country_id,
                'type' => $address2->type->value,
                'pin_point' => '',
            ])
            ->assertRedirect(route('account.addresses'))
            ->assertSessionHasNoErrors();

        $meta2 = json_decode((string) $address2->fresh()->getRawOriginal('metadata'), true) ?? [];
        $this->assertSame('17601', $meta2[AddressDestination::ID] ?? null);
        $this->assertSame('rajaongkir', $meta2[AddressDestination::DRIVER] ?? null);
        $this->assertArrayNotHasKey(AddressDestination::PIN_POINT, $meta2);

        // 3. API PATCH clearing destination_id, destination_label, pin_point without region change
        $address3 = $this->fixtures->createSavedAddress($customer);
        $this->apiUpdateAddress($customer, $address3->id, [
            'first_name' => $address3->first_name,
            'last_name' => $address3->last_name,
            'street_address' => $address3->street_address,
            'postal_code' => $address3->postal_code,
            'city' => $address3->city,
            'state' => $address3->state,
            'phone_number' => $address3->phone_number,
            'country_id' => $address3->country_id,
            'type' => $address3->type->value,
            'destination_id' => '',
            'destination_label' => '',
            'pin_point' => '',
        ])->assertOk();

        $meta3 = json_decode((string) $address3->fresh()->getRawOriginal('metadata'), true) ?? [];
        $this->assertArrayNotHasKey(AddressDestination::ID, $meta3);
        $this->assertArrayNotHasKey(AddressDestination::PIN_POINT, $meta3);
    }

    public function test_bug5_override_allocation_ignores_historical_cancelled_shipments_and_their_costs(): void
    {
        $this->applyTier('keyless');
        $admin = $this->fixtures->createAdmin();
        $customer = $this->fixtures->createVerifiedCustomer();
        $invA = $this->fixtures->inventory;
        $invB = $this->fixtures->ensureSecondInventory();

        $product = $this->fixtures->createStockedProduct(100000, 10, 500, $invA);
        $product->mutateStock($invB->id, 10);

        ['order' => $order, 'shipment' => $activeA] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::Processing,
                'payment_status' => PaymentStatus::Paid,
                'price_amount' => 115000,
                'shipping_option_id' => $this->fixtures->manualOption?->id,
                'metadata' => ['shipping_address' => [
                    'country_id' => $this->fixtures->country->id,
                    'city' => 'Jakarta Pusat',
                    'postal_code' => '10110',
                ]],
            ],
            [
                'driver' => 'manual',
                'carrier_id' => $this->fixtures->carriers['manual']->id,
                'carrier_code' => 'manual',
                'service_code' => (string) $this->fixtures->manualOption?->id,
                'cost' => 15000,
                'status' => NormalizeShipmentStatus::PENDING,
            ],
            null,
            $product,
            $invA,
        );
        $product->decreaseStock($invA->id, 1);

        // Historical cancelled shipment at destination warehouse B with cost=27000
        $historicalCancelledB = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $invB->id,
            'driver' => 'manual',
            'carrier_id' => $this->fixtures->carriers['manual']->id,
            'carrier_code' => 'manual',
            'service_code' => (string) $this->fixtures->manualOption?->id,
            'cost' => 27000,
            'currency_code' => 'IDR',
            'status' => NormalizeShipmentStatus::CANCELLED,
            'awb' => 'OLD-CANC-B',
        ]);
        $historicalCancelledB->lines()->create([
            'purchasable_type' => $product->getMorphClass(),
            'purchasable_id' => $product->id,
            'qty' => 1,
        ]);

        $lineId = $activeA->lines()->sole()->id;

        Http::fake();

        $plan = resolve(OverrideAllocation::class)->handle(
            $order->fresh(),
            [[
                'shipment_line_id' => $lineId,
                'qty' => 1,
                'from_inventory_id' => $invA->id,
                'to_inventory_id' => $invB->id,
            ]],
            $admin,
            'Move to warehouse B',
        );

        // Historical cancelled shipment at B must remain cancelled & untouched; a fresh active shipment at B is created.
        $this->assertSame(NormalizeShipmentStatus::CANCELLED, $historicalCancelledB->fresh()->status);

        $activeB = OrderShipment::query()
            ->where('order_id', $order->id)
            ->where('inventory_id', $invB->id)
            ->where('status', '!=', NormalizeShipmentStatus::CANCELLED)
            ->sole();
        $this->assertSame(NormalizeShipmentStatus::PENDING, $activeB->status);
        $this->assertSame(15000, (int) $activeB->cost);

        // AllocationPlan and shipping variance must exclude historical cancelled shipment cost (27000).
        $this->assertCount(1, $plan->shipments);
        $this->assertSame($invB->id, $plan->shipments[0]->inventory_id);
        $meta = json_decode((string) $order->fresh()->getRawOriginal('metadata'), true) ?? [];
        $this->assertArrayNotHasKey('allocation_shipping_variance', $meta);
    }

    public function test_bug6_order_summary_excludes_cancelled_shipment_cost_when_active_shipments_exist(): void
    {
        $this->applyTier('keyless');
        $admin = $this->fixtures->createAdmin();
        $customer = $this->fixtures->createVerifiedCustomer();

        ['order' => $order] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::Processing,
                'payment_status' => PaymentStatus::Paid,
                'shipping_option_id' => null,
                'price_amount' => 115000,
            ],
            [
                'driver' => 'manual',
                'status' => NormalizeShipmentStatus::CANCELLED,
                'cost' => 27000,
            ],
            null,
        );

        OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $this->fixtures->inventory->id,
            'driver' => 'manual',
            'carrier_code' => 'manual',
            'service_code' => 'Reguler',
            'cost' => 15000,
            'currency_code' => 'IDR',
            'status' => NormalizeShipmentStatus::PENDING,
        ]);

        Livewire::actingAs($admin)
            ->test(OrderSummary::class, ['order' => $order->fresh()])
            ->assertViewHas('shippingPrice', 15000.0)
            ->assertSee('15.000', false)
            ->assertDontSee('27.000', false)
            ->assertDontSee('42.000', false);
    }

    public function test_order_shipments_hides_mark_delivered_and_edit_for_cancelled_manual_shipment_without_active_sibling(): void
    {
        $this->applyTier('keyless');
        $admin = $this->fixtures->createAdmin();
        $customer = $this->fixtures->createVerifiedCustomer();

        ['order' => $order] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::Processing,
                'payment_status' => PaymentStatus::Paid,
            ],
            [
                'driver' => 'manual',
                'carrier_id' => $this->fixtures->carriers['manual']->id,
                'carrier_code' => 'manual',
                'status' => NormalizeShipmentStatus::CANCELLED,
                'awb' => 'MAN-CANC-ONLY-1',
                'tracking_number' => 'MAN-CANC-ONLY-1',
            ],
            null,
        );

        $cancelledShipping = OrderShipping::query()->create([
            'order_id' => $order->id,
            'carrier_id' => $this->fixtures->carriers['manual']->id,
            'tracking_number' => 'MAN-CANC-ONLY-1',
            'status' => ShipmentStatus::OutForDelivery,
        ]);

        $cancelledBlankShipping = OrderShipping::query()->create([
            'order_id' => $order->id,
            'carrier_id' => $this->fixtures->carriers['manual']->id,
            'tracking_number' => null,
            'status' => ShipmentStatus::OutForDelivery,
        ]);

        // Recovery OrderShipping created for the same order with bound item and fresh AWB remains actionable.
        $recoveryShipping = OrderShipping::query()->create([
            'order_id' => $order->id,
            'carrier_id' => $this->fixtures->carriers['manual']->id,
            'tracking_number' => 'MAN-REC-ONLY-2',
            'status' => ShipmentStatus::OutForDelivery,
        ]);
        OrderItem::query()->where('order_id', $order->id)->update(['order_shipping_id' => $recoveryShipping->id]);

        Livewire::actingAs($admin)
            ->test(OrderShipments::class)
            ->assertTableActionHidden('markDelivered', $cancelledShipping)
            ->assertTableActionHidden('edit', $cancelledShipping)
            ->assertTableActionHidden('markDelivered', $cancelledBlankShipping)
            ->assertTableActionHidden('edit', $cancelledBlankShipping)
            ->assertTableActionVisible('markDelivered', $recoveryShipping)
            ->assertTableActionVisible('edit', $recoveryShipping);
    }
}
