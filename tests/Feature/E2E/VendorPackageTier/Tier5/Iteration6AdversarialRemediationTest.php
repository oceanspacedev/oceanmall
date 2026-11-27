<?php

declare(strict_types=1);

namespace Tests\Feature\E2E\VendorPackageTier\Tier5;

use App\Actions\Shipping\NormalizeShipmentStatus;
use App\Actions\Shipping\RefreshShipmentTracking;
use App\Actions\Shipping\SyncOrderShippingFromShipments;
use App\Livewire\Shopper\OrderFulfillment;
use App\Livewire\Shopper\OrderSummary;
use App\Livewire\Shopper\SlideOvers\CreateShippingLabel;
use App\Models\OrderShipment;
use App\Support\CustomerCheckoutState;
use App\Support\OrderShipmentOpsPresenter;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Enum\ShippingStatus;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\OrderItem;
use Tests\Feature\E2E\VendorPackageTier\Support\KomerceHttpFakeBuilder;
use Tests\Feature\E2E\VendorPackageTier\Support\VendorPackageTierTestCase;

final class Iteration6AdversarialRemediationTest extends VendorPackageTierTestCase
{
    public function test_gap1_mixed_split_warehouse_komerce_and_manual_unblocks_manual_awb_and_confirm_received(): void
    {
        $this->applyTier('komship');
        $admin = $this->fixtures->createAdmin();
        $customer = $this->fixtures->createVerifiedCustomer();
        $secondInventory = $this->fixtures->ensureSecondInventory();

        $productKomerce = $this->fixtures->createStockedProduct(100000, 10, 500, $this->fixtures->inventory);
        $productManual = $this->fixtures->createStockedProduct(100000, 10, 500, $secondInventory);

        ['order' => $order, 'shipment' => $komerceShipment] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::Processing,
                'payment_status' => PaymentStatus::Paid,
                'shipping_status' => ShippingStatus::Unfulfilled,
                'shipping_option_id' => null,
                'price_amount' => 227000,
            ],
            [
                'driver' => 'komerce',
                'carrier_id' => $this->fixtures->carriers['jne']->id,
                'carrier_code' => 'jne',
                'carrier_name' => 'JNE Express',
                'service_code' => 'REG',
                'service_name' => 'REG',
                'status' => NormalizeShipmentStatus::LABELED,
                'awb' => 'KOM-MIX-AWB-1',
                'tracking_number' => 'KOM-MIX-AWB-1',
                'external_reference' => 'RO-MIX-1',
                'cost' => 12000,
                'metadata' => ['komerce' => ['order_no' => 'RO-MIX-1', 'awb' => 'KOM-MIX-AWB-1']],
            ],
            null,
            $productKomerce,
            $this->fixtures->inventory,
        );

        $manualItem = OrderItem::query()->create([
            'order_id' => $order->id,
            'name' => $productManual->name,
            'sku' => $productManual->sku ?? 'SKU-MANUAL-2',
            'product_type' => $productManual->getMorphClass(),
            'product_id' => $productManual->id,
            'quantity' => 1,
            'unit_price_amount' => 100000,
        ]);

        $manualShipment = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $secondInventory->id,
            'driver' => 'manual',
            'carrier_id' => $this->fixtures->carriers['manual']->id,
            'carrier_code' => 'manual',
            'carrier_name' => 'Kurir Toko',
            'service_code' => 'Reguler',
            'service_name' => 'Reguler',
            'status' => NormalizeShipmentStatus::PENDING,
            'awb' => null,
            'tracking_number' => null,
            'cost' => 15000,
            'currency_code' => 'IDR',
        ]);
        $manualShipment->lines()->create([
            'purchasable_type' => $productManual->getMorphClass(),
            'purchasable_id' => $productManual->id,
            'qty' => 1,
        ]);

        // Mirror the already-labeled komerce shipment so its item is bound, leaving manualItem unfulfilled.
        resolve(SyncOrderShippingFromShipments::class)->handle($order->refresh());
        $this->assertNull($manualItem->refresh()->order_shipping_id);
        $this->assertSame(ShippingStatus::PartiallyShipped, $order->refresh()->shipping_status);

        // Customer cannot confirm receipt while the manual parcel has no AWB.
        $this->apiConfirmReceived($customer, $order->number)->assertStatus(422);

        // OrderFulfillment exposes Shopper's manual label button & dispatches slide-over on click.
        $fulfillment = Livewire::actingAs($admin)
            ->test(OrderFulfillment::class, ['order' => $order->refresh()]);
        $this->assertTrue($fulfillment->instance()->hasUnfulfilledItems());
        $fulfillment->assertSee('Cetak Stiker Komerce', false)
            ->assertSee(__('shopper::pages/orders.create_shipping_label'), false)
            ->call('openShippingLabel')
            ->assertDispatched('openPanel', component: 'shopper-slide-overs.create-shipping-label');

        // CreateShippingLabel targets only the manual carrier/item and mirrors onto manualShipment only.
        $slideOver = Livewire::actingAs($admin)
            ->test(CreateShippingLabel::class, ['order' => $order->refresh()])
            ->assertSet('data.carrier_id', $this->fixtures->carriers['manual']->id);

        $this->assertSame([$manualItem->id], $slideOver->instance()->unfulfilledItems->pluck('id')->all());

        $slideOver->set('data.tracking_number', 'MANUAL-MIX-AWB-2')
            ->call('save')
            ->assertHasNoErrors();

        $manualShipment->refresh();
        $komerceShipment->refresh();
        $this->assertSame('MANUAL-MIX-AWB-2', $manualShipment->awb);
        $this->assertSame('MANUAL-MIX-AWB-2', $manualShipment->tracking_number);
        $this->assertSame(NormalizeShipmentStatus::LABELED, $manualShipment->status);
        $this->assertSame('KOM-MIX-AWB-1', $komerceShipment->awb);
        $this->assertSame('RO-MIX-1', $komerceShipment->external_reference);

        $this->assertFalse(
            Livewire::actingAs($admin)
                ->test(OrderFulfillment::class, ['order' => $order->refresh()])
                ->instance()
                ->hasUnfulfilledItems(),
        );

        // Customer can now confirm receipt and complete the order.
        $this->apiConfirmReceived($customer, $order->number)->assertOk();
        $this->assertSame(NormalizeShipmentStatus::DELIVERED, $komerceShipment->refresh()->status);
        $this->assertSame(NormalizeShipmentStatus::DELIVERED, $manualShipment->refresh()->status);
        $this->assertSame(OrderStatus::Completed, $order->refresh()->status);

        // When BOTH komerce (pending, lower ID) and manual (pending) lack AWBs, manual save must not touch komerce.
        ['order' => $dualPendingOrder, 'shipment' => $pendingKomerce] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::Processing,
                'payment_status' => PaymentStatus::Paid,
                'shipping_status' => ShippingStatus::Unfulfilled,
                'shipping_option_id' => null,
                'price_amount' => 227000,
            ],
            [
                'driver' => 'komerce',
                'carrier_id' => $this->fixtures->carriers['jne']->id,
                'status' => NormalizeShipmentStatus::PENDING,
                'awb' => null,
                'tracking_number' => null,
                'cost' => 12000,
            ],
            null,
            $productKomerce,
            $this->fixtures->inventory,
        );
        $dualManualItem = OrderItem::query()->create([
            'order_id' => $dualPendingOrder->id,
            'name' => $productManual->name,
            'sku' => $productManual->sku ?? 'SKU-MANUAL-DUAL',
            'product_type' => $productManual->getMorphClass(),
            'product_id' => $productManual->id,
            'quantity' => 1,
            'unit_price_amount' => 100000,
        ]);
        $pendingManual = OrderShipment::query()->create([
            'order_id' => $dualPendingOrder->id,
            'inventory_id' => $secondInventory->id,
            'driver' => 'manual',
            'carrier_id' => $this->fixtures->carriers['manual']->id,
            'carrier_code' => 'manual',
            'service_code' => 'Reguler',
            'status' => NormalizeShipmentStatus::PENDING,
            'cost' => 15000,
            'currency_code' => 'IDR',
        ]);
        $pendingManual->lines()->create([
            'purchasable_type' => $productManual->getMorphClass(),
            'purchasable_id' => $productManual->id,
            'qty' => 1,
        ]);

        $dualSlideOver = Livewire::actingAs($admin)
            ->test(CreateShippingLabel::class, ['order' => $dualPendingOrder])
            ->assertSet('data.carrier_id', $this->fixtures->carriers['manual']->id);
        $this->assertSame([$dualManualItem->id], $dualSlideOver->instance()->unfulfilledItems->pluck('id')->all());

        $dualSlideOver->set('data.tracking_number', 'MANUAL-DUAL-1')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull($pendingKomerce->refresh()->awb);
        $this->assertSame('MANUAL-DUAL-1', $pendingManual->refresh()->awb);

        // Mixed order where only the manual parcel has an AWB labels the button "Cetak Stiker Resi" (not "Cetak Stiker Komerce").
        Livewire::actingAs($admin)
            ->test(OrderFulfillment::class, ['order' => $dualPendingOrder->refresh()])
            ->assertViewHas('providerAwbs', [])
            ->assertViewHas('canPrintProviderLabel', true)
            ->assertViewHas('printProviderLabelText', 'Cetak Stiker Resi')
            ->assertSee('Cetak Stiker Resi', false)
            ->assertDontSee('Cetak Stiker Komerce', false)
            ->assertSee('Terbitkan Resi Komerce', false);

        // Negative control: pure komerce order with NO manual shipment keeps Shopper's manual button hidden.
        ['order' => $pureKomerceOrder] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::Processing,
                'payment_status' => PaymentStatus::Paid,
                'shipping_status' => ShippingStatus::Unfulfilled,
            ],
            [
                'driver' => 'komerce',
                'status' => NormalizeShipmentStatus::PENDING,
                'awb' => null,
                'tracking_number' => null,
            ],
        );
        $pureFulfillment = Livewire::actingAs($admin)
            ->test(OrderFulfillment::class, ['order' => $pureKomerceOrder]);
        $this->assertFalse($pureFulfillment->instance()->hasUnfulfilledItems());
        $pureFulfillment->assertDontSee(__('shopper::pages/orders.create_shipping_label'), false);
    }

    public function test_gap2_warehouse_bulk_labels_enforces_can_print_label_and_removes_fake_barcode_fallbacks(): void
    {
        $this->applyTier('komship');
        $admin = $this->fixtures->createAdmin();
        $customer = $this->fixtures->createVerifiedCustomer();

        // 1. Blank-AWB shipment with delivery_order_no must NOT be printable in bulkLabels.
        ['order' => $blankOrder] = $this->fixtures->createOrderWithShipment(
            $customer,
            ['number' => 'ORD-BLANK-BULK-1'],
            [
                'driver' => 'komerce',
                'status' => NormalizeShipmentStatus::PENDING,
                'awb' => null,
                'tracking_number' => null,
                'external_reference' => 'RO-NO-AWB-YET',
                'metadata' => ['komerce' => ['order_no' => 'RO-NO-AWB-YET']],
            ],
        );

        // 2. Unregistered driver with AWB must NOT be printable in bulkLabels.
        Carrier::query()->create(['name' => 'Acme', 'slug' => 'acme-bulk', 'driver' => 'acme', 'is_enabled' => true]);
        ['order' => $unregOrder] = $this->fixtures->createOrderWithShipment(
            $customer,
            ['number' => 'ORD-UNREG-BULK-1'],
            [
                'driver' => 'acme',
                'carrier_code' => 'acme-bulk',
                'status' => NormalizeShipmentStatus::LABELED,
                'awb' => 'ACME-UNREG-1',
                'tracking_number' => 'ACME-UNREG-1',
            ],
        );

        // 3. Cancelled shipment with AWB must return 422 (never 200 "Cetak Semua Label (0)").
        ['order' => $cancelledOrder] = $this->fixtures->createOrderWithShipment(
            $customer,
            ['number' => 'ORD-CANC-BULK-1'],
            [
                'driver' => 'komerce',
                'status' => NormalizeShipmentStatus::CANCELLED,
                'awb' => 'JNE-CANC-BULK-1',
                'tracking_number' => 'JNE-CANC-BULK-1',
            ],
        );

        // 4. Valid printable shipment.
        ['order' => $validOrder] = $this->fixtures->createOrderWithShipment(
            $customer,
            ['number' => 'ORD-VALID-BULK-1'],
            [
                'driver' => 'komerce',
                'status' => NormalizeShipmentStatus::LABELED,
                'awb' => 'JNE-VALID-BULK-1',
                'tracking_number' => 'JNE-VALID-BULK-1',
                'external_reference' => 'RO-VALID-BULK-1',
                'metadata' => ['komerce' => ['order_no' => 'RO-VALID-BULK-1', 'awb' => 'JNE-VALID-BULK-1']],
            ],
        );

        foreach ([$blankOrder, $unregOrder, $cancelledOrder] as $blockedOrder) {
            $this->actingAs($admin)
                ->get(route('shopper.orders.warehouse.bulk-labels', ['orders' => $blockedOrder->id]))
                ->assertStatus(422)
                ->assertViewIs('shopper.shipping.label-error')
                ->assertDontSee('RO-NO-AWB-YET', false)
                ->assertDontSee('ACME-UNREG-1', false)
                ->assertDontSee('JNE-CANC-BULK-1', false)
                ->assertDontSee('Cetak Semua Label (0)', false);
        }

        // Mixed selection prints ONLY the 1 valid printable shipment.
        $ids = implode(',', [$blankOrder->id, $unregOrder->id, $cancelledOrder->id, $validOrder->id]);
        $this->actingAs($admin)
            ->get(route('shopper.orders.warehouse.bulk-labels', ['orders' => $ids]))
            ->assertOk()
            ->assertViewIs('shopper.shipping.bulk-labels')
            ->assertSee('Cetak Semua Label (1)', false)
            ->assertSee('JNE-VALID-BULK-1', false)
            ->assertDontSee('RO-NO-AWB-YET', false)
            ->assertDontSee($blankOrder->number, false)
            ->assertDontSee('ACME-UNREG-1', false)
            ->assertDontSee('JNE-CANC-BULK-1', false);

        // View itself never falls back to delivery_order_no or order->number when AWB is missing.
        $presenter = resolve(OrderShipmentOpsPresenter::class);
        $blankPresentation = $presenter->shipments($blankOrder)[0];
        $rendered = view('shopper.shipping.bulk-labels', [
            'labels' => [['order' => $blankOrder, 'shipment' => $blankPresentation]],
        ])->render();
        $this->assertStringNotContainsString('RO-NO-AWB-YET', $rendered);
        $this->assertStringNotContainsString($blankOrder->number, $rendered);
    }

    public function test_gap3_shipping_refresh_tracking_schedule_and_command_align_on_fulfilling_tiers_and_protect_starter_quota(): void
    {
        $scheduled = static function (string $command): ScheduledEvent {
            $event = collect(resolve(Schedule::class)->events())
                ->first(static fn (ScheduledEvent $e): bool => str_contains((string) $e->command, $command));

            if (! $event instanceof ScheduledEvent) {
                throw new \RuntimeException("Scheduled command [{$command}] not found.");
            }

            return $event;
        };

        // 1. Keyless: both fulfill-paid and refresh-tracking are skipped by schedule & command.
        $this->applyTier('keyless');
        $this->assertFalse($scheduled('shipping:fulfill-paid')->filtersPass($this->app));
        $this->assertFalse($scheduled('shipping:refresh-tracking')->filtersPass($this->app));
        $this->artisan('shipping:refresh-tracking')
            ->expectsOutputToContain('skipping tracking refresh')
            ->assertSuccessful();
        Http::assertNothingSent();

        // 2. Starter: both fulfill-paid and refresh-tracking schedules stay OFF (protecting the 100/day Cost quota)
        // and the command makes zero HTTP requests, while on-demand tracking via TracksShipments still works.
        $this->applyTier('starter')
            ->withCostTracking([
                'delivered' => false,
                'summary' => ['waybill_number' => 'JNE-STARTER-CRON-1', 'status' => 'ON PROCESS'],
                'delivery_status' => ['status' => 'ON PROCESS'],
                'manifest' => [[
                    'manifest_code' => '100',
                    'manifest_description' => 'Shipment received by JNE',
                    'manifest_date' => '2026-11-26',
                    'manifest_time' => '09:00',
                    'city_name' => 'Cirebon',
                ]],
            ])
            ->apply();

        $this->assertFalse($scheduled('shipping:fulfill-paid')->filtersPass($this->app));
        $this->assertFalse($scheduled('shipping:refresh-tracking')->filtersPass($this->app));

        $customer = $this->fixtures->createVerifiedCustomer();
        ['shipment' => $starterShipment] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::Processing,
                'payment_status' => PaymentStatus::Paid,
                'shipping_status' => ShippingStatus::Shipped,
            ],
            [
                'driver' => 'rajaongkir',
                'carrier_code' => 'jne',
                'status' => NormalizeShipmentStatus::IN_TRANSIT,
                'awb' => 'JNE-STARTER-CRON-1',
                'tracking_number' => 'JNE-STARTER-CRON-1',
            ],
        );

        $this->artisan('shipping:refresh-tracking')
            ->expectsOutputToContain('skipping tracking refresh')
            ->assertSuccessful();
        Http::assertNothingSent();

        resolve(RefreshShipmentTracking::class)->handle($starterShipment);
        Http::assertSent(fn ($req): bool => str_contains($req->url(), '/api/v1/track/waybill'));
        Http::assertSentCount(1);
        $starterShipment->forceFill(['status' => NormalizeShipmentStatus::DELIVERED])->save();

        // 3. Delivery-only: FulfillsShipments is active, so both schedule filters pass and tracking HTTP calls execute.
        $this->applyTier('delivery_only')
            ->withDeliveryDetailResponse([
                'order_no' => 'RO-CRON-DEL-1',
                'airway_bill' => 'JNE-DEL-CRON-1',
                'awb' => 'JNE-DEL-CRON-1',
                'last_status' => 'DELIVERED',
            ])
            ->withDeliveryTrackingResponse([
                'airway_bill' => 'JNE-DEL-CRON-1',
                'last_status' => 'DELIVERED',
                'history' => [['desc' => 'Delivered', 'date' => '2026-11-26 12:00:00', 'code' => '200', 'status' => 'DELIVERED']],
            ])
            ->apply();
        $this->assertTrue($scheduled('shipping:fulfill-paid')->filtersPass($this->app));
        $this->assertTrue($scheduled('shipping:refresh-tracking')->filtersPass($this->app));

        ['shipment' => $deliveryOnlyShipment] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::Processing,
                'payment_status' => PaymentStatus::Paid,
                'shipping_status' => ShippingStatus::Shipped,
            ],
            [
                'driver' => 'komerce',
                'carrier_code' => 'jne',
                'status' => NormalizeShipmentStatus::IN_TRANSIT,
                'awb' => 'JNE-DEL-CRON-1',
                'tracking_number' => 'JNE-DEL-CRON-1',
                'external_reference' => 'RO-CRON-DEL-1',
                'metadata' => ['komerce' => ['order_no' => 'RO-CRON-DEL-1']],
            ],
        );

        $this->artisan('shipping:refresh-tracking')
            ->expectsOutputToContain('Refreshed 1 shipment(s); 0 failed.')
            ->assertSuccessful();

        $this->assertSame(NormalizeShipmentStatus::DELIVERED, $deliveryOnlyShipment->refresh()->status);
        Http::assertSent(fn ($req): bool => str_contains($req->url(), '/order/api/v1/orders/history-airway-bill')
            && $req->hasHeader('x-api-key', 'delivery-key')
            && ($req->data()['airway_bill'] ?? null) === 'JNE-DEL-CRON-1');

        // 4. Komship: both fulfill-paid and refresh-tracking pass schedule filters and refresh Delivery shipments.
        $this->applyTier('komship')
            ->withDeliveryDetailResponse([
                'order_no' => 'RO-CRON-KOM-1',
                'airway_bill' => 'JNE-KOM-CRON-1',
                'awb' => 'JNE-KOM-CRON-1',
                'last_status' => 'DELIVERED',
            ])
            ->withDeliveryTrackingResponse([
                'airway_bill' => 'JNE-KOM-CRON-1',
                'last_status' => 'DELIVERED',
                'history' => [['desc' => 'Delivered', 'date' => '2026-11-26 12:00:00', 'code' => '200', 'status' => 'DELIVERED']],
            ])
            ->apply();
        $this->assertTrue($scheduled('shipping:fulfill-paid')->filtersPass($this->app));
        $this->assertTrue($scheduled('shipping:refresh-tracking')->filtersPass($this->app));

        ['shipment' => $komshipShipment] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::Processing,
                'payment_status' => PaymentStatus::Paid,
                'shipping_status' => ShippingStatus::Shipped,
            ],
            [
                'driver' => 'komerce',
                'carrier_code' => 'jne',
                'status' => NormalizeShipmentStatus::IN_TRANSIT,
                'awb' => 'JNE-KOM-CRON-1',
                'tracking_number' => 'JNE-KOM-CRON-1',
                'external_reference' => 'RO-CRON-KOM-1',
                'metadata' => ['komerce' => ['order_no' => 'RO-CRON-KOM-1']],
            ],
        );

        $this->artisan('shipping:refresh-tracking')
            ->expectsOutputToContain('Refreshed 1 shipment(s); 0 failed.')
            ->assertSuccessful();

        $this->assertSame(NormalizeShipmentStatus::DELIVERED, $komshipShipment->refresh()->status);
    }

    public function test_gap4_api_order_show_falls_back_to_shipping_option_price_when_order_shipments_is_empty(): void
    {
        $this->applyTier('keyless');
        $admin = $this->fixtures->createAdmin();
        $customer = $this->fixtures->createVerifiedCustomer();
        $product = $this->fixtures->createStockedProduct(100000, 10, 500, $this->fixtures->inventory);

        $option = $this->fixtures->manualOption;
        $this->assertNotNull($option);
        $option->forceFill(['price' => 25000])->save();

        $order = Order::factory()->create([
            'number' => 'ORD-NO-SHIPMENT-FALLBACK',
            'customer_id' => $customer->id,
            'price_amount' => 125000,
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
            'shipping_option_id' => $option->id,
            'payment_method_id' => $this->fixtures->paymentMethods['manual']->id,
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'name' => $product->name,
            'sku' => $product->sku ?? 'SKU-FB-1',
            'product_type' => $product->getMorphClass(),
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price_amount' => 100000,
        ]);

        // Zero OrderShipment rows exist.
        $this->assertSame(0, OrderShipment::query()->where('order_id', $order->id)->count());

        // Cpanel OrderSummary
        Livewire::actingAs($admin)
            ->test(OrderSummary::class, ['order' => $order->fresh()])
            ->assertViewHas('shippingPrice', 25000);

        // Storefront Web Account\OrderController::show
        $this->withoutVite();
        $this->webShowOrder($customer, $order)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.subtotal', 100000)
                ->where('summary.shipping', 25000)
                ->where('summary.tax_inclusive', true)
                ->where('summary.total', 125000));

        // Mobile API v1 OrderController::show
        $this->apiShowOrder($customer, $order->number)
            ->assertOk()
            ->assertJsonPath('data.totals.subtotal', 100000)
            ->assertJsonPath('data.totals.shipping', 25000)
            ->assertJsonPath('data.totals.adjustment', 0)
            ->assertJsonPath('data.totals.total', 125000)
            ->assertJsonPath('data.breakdown.shipping', 25000)
            ->assertJsonPath('data.breakdown.tax_inclusive', true)
            ->assertJsonPath('data.breakdown.total', 125000);
    }

    public function test_gap5_api_checkout_show_clears_stale_shipping_option_after_provider_switch_or_plan_change(): void
    {
        $this->applyTier('komship');
        $customer = $this->fixtures->createVerifiedCustomer();
        $productA = $this->fixtures->createStockedProduct(100000, 10, 500, $this->fixtures->inventory);
        $secondInventory = $this->fixtures->ensureSecondInventory();
        $productB = $this->fixtures->createStockedProduct(100000, 10, 500, $secondInventory);

        Sanctum::actingAs($customer);
        $this->postJson('/api/v1/cart/items', ['product_id' => $productA->id, 'quantity' => 1])->assertOk();

        $this->postJson('/api/v1/checkout/shipping-address', [
            'first_name' => 'Budi',
            'last_name' => 'Santoso',
            'street_address' => 'Jl. Merdeka 1',
            'postal_code' => '10110',
            'city' => 'Jakarta Pusat',
            'state' => 'DKI Jakarta',
            'phone_number' => '081234567890',
            'destination_id' => '17601',
            'destination_label' => 'GAMBIR, GAMBIR, JAKARTA PUSAT, DKI JAKARTA, 10110',
            'pin_point' => '-6.1754,106.8272',
        ])->assertOk();

        $this->postJson('/api/v1/checkout/shipping-option', ['service_code' => 'ide:IDFLAT'])
            ->assertOk()
            ->assertJsonPath('data.shipping_option.service_code', 'ide:IDFLAT');

        // 1. Switch shipping provider to 'none' -> GET /api/v1/checkout clears stale komerce rate (parity with Web).
        config()->set('shopper.shipping.provider', 'none');

        $checkout = $this->getJson('/api/v1/checkout')->assertOk();
        $this->assertNull($checkout->json('data.shipping_option'));
        $this->assertSame([], $checkout->json('data.shipping_options_by_shipment'));
        $this->assertNull(resolve(CustomerCheckoutState::class)->get($customer)['shipping_option'] ?? null);

        // Place order refuses with 422 because shipping option was cleared.
        $this->postJson('/api/v1/checkout/place-order', [
            'payment_method_id' => $this->fixtures->paymentMethods['manual']->id,
        ])->assertStatus(422);

        // 2. Switch back to komship (without flushing checkout state), pick single-warehouse rate, then split cart across 2nd warehouse -> stale rate cleared.
        config()->set('shopper.shipping.provider', null);
        $this->postJson('/api/v1/checkout/shipping-option', ['service_code' => 'ide:IDFLAT'])
            ->assertOk()
            ->assertJsonPath('data.shipping_option.service_code', 'ide:IDFLAT');

        $this->postJson('/api/v1/cart/items', ['product_id' => $productB->id, 'quantity' => 1])->assertOk();

        $splitCheckout = $this->getJson('/api/v1/checkout')->assertOk();
        $this->assertNull($splitCheckout->json('data.shipping_option'));
        $this->assertSame([], $splitCheckout->json('data.shipping_options_by_shipment'));
    }

    public function test_gap6_mixed_batch_print_label_validates_provider_label_url_reachability(): void
    {
        $admin = $this->fixtures->createAdmin();
        $customer = $this->fixtures->createVerifiedCustomer();
        $secondInventory = $this->fixtures->ensureSecondInventory();

        $productA = $this->fixtures->createStockedProduct(50000, 10, 500, $this->fixtures->inventory);
        $productB = $this->fixtures->createStockedProduct(50000, 10, 500, $secondInventory);

        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'currency_code' => 'IDR',
            'price_amount' => 122000,
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
        ]);

        $manualShipment = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $this->fixtures->inventory->id,
            'driver' => 'manual',
            'carrier_code' => 'manual',
            'carrier_name' => 'Kurir Toko',
            'service_code' => 'Reguler',
            'service_name' => 'Reguler',
            'status' => NormalizeShipmentStatus::LABELED,
            'awb' => 'MANUAL-MIX-404',
            'tracking_number' => 'MANUAL-MIX-404',
            'cost' => 10000,
            'currency_code' => 'IDR',
        ]);
        $manualShipment->lines()->create([
            'purchasable_type' => $productA->getMorphClass(),
            'purchasable_id' => $productA->id,
            'qty' => 1,
        ]);

        $komerceShipment = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $secondInventory->id,
            'driver' => 'komerce',
            'carrier_code' => 'jne',
            'carrier_name' => 'JNE',
            'service_code' => 'REG',
            'service_name' => 'REG',
            'status' => NormalizeShipmentStatus::LABELED,
            'awb' => 'KOM-MIX-404',
            'tracking_number' => 'KOM-MIX-404',
            'external_reference' => 'RO-MIX-404',
            'cost' => 12000,
            'currency_code' => 'IDR',
            'metadata' => ['komerce' => ['order_no' => 'RO-MIX-404', 'awb' => 'KOM-MIX-404']],
        ]);
        $komerceShipment->lines()->create([
            'purchasable_type' => $productB->getMorphClass(),
            'purchasable_id' => $productB->id,
            'qty' => 1,
        ]);

        // 1. 404 PDF URL in mixed batch is suppressed (no dead link), thermal labels still render.
        $this->applyTier('komship')
            ->withDeliveryPrintLabelResponse(['path' => '/storage/label/RO-MIX-404.pdf'], 200, pdfFetch404: true)
            ->apply();

        $this->cpanelPrintLabel($admin, $order)
            ->assertOk()
            ->assertViewIs('shopper.shipping.thermal-label')
            ->assertViewHas('providerLabelUrl', null)
            ->assertSee('MANUAL-MIX-404', false)
            ->assertSee('KOM-MIX-404', false)
            ->assertDontSee('RO-MIX-404.pdf', false)
            ->assertDontSee('Buka Label PDF Penyedia', false);

        // 2. Reachable 200 PDF URL in mixed batch is preserved.
        $this->applyTier('komship')
            ->withDeliveryPrintLabelResponse(['path' => '/storage/label/RO-MIX-404.pdf'], 200, pdfFetch404: false)
            ->apply();

        $this->cpanelPrintLabel($admin, $order)
            ->assertOk()
            ->assertViewIs('shopper.shipping.thermal-label')
            ->assertViewHas('providerLabelUrl', KomerceHttpFakeBuilder::DELIVERY_BASE.'/order/storage/label/RO-MIX-404.pdf')
            ->assertSee('Buka Label PDF Penyedia', false);

        // 3. HTTP 500 on PDF URL in mixed batch also suppresses dead link cleanly without 500.
        $this->applyTier('komship', [], false);
        Http::fake([
            KomerceHttpFakeBuilder::DELIVERY_BASE.'/order/api/v1/orders/print-label*' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => ['path' => '/storage/label/RO-MIX-500.pdf'],
            ], 200),
            KomerceHttpFakeBuilder::DELIVERY_BASE.'/order/storage/label/*' => Http::response('Server Error', 500),
        ]);

        $this->cpanelPrintLabel($admin, $order)
            ->assertOk()
            ->assertViewIs('shopper.shipping.thermal-label')
            ->assertViewHas('providerLabelUrl', null)
            ->assertDontSee('RO-MIX-500.pdf', false);
    }
}
