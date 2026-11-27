<?php

declare(strict_types=1);

namespace Tests\Feature\E2E\VendorPackageTier\Tier5;

use App\Actions\Account\CancelCustomerOrder;
use App\Actions\Orders\CancelOrderWithProviders;
use App\Actions\Shipping\NormalizeShipmentStatus;
use App\Actions\Shipping\PrintShipmentLabels;
use App\Actions\Shipping\SyncOrderShippingFromShipments;
use App\Integrations\Komerce\Actions\Payment\MarkOrderPaidFromKomerce;
use App\Livewire\Shopper\Pages\OrderShipments;
use App\Livewire\Shopper\SlideOvers\ShipmentAddEvent;
use App\Models\OrderShipment;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use ReflectionMethod;
use RuntimeException;
use Shopper\Core\Enum\FulfillmentStatus;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Enum\ShipmentStatus;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\OrderItem;
use Shopper\Core\Models\OrderShipping;
use Shopper\Payment\Enum\TransactionStatus;
use Shopper\Payment\Enum\TransactionType;
use Shopper\Payment\Models\PaymentTransaction;
use Tests\Feature\E2E\VendorPackageTier\Support\KomerceHttpFakeBuilder;
use Tests\Feature\E2E\VendorPackageTier\Support\VendorPackageTierTestCase;

final class CancelledShipmentAndProviderRoutingRegressionTest extends VendorPackageTierTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireModules('Komerce');
    }

    public function test_bug1_create_shipping_label_mount_ignores_cancelled_shipment_awb_and_carrier(): void
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
                'carrier_id' => $this->fixtures->carriers['jne']->id,
                'carrier_code' => 'jne',
                'carrier_name' => 'JNE Express',
                'status' => NormalizeShipmentStatus::CANCELLED,
                'awb' => 'OLD-CANCELLED-AWB-999',
                'tracking_number' => 'OLD-CANCELLED-AWB-999',
                'external_reference' => 'RO-CANC-MOUNT-1',
            ],
        );

        OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $this->fixtures->inventory->id,
            'driver' => 'manual',
            'carrier_id' => null,
            'carrier_code' => 'manual',
            'carrier_name' => 'Kurir Toko',
            'service_code' => 'Reguler',
            'cost' => 15000,
            'currency_code' => 'IDR',
            'status' => NormalizeShipmentStatus::PENDING,
            'awb' => null,
            'tracking_number' => null,
        ]);

        $component = $this->cpanelCreateShippingLabel($admin, $order->fresh());

        $this->assertNull($component->get('data.tracking_number'));
        $this->assertNull($component->get('data.tracking_url'));
        $this->assertSame(
            (string) $this->fixtures->carriers['manual']->id,
            (string) $component->get('data.carrier_id'),
        );
    }

    public function test_bug1_create_shipping_label_mount_prefers_unlabelled_active_shipment_over_labelled_one(): void
    {
        $this->applyTier('starter');
        $admin = $this->fixtures->createAdmin();
        $customer = $this->fixtures->createVerifiedCustomer();

        $manualB = Carrier::query()->create([
            'slug' => 'manual-b',
            'name' => 'Kurir Manual B',
            'driver' => 'manual',
            'is_enabled' => true,
        ]);

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
                'status' => NormalizeShipmentStatus::LABELED,
                'awb' => 'ACTIVE-LABELLED-1',
                'tracking_number' => 'ACTIVE-LABELLED-1',
            ],
        );

        OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $this->fixtures->inventory->id,
            'driver' => 'manual',
            'carrier_id' => $manualB->id,
            'carrier_code' => 'manual-b',
            'service_code' => 'Reguler',
            'cost' => 15000,
            'currency_code' => 'IDR',
            'status' => NormalizeShipmentStatus::PENDING,
            'awb' => null,
            'tracking_number' => null,
        ]);

        $component = $this->cpanelCreateShippingLabel($admin, $order->fresh());

        $this->assertNull($component->get('data.tracking_number'));
        $this->assertSame((string) $manualB->id, (string) $component->get('data.carrier_id'));
    }

    public function test_bug2_sync_order_shipping_ignores_cancelled_awb_and_keeps_manual_fulfillment_open(): void
    {
        $this->applyTier('komship');
        $admin = $this->fixtures->createAdmin();
        $customer = $this->fixtures->createVerifiedCustomer();

        ['order' => $order, 'shipment' => $cancelled, 'product' => $product] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::Processing,
                'payment_status' => PaymentStatus::Paid,
            ],
            [
                'driver' => 'komerce',
                'status' => NormalizeShipmentStatus::CANCELLED,
                'awb' => 'OLD-CANCELLED-AWB-SYNC',
                'tracking_number' => 'OLD-CANCELLED-AWB-SYNC',
                'external_reference' => 'RO-CANC-SYNC-1',
            ],
        );

        $replacement = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $this->fixtures->inventory->id,
            'driver' => 'manual',
            'carrier_id' => $this->fixtures->carriers['manual']->id,
            'carrier_code' => 'manual',
            'carrier_name' => 'Kurir Toko',
            'service_code' => 'Reguler',
            'cost' => 15000,
            'currency_code' => 'IDR',
            'status' => NormalizeShipmentStatus::PENDING,
            'awb' => null,
            'tracking_number' => null,
        ]);
        $replacement->lines()->create([
            'purchasable_type' => $product->getMorphClass(),
            'purchasable_id' => $product->id,
            'qty' => 1,
        ]);

        resolve(SyncOrderShippingFromShipments::class)->handle($order->fresh());

        $this->assertDatabaseMissing((new OrderShipping)->getTable(), [
            'order_id' => $order->id,
            'tracking_number' => 'OLD-CANCELLED-AWB-SYNC',
        ]);

        $orderItem = OrderItem::query()->where('order_id', $order->id)->sole();
        $this->assertNull($orderItem->order_shipping_id);
        $this->assertNotSame(FulfillmentStatus::Cancelled, $orderItem->fulfillment_status);

        $fulfillment = $this->cpanelOrderFulfillment($admin, $order->fresh());
        $this->assertTrue($fulfillment->instance()->hasUnfulfilledItems());

        $labelSlideOver = $this->cpanelCreateShippingLabel($admin, $order->fresh());
        $this->assertCount(1, $labelSlideOver->instance()->unfulfilledItems);

        $labelSlideOver
            ->set('data.tracking_number', 'MANUAL-REPLACEMENT-1')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas((new OrderShipping)->getTable(), [
            'order_id' => $order->id,
            'tracking_number' => 'MANUAL-REPLACEMENT-1',
        ]);
        $this->assertSame('MANUAL-REPLACEMENT-1', $replacement->fresh()->awb);
        $this->assertSame('OLD-CANCELLED-AWB-SYNC', $cancelled->fresh()->awb);
    }

    public function test_bug2_sync_releases_item_bindings_when_previously_synced_shipment_becomes_cancelled(): void
    {
        $this->applyTier('komship');
        $admin = $this->fixtures->createAdmin();
        $customer = $this->fixtures->createVerifiedCustomer();

        ['order' => $order, 'shipment' => $shipment, 'product' => $product] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::Processing,
                'payment_status' => PaymentStatus::Paid,
            ],
            [
                'driver' => 'komerce',
                'status' => NormalizeShipmentStatus::LABELED,
                'awb' => 'WAS-ACTIVE-NOW-CANCELLED',
                'tracking_number' => 'WAS-ACTIVE-NOW-CANCELLED',
                'external_reference' => 'RO-WAS-ACTIVE-1',
            ],
        );

        resolve(SyncOrderShippingFromShipments::class)->handle($order->fresh());

        $this->assertNotNull(OrderItem::query()->where('order_id', $order->id)->value('order_shipping_id'));

        $shipment->forceFill(['status' => NormalizeShipmentStatus::CANCELLED])->save();

        $replacement = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $this->fixtures->inventory->id,
            'driver' => 'manual',
            'carrier_id' => $this->fixtures->carriers['manual']->id,
            'carrier_code' => 'manual',
            'service_code' => 'Reguler',
            'cost' => 15000,
            'currency_code' => 'IDR',
            'status' => NormalizeShipmentStatus::PENDING,
        ]);
        $replacement->lines()->create([
            'purchasable_type' => $product->getMorphClass(),
            'purchasable_id' => $product->id,
            'qty' => 1,
        ]);

        resolve(SyncOrderShippingFromShipments::class)->handle($order->fresh());

        $orderItem = OrderItem::query()->where('order_id', $order->id)->sole();
        $this->assertNull($orderItem->order_shipping_id);
        $this->assertSame(FulfillmentStatus::Pending, $orderItem->fulfillment_status);

        $fulfillment = $this->cpanelOrderFulfillment($admin, $order->fresh());
        $this->assertTrue($fulfillment->instance()->hasUnfulfilledItems());

        $this->cpanelCreateShippingLabel($admin, $order->fresh())
            ->set('data.tracking_number', 'MANUAL-AFTER-SYNC-CANCEL')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('MANUAL-AFTER-SYNC-CANCEL', $replacement->fresh()->awb);
    }

    public function test_bug3_mark_order_paid_from_komerce_delegates_to_komerce_payment_provider_for_qrisly_when_provider_missing(): void
    {
        $this->applyTier('payment_and_qrisly')
            ->withPaymentStatus('*', 'NOT_FOUND', 50000, 404)
            ->withQrislyStatus('QRISLY-HIST-99', 'paid', 50001, 200)
            ->apply();

        $customer = $this->fixtures->createVerifiedCustomer();
        ['order' => $order] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'price_amount' => 50000,
                'payment_method_id' => $this->fixtures->paymentMethods['qris']->id,
                'metadata' => [
                    'komerce' => [
                        'payment_type' => 'qris',
                        'provider' => null,
                        'payment_ref' => null,
                        'qrisly_history_id' => 'QRISLY-HIST-99',
                    ],
                ],
            ],
            [],
            null,
        );

        $result = resolve(MarkOrderPaidFromKomerce::class)->handle('QRISLY-HIST-99');

        $this->assertSame('handled', $result);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);

        Http::assertSent(fn (Request $req): bool => str_contains($req->url(), KomerceHttpFakeBuilder::QRISLY_BASE.'/api/v1/qrisly/payment-status/QRISLY-HIST-99'));
        Http::assertNotSent(fn (Request $req): bool => str_contains($req->url(), KomerceHttpFakeBuilder::PAYMENT_BASE));
    }

    public function test_bug3_transaction_komerce_provider_takes_precedence_over_stale_order_provider(): void
    {
        $this->applyTier('payment_and_qrisly')
            ->withPaymentStatus('*', 'PENDING', 50000, 404)
            ->withQrislyStatus('QRISLY-RETRY-55', 'paid', 50001, 200)
            ->apply();

        $customer = $this->fixtures->createVerifiedCustomer();
        ['order' => $order] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'price_amount' => 50000,
                'payment_method_id' => $this->fixtures->paymentMethods['qris']->id,
                'metadata' => [
                    'komerce' => [
                        'payment_type' => 'qris',
                        'provider' => 'payment_api',
                        'payment_ref' => 'KOMPAY-OLD-1',
                        'qrisly_history_id' => 'QRISLY-RETRY-55',
                    ],
                ],
            ],
            [],
            null,
        );

        PaymentTransaction::query()->create([
            'order_id' => $order->id,
            'payment_method_id' => $this->fixtures->paymentMethods['qris']->id,
            'driver' => 'komerce',
            'type' => TransactionType::Initiate,
            'status' => TransactionStatus::Pending,
            'amount' => 50000,
            'currency_code' => 'IDR',
            'reference' => 'QRISLY-RETRY-55',
            'metadata' => [
                'komerce_provider' => 'qrisly',
                'komerce_payment_type' => 'qris',
            ],
        ]);

        $result = resolve(MarkOrderPaidFromKomerce::class)->handle('QRISLY-RETRY-55');

        $this->assertSame('handled', $result);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        Http::assertNotSent(fn (Request $req): bool => str_contains($req->url(), KomerceHttpFakeBuilder::PAYMENT_BASE));
    }

    public function test_bug4_cancel_order_with_providers_ignores_cancelled_shipment_awb_when_checking_has_shipped(): void
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
                'awb' => 'OLD-CANCELLED-AWB-CANC',
                'tracking_number' => 'OLD-CANCELLED-AWB-CANC',
            ],
            null,
            $product,
        );

        // Simulate stock reserved at checkout (10 -> 8) and active replacement unshipped shipment.
        $product->decreaseStock($this->fixtures->inventory->id, 2);
        $cancelled->lines()->update(['qty' => 0]);

        $active = OrderShipment::query()->create([
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
        $active->lines()->create([
            'purchasable_type' => $product->getMorphClass(),
            'purchasable_id' => $product->id,
            'qty' => 2,
        ]);

        $cancelWithProviders = resolve(CancelOrderWithProviders::class);
        $cancelCustomerOrder = resolve(CancelCustomerOrder::class);

        $this->assertFalse($cancelWithProviders->hasShipped($order->fresh()));
        $this->assertTrue($cancelCustomerOrder->canCancel($order->fresh()));

        $cancelCustomerOrder->handle($order->fresh(), $customer, 'Customer changed mind');

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(PaymentStatus::Voided, $order->payment_status);
        $this->assertSame(NormalizeShipmentStatus::CANCELLED, $active->fresh()->status);
        $this->assertSame(10, (int) $product->fresh()->stockInventory($this->fixtures->inventory->id));

        // Active shipped AWB still blocks cancellation.
        ['order' => $shippedOrder] = $this->fixtures->createOrderWithShipment(
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
                'awb' => 'CANC-AWB-1',
            ],
            null,
        );
        OrderShipment::query()->create([
            'order_id' => $shippedOrder->id,
            'inventory_id' => $this->fixtures->inventory->id,
            'driver' => 'manual',
            'carrier_code' => 'manual',
            'service_code' => 'Reguler',
            'cost' => 15000,
            'currency_code' => 'IDR',
            'status' => NormalizeShipmentStatus::IN_TRANSIT,
            'awb' => 'ACTIVE-SHIPPED-AWB',
            'tracking_number' => 'ACTIVE-SHIPPED-AWB',
        ]);

        $this->assertTrue($cancelWithProviders->hasShipped($shippedOrder->fresh()));
        $this->assertFalse($cancelCustomerOrder->canCancel($shippedOrder->fresh()));
    }

    public function test_bug5_order_shipments_page_prefers_non_cancelled_shipment_for_blank_and_matching_tracking(): void
    {
        $this->applyTier('komship');
        $admin = $this->fixtures->createAdmin();
        $customer = $this->fixtures->createVerifiedCustomer();

        // 1. Blank tracking_number: active manual #1 + newer cancelled komerce #2 -> picks #1
        ['order' => $orderBlank, 'shipment' => $activeManual] = $this->fixtures->createOrderWithShipment(
            $customer,
            ['status' => OrderStatus::Processing, 'payment_status' => PaymentStatus::Paid],
            [
                'driver' => 'manual',
                'carrier_id' => $this->fixtures->carriers['manual']->id,
                'carrier_code' => 'manual',
                'status' => NormalizeShipmentStatus::PENDING,
                'awb' => null,
                'tracking_number' => null,
            ],
        );

        OrderShipment::query()->create([
            'order_id' => $orderBlank->id,
            'inventory_id' => $this->fixtures->inventory->id,
            'driver' => 'komerce',
            'carrier_id' => $this->fixtures->carriers['jne']->id,
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'cost' => 12000,
            'currency_code' => 'IDR',
            'status' => NormalizeShipmentStatus::CANCELLED,
            'awb' => null,
            'tracking_number' => null,
        ]);

        $shippingBlank = OrderShipping::query()->create([
            'order_id' => $orderBlank->id,
            'carrier_id' => $this->fixtures->carriers['manual']->id,
            'status' => ShipmentStatus::Pending,
            'tracking_number' => null,
        ]);

        $page = Livewire::actingAs($admin)->test(OrderShipments::class);
        $method = new ReflectionMethod(OrderShipments::class, 'orderShipmentFor');

        /** @var OrderShipment|null $matchedBlank */
        $matchedBlank = $method->invoke($page->instance(), $shippingBlank);
        $this->assertNotNull($matchedBlank);
        $this->assertSame($activeManual->id, $matchedBlank->id);

        // 2. Matching tracking_number: active shipment #1 + newer cancelled shipment #2 with same AWB -> picks active #1
        ['order' => $orderSameAwb, 'shipment' => $activeWithAwb] = $this->fixtures->createOrderWithShipment(
            $customer,
            ['status' => OrderStatus::Processing, 'payment_status' => PaymentStatus::Paid],
            [
                'driver' => 'komerce',
                'status' => NormalizeShipmentStatus::LABELED,
                'awb' => 'SHARED-AWB-77',
                'tracking_number' => 'SHARED-AWB-77',
            ],
        );

        OrderShipment::query()->create([
            'order_id' => $orderSameAwb->id,
            'inventory_id' => $this->fixtures->inventory->id,
            'driver' => 'komerce',
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'cost' => 12000,
            'currency_code' => 'IDR',
            'status' => NormalizeShipmentStatus::CANCELLED,
            'awb' => 'SHARED-AWB-77',
            'tracking_number' => 'SHARED-AWB-77',
        ]);

        $shippingWithAwb = OrderShipping::query()->where('order_id', $orderSameAwb->id)->first()
            ?? OrderShipping::query()->create([
                'order_id' => $orderSameAwb->id,
                'carrier_id' => $this->fixtures->carriers['jne']->id,
                'status' => ShipmentStatus::InTransit,
                'tracking_number' => 'SHARED-AWB-77',
            ]);

        /** @var OrderShipment|null $matchedAwb */
        $matchedAwb = $method->invoke($page->instance(), $shippingWithAwb);
        $this->assertNotNull($matchedAwb);
        $this->assertSame($activeWithAwb->id, $matchedAwb->id);
    }

    public function test_sibling_shipment_add_event_and_print_shipment_labels_ignore_cancelled_shipments(): void
    {
        $this->applyTier('komship');
        $admin = $this->fixtures->createAdmin();
        $customer = $this->fixtures->createVerifiedCustomer();

        ['order' => $order] = $this->fixtures->createOrderWithShipment(
            $customer,
            ['status' => OrderStatus::Processing, 'payment_status' => PaymentStatus::Paid],
            [
                'driver' => 'komerce',
                'status' => NormalizeShipmentStatus::CANCELLED,
                'awb' => 'CANC-SIBLING-1',
                'tracking_number' => 'CANC-SIBLING-1',
                'external_reference' => 'RO-CANC-SIBLING-1',
                'metadata' => ['komerce' => ['order_no' => 'RO-CANC-SIBLING-1']],
            ],
        );

        $inv2 = Inventory::factory()->create(['name' => 'Gudang Manual']);
        OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inv2->id,
            'driver' => 'manual',
            'carrier_id' => $this->fixtures->carriers['manual']->id,
            'carrier_code' => 'manual',
            'service_code' => 'Reguler',
            'cost' => 15000,
            'currency_code' => 'IDR',
            'status' => NormalizeShipmentStatus::PENDING,
            'awb' => null,
            'tracking_number' => null,
        ]);

        $shipping = OrderShipping::query()->create([
            'order_id' => $order->id,
            'carrier_id' => $this->fixtures->carriers['manual']->id,
            'status' => ShipmentStatus::Pending,
            'tracking_number' => null,
        ]);

        // ShipmentAddEvent should allow adding an event for the manual shipment without blocking on cancelled komerce
        Livewire::actingAs($admin)
            ->test(ShipmentAddEvent::class, ['shipment' => $shipping])
            ->set('data.status', ShipmentStatus::PickedUp->value)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(ShipmentStatus::PickedUp, $shipping->fresh()->status);

        Http::fake();

        // PrintShipmentLabels should skip cancelled komerce shipment and throw neutral "belum tersedia" instead of calling Komerce
        try {
            resolve(PrintShipmentLabels::class)->handle($order->fresh());
            $this->fail('Expected PrintShipmentLabels to ignore cancelled komerce shipment.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Label pengiriman otomatis belum tersedia', $e->getMessage());
        }

        Http::assertNothingSent();
    }
}
