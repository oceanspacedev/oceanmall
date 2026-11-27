<?php

declare(strict_types=1);

namespace Tests\Feature\E2E\VendorPackageTier\Tier2;

use App\Shipping\ShippingCapabilities;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Enum\ShippingStatus;
use Shopper\Core\Models\Carrier;
use Tests\Feature\E2E\VendorPackageTier\Support\VendorPackageTierTestCase;

final class F6FulfillmentAndLabelsBoundaryTest extends VendorPackageTierTestCase
{
    public function test_sync_carriers_dry_run_and_quota_exhausted_cost_fail_cleanly_without_corrupting_db(): void
    {
        // Dry run keeps DB untouched
        $this->applyTier('komship');
        Carrier::query()->where('slug', 'pos')->update(['is_enabled' => true]);

        $this->artisan('shipping:sync-carriers', ['--dry-run' => true])->assertSuccessful();
        $this->assertTrue((bool) Carrier::query()->where('slug', 'pos')->value('is_enabled'));

        // Quota-exhausted Cost tier fails cleanly and sends ZERO domestic-cost requests
        $this->applyTier('quota_exhausted');
        Carrier::query()->where('slug', 'jne')->update(['is_enabled' => true]);

        $this->artisan('shipping:sync-carriers')->assertFailed();
        $this->assertTrue((bool) Carrier::query()->where('slug', 'jne')->value('is_enabled'));

        Http::assertNothingSent();
    }

    public function test_cpanel_starter_mode_displays_accurate_status_badges_and_print_buttons_after_manual_awb(): void
    {
        $this->applyTier('starter');
        $admin = $this->fixtures->createAdmin();
        $customer = $this->fixtures->createVerifiedCustomer();

        ['order' => $order] = $this->fixtures->createOrderWithShipment(
            $customer,
            [],
            [
                'awb' => 'JNE-STARTER-MANUAL-1',
                'tracking_number' => 'JNE-STARTER-MANUAL-1',
                'status' => 'labeled',
                'metadata' => [
                    'rate' => ['provider' => 'rajaongkir', 'carrier_code' => 'jne', 'service_name' => 'REG'],
                ],
            ],
        );

        $this->cpanelOrderShipping($admin, $order)
            ->assertDontSee('Sebagian Terdaftar', false)
            ->assertDontSee('Belum Terdaftar', false)
            ->assertSee('Cetak Semua Stiker Resi', false)
            ->assertSee('Cetak Stiker Resi', false);

        $this->cpanelPrintLabel($admin, $order)
            ->assertOk()
            ->assertViewIs('shopper.shipping.thermal-label');
    }

    public function test_store_order_timeout_sets_anti_duplicate_guard_and_blocks_blind_repost(): void
    {
        $this->applyTier('komship')
            ->withDeliveryStoreOrderTimeout()
            ->apply();

        $customer = $this->fixtures->createVerifiedCustomer();
        ['shipment' => $shipment] = $this->fixtures->createOrderWithShipment($customer);

        $fulfiller = resolve(ShippingCapabilities::class)->fulfillerFor($shipment);
        $this->assertNotNull($fulfiller);

        try {
            $fulfiller->fulfillShipment($shipment);
            $this->fail('Expected timeout exception on first Store Order call.');
        } catch (\Throwable $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        $shipment->refresh();
        $this->assertNotNull(data_get($shipment->metadata, $this->storeOrderPendingMetadataKey()));

        // Second attempt must throw anti-duplicate guard error and send ZERO Store Order POSTs
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake();

        try {
            $fulfiller->fulfillShipment($shipment);
            $this->fail('Expected duplicate-guard RuntimeException on second call.');
        } catch (\Throwable $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_print_label_handles_sandbox_404_with_thermal_fallback_and_handles_runtime_error_safely(): void
    {
        $this->applyTier('komship')
            ->withDeliveryPrintLabelResponse(['path' => '/storage/label/RO-404.pdf'], 200, pdfFetch404: true)
            ->apply();

        $admin = $this->fixtures->createAdmin();
        $customer = $this->fixtures->createVerifiedCustomer();

        ['order' => $order404, 'shipment' => $shipment404] = $this->fixtures->createOrderWithShipment(
            $customer,
            [],
            [
                'awb' => 'JNE-404-1',
                'tracking_number' => 'JNE-404-1',
                'external_reference' => 'RO-404',
                'status' => 'labeled',
                'metadata' => [
                    'komerce' => [
                        'order_no' => 'RO-404',
                        'pickup_response' => ['meta' => ['status' => 'success']],
                    ],
                ],
            ],
        );

        // Sandbox 404 on PDF URL falls back cleanly to thermal-label view
        $this->cpanelPrintLabel($admin, $order404, $shipment404->id)
            ->assertOk()
            ->assertViewIs('shopper.shipping.thermal-label');

        // Unregistered shipment (no order_no, no AWB) returns session error 'label', never 500
        ['order' => $unregistered] = $this->fixtures->createOrderWithShipment($customer);
        $this->cpanelPrintLabel($admin, $unregistered)
            ->assertStatus(422)
            ->assertViewIs('shopper.shipping.label-error')
            ->assertSee(route('shopper.orders.detail', $unregistered), false)
            ->assertSessionHasErrors('label');
    }

    public function test_delivery_revocation_401_switches_fulfillment_to_manual_and_clears_stale_error_state(): void
    {
        $this->applyTier('komship')
            ->withDeliveryEntitled(false, 401, 'Invalid API Key')
            ->apply();

        $admin = $this->fixtures->createAdmin();
        $customer = $this->fixtures->createVerifiedCustomer();

        ['order' => $order, 'shipment' => $shipment] = $this->fixtures->createOrderWithShipment($customer);

        // Attempt Delivery fulfillment -> Delivery returns 401 and revokes shipping_delivery entitlement
        $this->cpanelOrderShipping($admin, $order)
            ->call('processAllDeliveryOrders');

        $this->assertFalse($this->komerceProductEnabled('shipping_delivery'));

        // Subsequent OrderFulfillment render degrades cleanly to Shopper manual label button
        $this->cpanelOrderFulfillment($admin, $order)
            ->assertSee(__('shopper::pages/orders.create_shipping_label'), false)
            ->assertDontSee('Terbitkan Resi Komerce', false)
            ->call('openShippingLabel')
            ->assertDispatched('openPanel', component: 'shopper-slide-overs.create-shipping-label');

        // After manual AWB is saved, stale fulfillment_error banner must not linger
        $this->cpanelCreateShippingLabel($admin, $order)
            ->set('data.tracking_number', 'JNE-FALLBACK-MANUAL-1')
            ->call('save')
            ->assertHasNoErrors();

        $shipment->refresh();
        $this->assertSame('JNE-FALLBACK-MANUAL-1', $shipment->tracking_number);
        $this->assertNull(data_get($shipment->metadata, 'komerce.fulfillment_error'));
    }

    public function test_cancel_order_calls_delivery_cancel_only_when_delivery_order_exists_and_skips_when_disabled(): void
    {
        $customer = $this->fixtures->createVerifiedCustomer();

        // Komship with external Delivery order_no -> calls PUT /orders/cancel
        $this->applyTier('komship');
        ['order' => $komshipOrder] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'payment_method_id' => $this->fixtures->paymentMethods['manual']->id,
                'shipping_status' => ShippingStatus::Unfulfilled,
                'metadata' => [],
            ],
            [
                'external_reference' => 'RO-CANCEL-1',
                'metadata' => ['komerce' => ['order_no' => 'RO-CANCEL-1']],
            ],
            null,
        );

        $this->apiCancelOrder($customer, $komshipOrder->number)->assertOk();
        Http::assertSent(fn ($req): bool => str_contains($req->url(), '/order/api/v1/orders/cancel')
            && $req->hasHeader('x-api-key', 'delivery-key')
            && $req->method() === 'PUT'
            && ($req->data()['order_no'] ?? null) === 'RO-CANCEL-1');

        // Without Delivery order_no (or when komerce.enabled = false) -> zero Delivery cancel calls
        $this->applyTier('keyless');
        ['order' => $manualOrder] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'shipping_status' => ShippingStatus::Unfulfilled,
            ],
            [
                'external_reference' => null,
                'metadata' => [],
            ],
            null,
        );

        $this->apiCancelOrder($customer, $manualOrder->number)->assertOk();
        Http::assertNothingSent();
    }
}
