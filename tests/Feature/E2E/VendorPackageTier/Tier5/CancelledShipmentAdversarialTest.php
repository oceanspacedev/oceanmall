<?php

declare(strict_types=1);

namespace Tests\Feature\E2E\VendorPackageTier\Tier5;

use App\Actions\Shipping\IssueOrderFulfillment;
use App\Models\OrderShipment;
use App\Shipping\ShippingCapabilities;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\OrderShipping;
use Tests\Feature\E2E\VendorPackageTier\Support\KomerceHttpFakeBuilder;
use Tests\Feature\E2E\VendorPackageTier\Support\VendorPackageTierTestCase;

final class CancelledShipmentAdversarialTest extends VendorPackageTierTestCase
{
    public function test_issue_order_fulfillment_ignores_cancelled_parcel_awb_when_active_parcel_fails(): void
    {
        $this->applyTier('komship')
            ->withRoute(
                KomerceHttpFakeBuilder::DELIVERY_BASE.'/order/api/v1/orders/store*',
                Http::response(['meta' => ['code' => 500, 'status' => 'error', 'message' => 'Delivery upstream error']], 500),
            )
            ->apply();

        $customer = $this->fixtures->createVerifiedCustomer();
        ['order' => $order, 'shipment' => $cancelledShipment] = $this->fixtures->createOrderWithShipment(
            $customer,
            [],
            [
                'driver' => 'komerce',
                'status' => 'cancelled',
                'awb' => 'OLD-CANCELLED-AWB',
                'tracking_number' => 'OLD-CANCELLED-AWB',
                'external_reference' => 'RO-CANCELLED-1',
                'metadata' => ['komerce' => ['order_no' => 'RO-CANCELLED-1', 'awb' => 'OLD-CANCELLED-AWB']],
            ],
        );

        $activeShipment = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $this->fixtures->inventory->id,
            'carrier_id' => $this->fixtures->carriers['jne']->id ?? null,
            'driver' => 'komerce',
            'carrier_code' => 'jne',
            'carrier_name' => 'JNE Express',
            'service_code' => 'JNEFlat',
            'service_name' => 'JNEFlat',
            'cost' => 12000,
            'currency_code' => 'IDR',
            'status' => 'pending',
            'metadata' => $cancelledShipment->metadata['rate'] ?? [] ? ['rate' => $cancelledShipment->metadata['rate']] : [],
        ]);
        $activeShipment->lines()->create([
            'purchasable_type' => $cancelledShipment->lines()->first()->purchasable_type,
            'purchasable_id' => $cancelledShipment->lines()->first()->purchasable_id,
            'qty' => 1,
        ]);

        try {
            resolve(IssueOrderFulfillment::class)->handle($order->fresh());
            $this->fail('Expected IssueOrderFulfillment to throw when the active shipment fails, without leaking the cancelled AWB.');
        } catch (RuntimeException $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        Http::assertNotSent(fn ($req): bool => str_contains($req->url(), '/order/api/v1/orders/detail'));
        $this->assertSame('OLD-CANCELLED-AWB', $cancelledShipment->fresh()->awb);
    }

    public function test_issue_order_fulfillment_skips_cancelled_external_ref_when_active_shipment_is_manual(): void
    {
        $this->applyTier('komship');
        $customer = $this->fixtures->createVerifiedCustomer();

        ['order' => $order, 'shipment' => $cancelled] = $this->fixtures->createOrderWithShipment(
            $customer,
            [],
            [
                'driver' => 'komerce',
                'status' => 'cancelled',
                'awb' => null,
                'tracking_number' => null,
                'external_reference' => 'RO-CANCELLED-NO-AWB',
                'metadata' => ['komerce' => ['order_no' => 'RO-CANCELLED-NO-AWB']],
            ],
        );

        OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $this->fixtures->inventory->id,
            'driver' => 'manual',
            'carrier_code' => 'manual',
            'service_code' => 'Reguler',
            'cost' => 15000,
            'currency_code' => 'IDR',
            'status' => 'pending',
        ]);

        try {
            resolve(IssueOrderFulfillment::class)->handle($order->fresh());
            $this->fail('Expected IssueOrderFulfillment to throw when only manual active shipment exists without AWB.');
        } catch (RuntimeException $e) {
            $this->assertSame('Penyedia pengiriman belum mengembalikan nomor resi.', $e->getMessage());
            $this->assertStringNotContainsString('Pickup sudah diajukan ke kurir', $e->getMessage());
        }

        Http::assertNothingSent();
        $this->assertNull($cancelled->fresh()->awb);
    }

    public function test_issue_order_fulfillment_handles_all_cancelled_shipments_cleanly(): void
    {
        $this->applyTier('komship');
        $customer = $this->fixtures->createVerifiedCustomer();

        ['order' => $order] = $this->fixtures->createOrderWithShipment(
            $customer,
            [],
            [
                'driver' => 'komerce',
                'status' => 'cancelled',
                'awb' => 'OLD-AWB',
                'external_reference' => 'RO-OLD-1',
            ],
        );

        try {
            resolve(IssueOrderFulfillment::class)->handle($order->fresh());
            $this->fail('Expected RuntimeException when all shipments are cancelled.');
        } catch (RuntimeException $e) {
            $this->assertSame('Semua pengiriman pada pesanan ini sudah dibatalkan.', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_fulfiller_for_order_and_cpanel_manual_form_ignore_cancelled_provider_shipment(): void
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
                'status' => 'cancelled',
                'awb' => 'OLD-KOM-AWB',
                'external_reference' => 'RO-CANCEL-UI',
            ],
        );

        OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $this->fixtures->inventory->id,
            'driver' => 'manual',
            'carrier_code' => 'manual',
            'service_code' => 'Reguler',
            'cost' => 15000,
            'currency_code' => 'IDR',
            'status' => 'pending',
        ]);

        $this->assertNull(resolve(ShippingCapabilities::class)->fulfillerForOrder($order->fresh()));

        $fulfillment = $this->cpanelOrderFulfillment($admin, $order->fresh());
        $this->assertTrue($fulfillment->instance()->hasUnfulfilledItems());
        $fulfillment
            ->assertSee(__('shopper::pages/orders.create_shipping_label'), false)
            ->assertDontSee('Terbitkan Resi Komerce', false);

        $this->cpanelCreateShippingLabel($admin, $order->fresh())
            ->set('data.tracking_number', 'MANUAL-AFTER-CANCEL-1')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas((new OrderShipping)->getTable(), [
            'order_id' => $order->id,
            'tracking_number' => 'MANUAL-AFTER-CANCEL-1',
        ]);
    }

    public function test_cpanel_order_fulfillment_hides_issue_awb_button_when_all_shipments_are_cancelled(): void
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
                'status' => 'cancelled',
                'awb' => null,
                'external_reference' => null,
            ],
        );

        $this->cpanelOrderFulfillment($admin, $order->fresh())
            ->assertSee('Semua pengiriman pada pesanan ini sudah dibatalkan.', false)
            ->assertDontSee('Terbitkan Resi Komerce', false);
    }

    public function test_web_and_api_order_detail_suppress_can_track_and_exclude_cancelled_shipping_cost(): void
    {
        foreach (['starter', 'komship'] as $tier) {
            $this->applyTier($tier);
            $customer = $this->fixtures->createVerifiedCustomer();

            ['order' => $order] = $this->fixtures->createOrderWithShipment(
                $customer,
                [
                    'price_amount' => 115000,
                    'status' => OrderStatus::Processing,
                    'payment_status' => PaymentStatus::Paid,
                ],
                [
                    'driver' => 'rajaongkir',
                    'status' => 'cancelled',
                    'awb' => 'JNE-CANCELLED-TRK',
                    'tracking_number' => 'JNE-CANCELLED-TRK',
                    'cost' => 15000,
                ],
            );

            OrderShipment::query()->create([
                'order_id' => $order->id,
                'inventory_id' => $this->fixtures->inventory->id,
                'carrier_id' => $this->fixtures->carriers['jne']->id ?? null,
                'driver' => 'rajaongkir',
                'carrier_code' => 'jne',
                'carrier_name' => 'JNE Express',
                'service_code' => 'REG',
                'service_name' => 'REG',
                'status' => 'labeled',
                'awb' => 'JNE-ACTIVE-TRK',
                'tracking_number' => 'JNE-ACTIVE-TRK',
                'cost' => 15000,
                'currency_code' => 'IDR',
            ]);

            $apiResponse = $this->apiShowOrder($customer, $order->number)->assertOk();
            $apiShipments = $apiResponse->json('data.shipments');
            $this->assertFalse($apiShipments[0]['can_track']);
            $this->assertTrue($apiShipments[1]['can_track']);
            $this->assertSame(15000, $apiResponse->json('data.totals.shipping'));
            $this->assertSame(15000, $apiResponse->json('data.breakdown.shipping'));
            $this->assertTrue($apiResponse->json('data.breakdown.tax_inclusive'));

            $webResponse = $this->webShowOrder($customer, $order)->assertOk();
            $webProps = $webResponse->viewData('page')['props'];
            $this->assertFalse($webProps['shipments'][0]['can_track']);
            $this->assertTrue($webProps['shipments'][1]['can_track']);
            $this->assertSame(15000, $webProps['summary']['shipping']);
            $this->assertTrue($webProps['summary']['tax_inclusive']);
        }
    }
}
