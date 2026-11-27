<?php

declare(strict_types=1);

namespace Tests\Feature\E2E\VendorPackageTier\Tier1;

use Illuminate\Support\Facades\Http;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\OrderShipping;
use Tests\Feature\E2E\VendorPackageTier\Support\VendorPackageTierTestCase;

final class F6FulfillmentAndLabelsTest extends VendorPackageTierTestCase
{
    public function test_komship_sync_carriers_enables_delivery_offered_couriers_only(): void
    {
        $this->applyTier('komship');

        $this->artisan('shipping:sync-carriers')->assertSuccessful();

        $this->assertTrue((bool) Carrier::query()->where('slug', 'jne')->value('is_enabled'));
        $this->assertTrue((bool) Carrier::query()->where('slug', 'jnt')->value('is_enabled'));
        $this->assertTrue((bool) Carrier::query()->where('slug', 'spx')->value('is_enabled'));
        $this->assertTrue((bool) Carrier::query()->where('slug', 'ide')->value('is_enabled'));
        $this->assertFalse((bool) Carrier::query()->where('slug', 'pos')->value('is_enabled'));
        // Manual carrier stays untouched
        $this->assertTrue((bool) Carrier::query()->where('slug', 'manual')->value('is_enabled'));
    }

    public function test_starter_sync_carriers_enables_cost_quoted_couriers_without_calling_delivery(): void
    {
        $this->applyTier('starter');

        $this->artisan('shipping:sync-carriers')->assertSuccessful();

        $this->assertTrue((bool) Carrier::query()->where('slug', 'jne')->value('is_enabled'));
        $this->assertTrue((bool) Carrier::query()->where('slug', 'jnt')->value('is_enabled'));
        $this->assertTrue((bool) Carrier::query()->where('slug', 'pos')->value('is_enabled'));
        $this->assertTrue((bool) Carrier::query()->where('slug', 'spx')->value('is_enabled'));
        $this->assertFalse((bool) Carrier::query()->where('slug', 'ide')->value('is_enabled'));

        $this->assertStringContainsString(
            'no Delivery API',
            (string) data_get(Carrier::query()->where('slug', 'jne')->first()?->metadata, 'sync_reason'),
        );

        Http::assertNotSent(fn ($req): bool => str_contains($req->url(), '/tariff/api/v1/calculate'));
    }

    public function test_komship_auto_fulfill_and_print_label_create_store_order_pickup_awb_and_pdf_redirect(): void
    {
        $this->applyTier('komship');
        $admin = $this->fixtures->createAdmin();
        $customer = $this->fixtures->createVerifiedCustomer();

        ['order' => $order, 'shipment' => $shipment] = $this->fixtures->createOrderWithShipment($customer);

        $this->artisan('shipping:fulfill-paid')->assertSuccessful();

        $shipment->refresh();
        $this->assertSame('JNE-E2E-1', $shipment->awb);
        $this->assertSame('RO-E2E-1', $shipment->external_reference);
        $this->assertSame('RO-E2E-1', data_get($shipment->metadata, 'komerce.order_no'));

        $this->cpanelPrintLabel($admin, $order, $shipment->id)
            ->assertRedirect('https://delivery.example.test/order/storage/label/RO-E2E-1.pdf');

        Http::assertSent(fn ($req): bool => str_contains($req->url(), '/order/api/v1/orders/store')
            && $req->hasHeader('x-api-key', 'delivery-key')
            && $req->method() === 'POST'
            && ($req->data()['shipping'] ?? null) === 'JNE'
            && ($req->data()['shipping_type'] ?? null) === 'JNEFlat'
            && (int) ($req->data()['shipper_destination_id'] ?? 0) === 17248
            && (int) ($req->data()['receiver_destination_id'] ?? 0) === 17601
            && is_array($req->data()['order_details'] ?? null)
            && count($req->data()['order_details']) === 1
            && (int) ($req->data()['order_details'][0]['product_weight'] ?? 0) === 500);
        Http::assertSent(fn ($req): bool => str_contains($req->url(), '/order/api/v1/pickup/request')
            && $req->hasHeader('x-api-key', 'delivery-key')
            && $req->method() === 'POST'
            && ($req->data()['pickup_time'] ?? null) === '10:00:00'
            && ($req->data()['pickup_vehicle'] ?? null) === 'Motor'
            && ($req->data()['orders'][0]['order_no'] ?? null) === 'RO-E2E-1');
        Http::assertSent(fn ($req): bool => str_contains($req->url(), '/order/api/v1/orders/print-label')
            && $req->hasHeader('x-api-key', 'delivery-key')
            && str_contains($req->url(), 'order_no=RO-E2E-1')
            && str_contains($req->url(), 'page=page_5'));
    }

    public function test_starter_and_keyless_cpanel_allow_manual_awb_entry_and_thermal_label(): void
    {
        $this->applyTier('starter');
        $admin = $this->fixtures->createAdmin();
        $customer = $this->fixtures->createVerifiedCustomer();

        ['order' => $order] = $this->fixtures->createOrderWithShipment($customer);

        $this->cpanelOrderFulfillment($admin, $order)
            ->assertSee(__('shopper::pages/orders.create_shipping_label'), false)
            ->assertDontSee('Terbitkan Resi Komerce', false)
            ->call('openShippingLabel')
            ->assertDispatched('openPanel', component: 'shopper-slide-overs.create-shipping-label');

        $this->cpanelCreateShippingLabel($admin, $order)
            ->set('data.tracking_number', 'JNE-MANUAL-1')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas((new OrderShipping)->getTable(), [
            'order_id' => $order->id,
            'tracking_number' => 'JNE-MANUAL-1',
        ]);

        // Keyless manual order prints thermal label cleanly
        $this->applyTier('keyless');
        ['order' => $keylessOrder] = $this->fixtures->createOrderWithShipment(
            $customer,
            [],
            [
                'driver' => 'manual',
                'awb' => 'MANUAL-AWB-KEYLESS',
                'tracking_number' => 'MANUAL-AWB-KEYLESS',
                'metadata' => [],
            ],
        );

        $this->cpanelPrintLabel($admin, $keylessOrder)
            ->assertOk()
            ->assertViewIs('shopper.shipping.thermal-label');

        Http::assertNothingSent();
    }

    public function test_cpanel_komerce_order_shipping_syncs_unpaid_order_and_processes_delivery(): void
    {
        $this->applyTier('komship')
            ->withPaymentStatus('KOMPAY-CPANEL-1', 'PAID', 112000)
            ->apply();

        $admin = $this->fixtures->createAdmin();
        $customer = $this->fixtures->createVerifiedCustomer();

        ['order' => $order, 'shipment' => $shipment] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'price_amount' => 112000,
                'metadata' => ['komerce' => ['payment_ref' => 'KOMPAY-CPANEL-1']],
            ],
            [],
            ['amount' => 112000, 'reference' => 'KOMPAY-CPANEL-1'],
        );

        $this->cpanelOrderShipping($admin, $order)
            ->call('markPaidAndProcessDelivery')
            ->assertSet('overrideError', null);

        $order->refresh();
        $shipment->refresh();
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame('JNE-E2E-1', $shipment->awb);
    }

    public function test_delivery_only_sync_carriers_and_fulfillment_succeed_without_shipping_cost_key(): void
    {
        $this->applyTier('delivery_only');
        $customer = $this->fixtures->createVerifiedCustomer();

        $this->artisan('shipping:sync-carriers')->assertSuccessful();

        $this->assertTrue((bool) Carrier::query()->where('slug', 'jne')->value('is_enabled'));
        $this->assertFalse((bool) Carrier::query()->where('slug', 'pos')->value('is_enabled'));

        ['order' => $order, 'shipment' => $shipment] = $this->fixtures->createOrderWithShipment($customer);

        $this->artisan('shipping:fulfill-paid')->assertSuccessful();

        $this->assertSame('JNE-E2E-1', $shipment->fresh()->awb);
        $this->assertSame('RO-E2E-1', $shipment->fresh()->external_reference);
        Http::assertNotSent(fn ($req): bool => str_contains($req->url(), 'cost.example.test'));
    }
}
