<?php

declare(strict_types=1);

namespace Tests\Feature\E2E\VendorPackageTier\Tier5;

use App\Actions\Shipping\NormalizeShipmentStatus;
use App\Livewire\Shopper\OrderSummary;
use App\Livewire\Shopper\Pages\OrderShipments;
use App\Models\OrderShipment;
use App\Support\OrderShipmentOpsPresenter;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Enum\ShippingStatus;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\OrderShipping;
use Tests\Feature\E2E\VendorPackageTier\Support\VendorPackageTierTestCase;

final class Iteration5AdversarialRemediationTest extends VendorPackageTierTestCase
{
    public function test_bug1_starter_manual_awb_and_legacy_null_driver_remain_printable_after_komship_upgrade(): void
    {
        $this->applyTier('starter');
        $admin = $this->fixtures->createAdmin();
        $customer = $this->fixtures->createVerifiedCustomer();
        $presenter = resolve(OrderShipmentOpsPresenter::class);

        $jneCarrier = $this->fixtures->carriers['jne'];

        // 1. Starter-fulfilled order (driver = 'rajaongkir', manual AWB, external_reference = null)
        ['order' => $starterOrder, 'shipment' => $starterShipment] = $this->fixtures->createOrderWithShipment(
            $customer,
            [],
            [
                'driver' => 'rajaongkir',
                'carrier_id' => $jneCarrier->id,
                'carrier_code' => 'jne',
                'service_code' => 'REG',
                'status' => NormalizeShipmentStatus::LABELED,
                'awb' => 'JNE-STARTER-UPG-1',
                'tracking_number' => 'JNE-STARTER-UPG-1',
                'external_reference' => null,
            ],
        );
        $starterShipping = OrderShipping::query()->create([
            'order_id' => $starterOrder->id,
            'carrier_id' => $jneCarrier->id,
            'shipped_at' => now(),
            'tracking_number' => 'JNE-STARTER-UPG-1',
        ]);

        // 2. Komerce manual-AWB order (driver = 'komerce', external_reference = null)
        ['order' => $komerceManualOrder, 'shipment' => $komerceManualShipment] = $this->fixtures->createOrderWithShipment(
            $customer,
            [],
            [
                'driver' => 'komerce',
                'carrier_id' => $jneCarrier->id,
                'carrier_code' => 'jne',
                'service_code' => 'REG',
                'status' => NormalizeShipmentStatus::LABELED,
                'awb' => 'KOM-MANUAL-UPG-1',
                'tracking_number' => 'KOM-MANUAL-UPG-1',
                'external_reference' => null,
            ],
        );

        // 3. Legacy null-driver order with manual AWB (driver = null, external_reference = null)
        ['order' => $legacyOrder, 'shipment' => $legacyShipment] = $this->fixtures->createOrderWithShipment(
            $customer,
            [],
            [
                'driver' => null,
                'carrier_id' => $jneCarrier->id,
                'carrier_code' => 'jne',
                'service_code' => 'REG',
                'status' => NormalizeShipmentStatus::LABELED,
                'awb' => 'LEGACY-MANUAL-UPG-1',
                'tracking_number' => 'LEGACY-MANUAL-UPG-1',
                'external_reference' => null,
            ],
        );

        $this->assertTrue($presenter->canPrintLabel($starterShipment));
        $this->cpanelPrintLabel($admin, $starterOrder)
            ->assertOk()
            ->assertViewIs('shopper.shipping.thermal-label')
            ->assertSee('JNE-STARTER-UPG-1', false);

        // Upgrade store to Komship (shipping_delivery enabled -> PrintsLabels active)
        $this->applyTier('komship');

        foreach ([
            [$starterOrder, $starterShipment, 'JNE-STARTER-UPG-1'],
            [$komerceManualOrder, $komerceManualShipment, 'KOM-MANUAL-UPG-1'],
            [$legacyOrder, $legacyShipment, 'LEGACY-MANUAL-UPG-1'],
        ] as [$order, $shipment, $awb]) {
            $this->assertTrue($presenter->canPrintLabel($shipment));

            $this->cpanelOrderShipping($admin, $order)
                ->assertSee('Cetak Semua Stiker Resi', false)
                ->assertSee('Cetak Stiker Resi', false);

            // Batch print renders 200 thermal-label without hitting provider print-label API
            $this->cpanelPrintLabel($admin, $order)
                ->assertOk()
                ->assertViewIs('shopper.shipping.thermal-label')
                ->assertSee($awb, false);

            // Single shipment print also renders 200 thermal-label
            $this->cpanelPrintLabel($admin, $order, $shipment->id)
                ->assertOk()
                ->assertViewIs('shopper.shipping.thermal-label')
                ->assertSee($awb, false);
        }

        Livewire::actingAs($admin)
            ->test(OrderShipments::class, ['order' => $starterOrder])
            ->assertTableActionVisible('printProviderLabel', $starterShipping);

        Http::assertNothingSent();

        // Negative cases still return canPrintLabel() === false and HTTP 422
        ['order' => $blankOrder, 'shipment' => $blankShipment] = $this->fixtures->createOrderWithShipment(
            $customer,
            [],
            ['driver' => 'rajaongkir', 'status' => NormalizeShipmentStatus::PENDING, 'awb' => null, 'tracking_number' => null],
        );
        $this->assertFalse($presenter->canPrintLabel($blankShipment));
        $this->cpanelPrintLabel($admin, $blankOrder)->assertStatus(422)->assertViewIs('shopper.shipping.label-error');

        ['order' => $cancelledOrder, 'shipment' => $cancelledShipment] = $this->fixtures->createOrderWithShipment(
            $customer,
            [],
            ['driver' => 'rajaongkir', 'status' => NormalizeShipmentStatus::CANCELLED, 'awb' => 'JNE-CANC-99', 'tracking_number' => 'JNE-CANC-99'],
        );
        $this->assertFalse($presenter->canPrintLabel($cancelledShipment));
        $this->cpanelPrintLabel($admin, $cancelledOrder)->assertStatus(422)->assertViewIs('shopper.shipping.label-error');

        Carrier::query()->create(['name' => 'Acme', 'slug' => 'acme-exp', 'driver' => 'acme', 'is_enabled' => true]);
        ['order' => $unregisteredOrder, 'shipment' => $unregisteredShipment] = $this->fixtures->createOrderWithShipment(
            $customer,
            [],
            ['driver' => 'acme', 'carrier_code' => 'acme-exp', 'status' => NormalizeShipmentStatus::LABELED, 'awb' => 'ACME-99', 'tracking_number' => 'ACME-99'],
        );
        $this->assertFalse($presenter->canPrintLabel($unregisteredShipment));
        $this->cpanelPrintLabel($admin, $unregisteredOrder)->assertStatus(422)->assertViewIs('shopper.shipping.label-error');
    }

    public function test_bug2_zero_cost_replacement_shipment_alongside_cancelled_shipment_matches_across_cpanel_web_and_api(): void
    {
        $this->applyTier('komship');
        $admin = $this->fixtures->createAdmin();
        $customer = $this->fixtures->createVerifiedCustomer();
        $product = $this->fixtures->createStockedProduct(100000, 10, 500, $this->fixtures->inventory);

        ['order' => $order] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::Processing,
                'payment_status' => PaymentStatus::Paid,
                'shipping_option_id' => null,
                'price_amount' => 112000,
            ],
            [
                'driver' => 'komerce',
                'status' => NormalizeShipmentStatus::CANCELLED,
                'awb' => null,
                'tracking_number' => null,
                'cost' => 12000,
                'external_reference' => 'RO-CANC-COST-1',
            ],
            null,
            $product,
        );

        $replacement = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $this->fixtures->inventory->id,
            'driver' => 'manual',
            'carrier_code' => 'manual',
            'carrier_name' => 'Kurir Toko',
            'service_code' => 'Reguler',
            'service_name' => 'Reguler',
            'status' => NormalizeShipmentStatus::LABELED,
            'awb' => 'MANUAL-ZERO-COST-1',
            'tracking_number' => 'MANUAL-ZERO-COST-1',
            'cost' => 0,
            'currency_code' => 'IDR',
        ]);
        $replacement->lines()->create([
            'purchasable_type' => $product->getMorphClass(),
            'purchasable_id' => $product->id,
            'qty' => 1,
        ]);

        // 1. Cpanel OrderSummary
        Livewire::actingAs($admin)
            ->test(OrderSummary::class, ['order' => $order->fresh()])
            ->assertViewHas('shippingPrice', 12000.0)
            ->assertSee('12.000', false);

        // 2. Web Storefront Account\OrderController::show
        $this->withoutVite();
        $this->webShowOrder($customer, $order->fresh())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.subtotal', 100000)
                ->where('summary.shipping', 12000)
                ->where('summary.tax_inclusive', true)
                ->where('summary.total', 112000));

        // 3. Mobile API Api\V1\OrderController::show
        $this->apiShowOrder($customer, $order->number)
            ->assertOk()
            ->assertJsonPath('data.totals.subtotal', 100000)
            ->assertJsonPath('data.totals.shipping', 12000)
            ->assertJsonPath('data.totals.adjustment', 0)
            ->assertJsonPath('data.totals.total', 112000)
            ->assertJsonPath('data.breakdown.shipping', 12000)
            ->assertJsonPath('data.breakdown.tax_inclusive', true)
            ->assertJsonPath('data.breakdown.total', 112000);
    }

    public function test_bug3_split_warehouse_mixed_manual_and_komerce_bulk_print_renders_all_active_labels_without_dropping_manual(): void
    {
        $this->applyTier('komship');
        $admin = $this->fixtures->createAdmin();
        $customer = $this->fixtures->createVerifiedCustomer();
        $productA = $this->fixtures->createStockedProduct(50000, 10, 500, $this->fixtures->inventory);
        $secondInventory = Inventory::factory()->create([
            'name' => 'Gudang Surabaya',
            'shipping_origins' => ['komerce' => '502', 'rajaongkir' => '502'],
        ]);
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
            'awb' => 'MANUAL-MIX-1',
            'tracking_number' => 'MANUAL-MIX-1',
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
            'awb' => 'KOM-AWB-2',
            'tracking_number' => 'KOM-AWB-2',
            'external_reference' => 'RO-E2E-1',
            'cost' => 12000,
            'currency_code' => 'IDR',
            'metadata' => ['komerce' => ['order_no' => 'RO-E2E-1', 'awb' => 'KOM-AWB-2']],
        ]);
        $komerceShipment->lines()->create([
            'purchasable_type' => $productB->getMorphClass(),
            'purchasable_id' => $productB->id,
            'qty' => 1,
        ]);

        // Cancelled shipment must NOT be included in mixed bulk label output
        OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $secondInventory->id,
            'driver' => 'komerce',
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'status' => NormalizeShipmentStatus::CANCELLED,
            'awb' => 'CANC-DROP-3',
            'tracking_number' => 'CANC-DROP-3',
            'cost' => 0,
            'currency_code' => 'IDR',
        ]);

        $this->cpanelOrderShipping($admin, $order)
            ->assertViewHas('printableShipmentCount', 2)
            ->assertSee('Cetak Semua Stiker Resi', false);

        // Bulk print (?shipment=null) renders 200 thermal-label with BOTH MANUAL-MIX-1 and KOM-AWB-2 + provider PDF link
        $bulkResponse = $this->cpanelPrintLabel($admin, $order)
            ->assertOk()
            ->assertViewIs('shopper.shipping.thermal-label')
            ->assertSee('MANUAL-MIX-1', false)
            ->assertSee('KOM-AWB-2', false)
            ->assertSee('https://delivery.example.test/order/storage/label/RO-E2E-1.pdf', false)
            ->assertDontSee('CANC-DROP-3', false);

        $this->assertSame(2, substr_count($bulkResponse->getContent(), 'class="label-container"'));

        // Single-shipment print for the Komerce parcel preserves 302 redirect to provider PDF
        $this->cpanelPrintLabel($admin, $order, $komerceShipment->id)
            ->assertRedirect('https://delivery.example.test/order/storage/label/RO-E2E-1.pdf');

        // Single-shipment print for the manual parcel renders 200 thermal-label for MANUAL-MIX-1
        $singleManual = $this->cpanelPrintLabel($admin, $order, $manualShipment->id)
            ->assertOk()
            ->assertViewIs('shopper.shipping.thermal-label')
            ->assertSee('MANUAL-MIX-1', false);
        $this->assertSame(1, substr_count($singleManual->getContent(), 'class="label-container"'));

        // Even if the provider PDF API fails with 500, mixed bulk print still renders 200 thermal-label with both parcels
        $this->applyTier('komship')
            ->withDeliveryPrintLabelResponse(['message' => 'Provider error'], 500)
            ->apply();

        $resilientBulk = $this->cpanelPrintLabel($admin, $order)
            ->assertOk()
            ->assertViewIs('shopper.shipping.thermal-label')
            ->assertSee('MANUAL-MIX-1', false)
            ->assertSee('KOM-AWB-2', false);
        $this->assertSame(2, substr_count($resilientBulk->getContent(), 'class="label-container"'));

        // Starter manual-AWB (external_reference = null) + Komerce (external_reference != null) also prints both
        $this->applyTier('komship');
        $manualShipment->forceFill([
            'driver' => 'rajaongkir',
            'carrier_code' => 'jne',
            'awb' => 'RAJA-MIX-1',
            'tracking_number' => 'RAJA-MIX-1',
        ])->save();

        $starterPlusKomerceBulk = $this->cpanelPrintLabel($admin, $order)
            ->assertOk()
            ->assertViewIs('shopper.shipping.thermal-label')
            ->assertSee('RAJA-MIX-1', false)
            ->assertSee('KOM-AWB-2', false);
        $this->assertSame(2, substr_count($starterPlusKomerceBulk->getContent(), 'class="label-container"'));
    }

    public function test_bug4_komship_manual_blank_awb_parcel_hides_spurious_pickup_button(): void
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
                'driver' => 'manual',
                'carrier_code' => 'manual',
                'carrier_name' => 'Kurir Toko',
                'service_code' => 'Reguler',
                'status' => NormalizeShipmentStatus::LABELED,
                'awb' => null,
                'tracking_number' => null,
                'external_reference' => null,
            ],
        );

        $this->cpanelOrderShipping($admin, $order)
            ->assertViewHas('hasUnprocessedShipment', false)
            ->assertDontSee('processAllDeliveryOrders', false)
            ->assertDontSee('processDeliveryOrder(', false)
            ->assertDontSee('Daftarkan & Pickup Komerce', false)
            ->assertSee('Input via Kartu Fulfillment', false);
    }
}
