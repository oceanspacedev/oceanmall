<?php

declare(strict_types=1);

namespace Tests\Feature\E2E\VendorPackageTier\Tier5;

use App\Actions\Account\ConfirmOrderReceived;
use App\Actions\Shipping\NormalizeShipmentStatus;
use App\Actions\Shipping\ReshipOrderShipment;
use App\Actions\Shipping\SyncOrderShippingFromShipments;
use App\Models\OrderShipment;
use App\Support\OrderShipmentOpsPresenter;
use Inertia\Testing\AssertableInertia as Assert;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Enum\ShipmentStatus;
use Shopper\Core\Enum\ShippingStatus;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\OrderItem;
use Shopper\Core\Models\OrderShipping;
use Tests\Feature\E2E\VendorPackageTier\Support\VendorPackageTierTestCase;

final class Iteration9AdversarialRemediationTest extends VendorPackageTierTestCase
{
    public function test_bug_a_split_manual_create_shipping_label_targets_s1_even_when_carrier_swapped_to_sibling_s2(): void
    {
        $this->applyTier('keyless');
        $admin = $this->fixtures->createAdmin();
        $customer = $this->fixtures->createVerifiedCustomer();
        $secondInventory = $this->fixtures->ensureSecondInventory();

        $carrier1 = Carrier::query()->create([
            'name' => 'Kurir Manual Satu',
            'slug' => 'kurir-manual-satu',
            'driver' => 'manual',
            'is_enabled' => true,
        ]);
        $carrier2 = Carrier::query()->create([
            'name' => 'Kurir Manual Dua',
            'slug' => 'kurir-manual-dua',
            'driver' => 'manual',
            'is_enabled' => true,
        ]);

        $product1 = $this->fixtures->createStockedProduct(100000, 10, 500, $this->fixtures->inventory);
        $product2 = $this->fixtures->createStockedProduct(120000, 10, 500, $secondInventory);

        ['order' => $order, 'shipment' => $s1] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'number' => 'ORD-SPLIT-SWAP-1',
                'status' => OrderStatus::Processing,
                'payment_status' => PaymentStatus::Paid,
                'shipping_status' => ShippingStatus::Unfulfilled,
                'shipping_option_id' => null,
                'price_amount' => 250000,
            ],
            [
                'driver' => 'manual',
                'carrier_id' => $carrier1->id,
                'carrier_code' => 'manual',
                'carrier_name' => 'Kurir Manual Satu',
                'service_code' => 'Reguler',
                'service_name' => 'Reguler',
                'status' => NormalizeShipmentStatus::PENDING,
                'awb' => null,
                'tracking_number' => null,
                'cost' => 15000,
                'metadata' => [],
            ],
            null,
            $product1,
            $this->fixtures->inventory,
        );

        OrderItem::query()->create([
            'order_id' => $order->id,
            'name' => $product2->name,
            'sku' => $product2->sku ?? 'SKU-SWAP-2',
            'product_type' => $product2->getMorphClass(),
            'product_id' => $product2->id,
            'quantity' => 1,
            'unit_price_amount' => 120000,
        ]);

        $s2 = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $secondInventory->id,
            'driver' => 'manual',
            'carrier_id' => $carrier2->id,
            'carrier_code' => 'manual',
            'carrier_name' => 'Kurir Manual Dua',
            'service_code' => 'Reguler',
            'service_name' => 'Reguler',
            'status' => NormalizeShipmentStatus::PENDING,
            'awb' => null,
            'tracking_number' => null,
            'cost' => 15000,
            'currency_code' => 'IDR',
            'metadata' => [],
        ]);
        $s2->lines()->create([
            'purchasable_type' => $product2->getMorphClass(),
            'purchasable_id' => $product2->id,
            'qty' => 1,
        ]);

        // Staff opens CreateShippingLabel targeting s1 (c1), but swaps carrier to c2 (which matches sibling s2).
        $slideOver1 = $this->cpanelCreateShippingLabel($admin, $order->fresh());
        $this->assertSame($s1->id, $slideOver1->instance()->targetShipmentId);

        $slideOver1
            ->set('data.carrier_id', $carrier2->id)
            ->set('data.tracking_number', 'AWB-FOR-S1')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('AWB-FOR-S1', $s1->refresh()->awb);
        $this->assertSame('AWB-FOR-S1', $s1->tracking_number);
        $this->assertSame(NormalizeShipmentStatus::LABELED, $s1->status);
        $this->assertNull($s2->refresh()->awb);
        $this->assertNull($s2->tracking_number);
        $this->assertSame(NormalizeShipmentStatus::PENDING, $s2->status);

        // Confirm receipt stays blocked until s2 is also labelled.
        $this->apiConfirmReceived($customer, $order->number)->assertStatus(422);

        // Next CreateShippingLabel cleanly targets s2 and completes the split order.
        $slideOver2 = $this->cpanelCreateShippingLabel($admin, $order->fresh());
        $this->assertSame($s2->id, $slideOver2->instance()->targetShipmentId);

        $slideOver2
            ->set('data.carrier_id', $carrier2->id)
            ->set('data.tracking_number', 'AWB-FOR-S2')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('AWB-FOR-S1', $s1->refresh()->awb);
        $this->assertSame('AWB-FOR-S2', $s2->refresh()->awb);
        $this->assertSame(NormalizeShipmentStatus::LABELED, $s2->status);

        $this->apiConfirmReceived($customer, $order->number)->assertOk();
        $this->assertSame(NormalizeShipmentStatus::DELIVERED, $s1->refresh()->status);
        $this->assertSame(NormalizeShipmentStatus::DELIVERED, $s2->refresh()->status);
        $this->assertSame(OrderStatus::Completed, $order->refresh()->status);

        // Direct OrderShipping saves outside CreateShippingLabel remain carrier-aware on ambiguous splits.
        ['order' => $directOrder, 'shipment' => $directS1] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'number' => 'ORD-DIRECT-CARRIER-1',
                'status' => OrderStatus::Processing,
                'payment_status' => PaymentStatus::Paid,
                'shipping_status' => ShippingStatus::Unfulfilled,
                'shipping_option_id' => null,
                'price_amount' => 130000,
            ],
            [
                'driver' => 'manual',
                'carrier_id' => $carrier1->id,
                'carrier_code' => 'manual',
                'status' => NormalizeShipmentStatus::PENDING,
                'awb' => null,
                'tracking_number' => null,
                'cost' => 15000,
                'metadata' => [],
            ],
            null,
            $product1,
            $this->fixtures->inventory,
        );
        $directS2 = OrderShipment::query()->create([
            'order_id' => $directOrder->id,
            'inventory_id' => $secondInventory->id,
            'driver' => 'manual',
            'carrier_id' => $carrier2->id,
            'carrier_code' => 'manual',
            'status' => NormalizeShipmentStatus::PENDING,
            'awb' => null,
            'tracking_number' => null,
            'cost' => 15000,
            'currency_code' => 'IDR',
            'metadata' => [],
        ]);

        OrderShipping::query()->create([
            'order_id' => $directOrder->id,
            'carrier_id' => $carrier2->id,
            'tracking_number' => 'DIRECT-C2-AWB',
            'status' => ShipmentStatus::Pending,
        ]);

        $this->assertNull($directS1->refresh()->awb);
        $this->assertSame('DIRECT-C2-AWB', $directS2->refresh()->awb);
    }

    public function test_bug_b_superseded_reshipped_returned_and_failed_shipments_flag_is_superseded_and_unblock_confirm_and_cpanel_complete(): void
    {
        $this->requireModules('Komerce');
        $this->applyTier('komship');

        $admin = $this->fixtures->createAdmin();
        $customer = $this->fixtures->createVerifiedCustomer();

        foreach ([
            ['status' => NormalizeShipmentStatus::RETURNED, 'suffix' => 'RET'],
            ['status' => NormalizeShipmentStatus::DELIVERY_FAILED, 'suffix' => 'FAIL'],
        ] as $case) {
            ['order' => $order, 'shipment' => $s1] = $this->fixtures->createOrderWithShipment(
                $customer,
                [
                    'number' => 'ORD-RESHIP-B-'.$case['suffix'],
                    'status' => OrderStatus::Processing,
                    'payment_status' => PaymentStatus::Paid,
                    'shipping_status' => ShippingStatus::Shipped,
                    'shipping_option_id' => null,
                    'price_amount' => 112000,
                ],
                [
                    'driver' => 'komerce',
                    'carrier_id' => $this->fixtures->carriers['jne']->id,
                    'carrier_code' => 'jne',
                    'carrier_name' => 'JNE Express',
                    'service_code' => 'REG',
                    'service_name' => 'REG',
                    'status' => $case['status'],
                    'awb' => 'OLD-'.$case['suffix'].'-1',
                    'tracking_number' => 'OLD-'.$case['suffix'].'-1',
                    'external_reference' => 'RO-OLD-'.$case['suffix'],
                    'cost' => 12000,
                ],
            );

            $s2 = resolve(ReshipOrderShipment::class)->handle($s1->fresh(), $admin);
            $s2->forceFill([
                'awb' => 'NEW-'.$case['suffix'].'-2',
                'tracking_number' => 'NEW-'.$case['suffix'].'-2',
                'external_reference' => 'RO-NEW-'.$case['suffix'],
                'status' => NormalizeShipmentStatus::IN_TRANSIT,
            ])->save();

            resolve(SyncOrderShippingFromShipments::class)->handle($order->fresh());

            // 1. Web Inertia show() keeps both shipments in history and exposes is_superseded accurately.
            $this->withoutVite();
            $this->actingAs($customer)
                ->get(route('account.orders.show', $order))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->has('shipments', 2)
                    ->where('shipments.0.id', $s1->id)
                    ->where('shipments.0.status', $case['status'])
                    ->where('shipments.0.is_superseded', true)
                    ->where('shipments.1.id', $s2->id)
                    ->where('shipments.1.status', NormalizeShipmentStatus::IN_TRANSIT)
                    ->where('shipments.1.is_superseded', false)
                    ->where('summary.shipping', 12000));

            // 2. Mobile API v1 show() keeps both shipments in history and exposes is_superseded accurately.
            $this->apiShowOrder($customer, $order->number)
                ->assertOk()
                ->assertJsonCount(2, 'data.shipments')
                ->assertJsonPath('data.shipments.0.id', $s1->id)
                ->assertJsonPath('data.shipments.0.status', $case['status'])
                ->assertJsonPath('data.shipments.0.is_superseded', true)
                ->assertJsonPath('data.shipments.1.id', $s2->id)
                ->assertJsonPath('data.shipments.1.status', NormalizeShipmentStatus::IN_TRANSIT)
                ->assertJsonPath('data.shipments.1.is_superseded', false)
                ->assertJsonPath('data.breakdown.shipping', 12000);

            // 3. Cpanel OrderDetail::allShipmentsDelivered() stays hidden while s2 is in_transit,
            //    and becomes visible + completable once s2 is delivered (even though s1 stays returned/delivery_failed).
            $this->cpanelOrderDetail($admin, $order->fresh())
                ->assertActionHidden('markComplete');

            // Verify ConfirmOrderReceived succeeds on a clone/fresh shipped order before s2 is delivered.
            $confirmed = resolve(ConfirmOrderReceived::class)->handle($order->fresh());
            $this->assertSame(ShippingStatus::Delivered, $confirmed->shipping_status);
            $this->assertSame(OrderStatus::Completed, $confirmed->status);
            $this->assertSame(NormalizeShipmentStatus::DELIVERED, $s2->refresh()->status);
            $this->assertSame($case['status'], $s1->refresh()->status);

            // Reset order to Processing + s2 delivered (provider courier delivered webhook path) to assert Cpanel markCompleteAction.
            $order->refresh()->forceFill([
                'status' => OrderStatus::Processing,
                'shipping_status' => ShippingStatus::Delivered,
            ])->save();

            $this->cpanelOrderDetail($admin, $order->fresh())
                ->assertActionVisible('markComplete')
                ->callAction('markComplete')
                ->assertNotified('Pesanan diselesaikan setelah kurir menandai terkirim.');

            $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
        }
    }

    public function test_bug_b_sibling_cod_allocation_gives_full_amount_to_replacement_shipment(): void
    {
        $this->applyTier('keyless');
        $admin = $this->fixtures->createAdmin();
        $customer = $this->fixtures->createVerifiedCustomer();

        ['order' => $order, 'shipment' => $s1] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'number' => 'ORD-RESHIP-COD-1',
                'status' => OrderStatus::Processing,
                'payment_status' => PaymentStatus::Pending,
                'shipping_status' => ShippingStatus::Returned,
                'payment_method_id' => $this->fixtures->paymentMethods['cod']->id,
                'shipping_option_id' => null,
                'price_amount' => 115000,
                'metadata' => [],
            ],
            [
                'driver' => 'manual',
                'carrier_id' => $this->fixtures->carriers['manual']->id,
                'carrier_code' => 'manual',
                'status' => NormalizeShipmentStatus::RETURNED,
                'awb' => 'COD-OLD-1',
                'tracking_number' => 'COD-OLD-1',
                'cost' => 15000,
                'metadata' => [],
            ],
            null,
        );

        $s2 = resolve(ReshipOrderShipment::class)->handle($s1->fresh(), $admin);

        $allocated = resolve(OrderShipmentOpsPresenter::class)->allocateCodAmounts($order->fresh());

        $this->assertSame(0, $allocated[$s1->id]);
        $this->assertSame(115000, $allocated[$s2->id]);
    }
}
