<?php

declare(strict_types=1);

namespace Tests\Feature\E2E\VendorPackageTier\Tier5;

use App\Actions\Shipping\DispatchShipmentFulfillment;
use App\Actions\Shipping\IssueOrderFulfillment;
use App\Actions\Shipping\NormalizeShipmentStatus;
use App\Actions\Shipping\PrintShipmentLabels;
use App\Actions\Shipping\ReshipOrderShipment;
use App\Actions\Shipping\SyncOrderShippingFromShipments;
use App\Integrations\Komerce\Services\KomerceDeliveryFulfillment;
use App\Shipping\ShipmentFulfillment;
use App\Shipping\ShipmentMetadata;
use App\Support\OrderShipmentOpsPresenter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Enum\ShippingStatus;
use Tests\Feature\E2E\VendorPackageTier\Support\KomerceHttpFakeBuilder;
use Tests\Feature\E2E\VendorPackageTier\Support\VendorPackageTierTestCase;

final class Iteration10AdversarialRemediationTest extends VendorPackageTierTestCase
{
    public function test_bug_1_reshipped_zero_cost_shipment_fulfills_under_komship_and_delivery_only_tiers(): void
    {
        $this->requireModules('Komerce');

        $admin = $this->fixtures->createAdmin();
        $customer = $this->fixtures->createVerifiedCustomer();

        foreach (['komship', 'delivery_only'] as $tier) {
            $expectedOrderNo = 'KOM-RESHIP-'.strtoupper($tier);
            $expectedAwb = 'AWB-RESHIP-'.strtoupper($tier);

            $this->applyTier($tier, [], false)
                ->withDeliveryStoreOrderResponse([
                    'order_id' => 30001,
                    'order_no' => $expectedOrderNo,
                ])
                ->withDeliveryPickupResponse([[
                    'status' => 'success',
                    'order_no' => $expectedOrderNo,
                    'awb' => $expectedAwb,
                ]])
                ->withDeliveryPrintLabelResponse([
                    'path' => "/storage/label/{$expectedOrderNo}.pdf",
                ])
                ->apply();

            ['order' => $orderA, 'shipment' => $s1A] = $this->fixtures->createOrderWithShipment(
                $customer,
                [
                    'number' => 'ORD-RESHIP-ISSUE-'.strtoupper($tier),
                    'status' => OrderStatus::Processing,
                    'payment_status' => PaymentStatus::Paid,
                    'shipping_status' => ShippingStatus::Returned,
                    'price_amount' => 125000,
                ],
                [
                    'driver' => 'komerce',
                    'carrier_id' => $this->fixtures->carriers['jne']->id,
                    'carrier_code' => 'jne',
                    'carrier_name' => 'JNE Express',
                    'service_code' => 'REG',
                    'service_name' => 'REG',
                    'status' => NormalizeShipmentStatus::RETURNED,
                    'awb' => 'OLD-AWB-ISSUE-'.$tier,
                    'tracking_number' => 'OLD-AWB-ISSUE-'.$tier,
                    'external_reference' => 'KOM-OLD-ISSUE-'.$tier,
                    'cost' => 25000,
                    'metadata' => [
                        'rate' => [
                            'provider' => 'shipping_delivery',
                            'shipping_name' => 'JNE',
                            'service_name' => 'REG',
                            'shipping_cost' => 25000,
                            'shipping_cashback' => 1000,
                            'service_fee' => 0,
                            'additional_cost' => 0,
                            'grandtotal' => 125000,
                            'cod_value' => 0,
                            'insurance_value' => 0,
                            'carrier_code' => 'jne',
                        ],
                    ],
                ],
            );

            $s2A = resolve(ReshipOrderShipment::class)->handle($s1A->fresh(), $admin);
            $this->assertSame(0, $s2A->cost);
            $this->assertSame($s1A->id, data_get($s2A->metadata, ShipmentMetadata::RESHIP_OF_SHIPMENT_ID));
            $this->assertSame($s2A->id, data_get($s1A->fresh()->metadata, ShipmentMetadata::RESHIPPED_TO_SHIPMENT_ID));

            $result = resolve(IssueOrderFulfillment::class)->handle($orderA->fresh());
            $this->assertSame([$expectedAwb], $result['awbs']);

            $s2AFresh = $s2A->fresh();
            $this->assertSame($expectedAwb, $s2AFresh->awb);
            $this->assertSame($expectedOrderNo, $s2AFresh->external_reference);
            $this->assertSame(0, $s2AFresh->cost);

            Http::assertSent(static function (Request $request): bool {
                return str_contains($request->url(), '/order/api/v1/orders/store')
                    && (int) ($request->data()['shipping_cost'] ?? 0) === 25000;
            });

            // Also verify KomerceOrderShipping::processDeliveryOrder() on a second reshipped order.
            $cardOrderNo = 'KOM-CARD-'.strtoupper($tier);
            $cardAwb = 'AWB-CARD-'.strtoupper($tier);
            KomerceHttpFakeBuilder::forTier($tier)
                ->withDeliveryStoreOrderResponse([
                    'order_id' => 30002,
                    'order_no' => $cardOrderNo,
                ])
                ->withDeliveryPickupResponse([[
                    'status' => 'success',
                    'order_no' => $cardOrderNo,
                    'awb' => $cardAwb,
                ]])
                ->withDeliveryPrintLabelResponse([
                    'path' => "/storage/label/{$cardOrderNo}.pdf",
                ])
                ->apply();

            ['order' => $orderB, 'shipment' => $s1B] = $this->fixtures->createOrderWithShipment(
                $customer,
                [
                    'number' => 'ORD-RESHIP-CARD-'.strtoupper($tier),
                    'status' => OrderStatus::Processing,
                    'payment_status' => PaymentStatus::Paid,
                    'shipping_status' => ShippingStatus::Returned,
                    'price_amount' => 125000,
                ],
                [
                    'driver' => 'komerce',
                    'carrier_id' => $this->fixtures->carriers['jne']->id,
                    'carrier_code' => 'jne',
                    'carrier_name' => 'JNE Express',
                    'service_code' => 'REG',
                    'service_name' => 'REG',
                    'status' => NormalizeShipmentStatus::RETURNED,
                    'awb' => 'OLD-AWB-CARD-'.$tier,
                    'tracking_number' => 'OLD-AWB-CARD-'.$tier,
                    'external_reference' => 'KOM-OLD-CARD-'.$tier,
                    'cost' => 25000,
                    'metadata' => [
                        'rate' => [
                            'provider' => 'shipping_delivery',
                            'shipping_name' => 'JNE',
                            'service_name' => 'REG',
                            'shipping_cost' => 25000,
                            'shipping_cashback' => 1000,
                            'service_fee' => 0,
                            'additional_cost' => 0,
                            'grandtotal' => 125000,
                            'cod_value' => 0,
                            'insurance_value' => 0,
                            'carrier_code' => 'jne',
                        ],
                    ],
                ],
            );

            $s2B = resolve(ReshipOrderShipment::class)->handle($s1B->fresh(), $admin);

            $card = $this->cpanelOrderShipping($admin, $orderB->fresh())
                ->call('processDeliveryOrder', $s2B->id)
                ->assertSet('overrideError', null);

            $this->assertNotNull($card->get('successMessage'));
            $s2BFresh = $s2B->fresh();
            $this->assertSame($cardAwb, $s2BFresh->awb);
            $this->assertSame($cardOrderNo, $s2BFresh->external_reference);
            $this->assertSame(0, $s2BFresh->cost);
        }
    }

    public function test_bug_1_strict_cost_tolerance_still_blocks_non_reship_or_mismatched_source_cost(): void
    {
        $this->requireModules('Komerce');
        $this->applyTier('komship');

        $admin = $this->fixtures->createAdmin();
        $customer = $this->fixtures->createVerifiedCustomer();

        // 1. Non-reship shipment with tampered cost = 0 must still fail strict tolerance check.
        ['shipment' => $tampered] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'number' => 'ORD-TAMPER-NONRESHIP',
                'status' => OrderStatus::Processing,
                'payment_status' => PaymentStatus::Paid,
                'shipping_status' => ShippingStatus::Unfulfilled,
                'price_amount' => 125000,
            ],
            [
                'driver' => 'komerce',
                'cost' => 0,
                'metadata' => [
                    'rate' => [
                        'provider' => 'shipping_delivery',
                        'shipping_name' => 'JNE',
                        'service_name' => 'REG',
                        'shipping_cost' => 25000,
                        'shipping_cashback' => 0,
                        'service_fee' => 0,
                        'additional_cost' => 0,
                        'grandtotal' => 125000,
                    ],
                ],
            ],
        );

        $fulfillment = resolve(KomerceDeliveryFulfillment::class);

        try {
            $fulfillment->storeOrderPayload($tampered->fresh(['order.shippingAddress', 'order.items', 'order.customer', 'inventory', 'lines.purchasable']));
            $this->fail('Expected RuntimeException for non-reship zero-cost shipment.');
        } catch (RuntimeException $e) {
            $this->assertSame('Shipment cost does not match the persisted Shipping Delivery rate.', $e->getMessage());
        }

        // 2. Forged reship_of_shipment_id pointing to a nonexistent shipment must fail strict tolerance check.
        $tampered->forceFill([
            'metadata' => array_merge($tampered->metadata ?? [], [
                ShipmentMetadata::RESHIP_OF_SHIPMENT_ID => 999999,
            ]),
        ])->save();

        try {
            $fulfillment->storeOrderPayload($tampered->fresh(['order.shippingAddress', 'order.items', 'order.customer', 'inventory', 'lines.purchasable']));
            $this->fail('Expected RuntimeException for forged reship source.');
        } catch (RuntimeException $e) {
            $this->assertSame('Shipment cost does not match the persisted Shipping Delivery rate.', $e->getMessage());
        }

        // 3. Genuine reship whose rate shipping_cost diverges from source shipment cost must still fail.
        ['shipment' => $source] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'number' => 'ORD-RESHIP-MISMATCH',
                'status' => OrderStatus::Processing,
                'payment_status' => PaymentStatus::Paid,
                'shipping_status' => ShippingStatus::Returned,
                'price_amount' => 110000,
            ],
            [
                'driver' => 'komerce',
                'status' => NormalizeShipmentStatus::RETURNED,
                'awb' => 'SRC-AWB-1',
                'tracking_number' => 'SRC-AWB-1',
                'cost' => 10000,
                'metadata' => [
                    'rate' => [
                        'provider' => 'shipping_delivery',
                        'shipping_name' => 'JNE',
                        'service_name' => 'REG',
                        'shipping_cost' => 25000,
                        'shipping_cashback' => 0,
                        'service_fee' => 0,
                        'additional_cost' => 0,
                        'grandtotal' => 125000,
                    ],
                ],
            ],
        );

        $divergentReship = resolve(ReshipOrderShipment::class)->handle($source->fresh(), $admin);

        try {
            $fulfillment->storeOrderPayload($divergentReship->fresh(['order.shippingAddress', 'order.items', 'order.customer', 'inventory', 'lines.purchasable']));
            $this->fail('Expected RuntimeException when reship rate diverges from original shipment cost.');
        } catch (RuntimeException $e) {
            $this->assertSame('Shipment cost does not match the persisted Shipping Delivery rate.', $e->getMessage());
        }
    }

    public function test_bug_2_superseded_shipments_excluded_from_print_tracking_and_order_fulfillment_notifications(): void
    {
        $this->requireModules('Komerce');

        $admin = $this->fixtures->createAdmin();
        $customer = $this->fixtures->createVerifiedCustomer();

        $this->applyTier('komship', [], false)
            ->withRoute(
                KomerceHttpFakeBuilder::DELIVERY_BASE.'/order/api/v1/orders/store*',
                Http::response([
                    'meta' => ['code' => 422, 'status' => 'failed', 'message' => 'Saldo tidak mencukupi'],
                    'data' => [],
                ], 422),
            )
            ->apply();

        ['order' => $order, 'shipment' => $s1] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'number' => 'ORD-BUG2-RESHIP',
                'status' => OrderStatus::Processing,
                'payment_status' => PaymentStatus::Paid,
                'shipping_status' => ShippingStatus::Returned,
                'price_amount' => 125000,
            ],
            [
                'driver' => 'komerce',
                'carrier_id' => $this->fixtures->carriers['jne']->id,
                'carrier_code' => 'jne',
                'carrier_name' => 'JNE Express',
                'service_code' => 'REG',
                'service_name' => 'REG',
                'status' => NormalizeShipmentStatus::RETURNED,
                'awb' => 'OLD-KOM-AWB-1',
                'tracking_number' => 'OLD-KOM-AWB-1',
                'external_reference' => 'KOM-OLD-1',
                'cost' => 25000,
                'metadata' => [
                    'komerce' => [
                        'order_no' => 'KOM-OLD-1',
                        'order_id' => 10001,
                    ],
                    'rate' => [
                        'provider' => 'shipping_delivery',
                        'shipping_name' => 'JNE',
                        'service_name' => 'REG',
                        'shipping_cost' => 25000,
                        'shipping_cashback' => 0,
                        'service_fee' => 0,
                        'additional_cost' => 0,
                        'grandtotal' => 125000,
                    ],
                ],
            ],
        );

        $s2 = resolve(ReshipOrderShipment::class)->handle($s1->fresh(), $admin);

        // Phase A: Before s2 has an AWB.
        $presenter = resolve(OrderShipmentOpsPresenter::class);
        $this->assertFalse($presenter->canPrintLabel($s1->fresh()));
        $this->assertFalse($presenter->canPrintLabel($s2->fresh()));
        $this->assertSame(0, $presenter->printableCount($presenter->shipments($order->fresh())));

        $shippingCard = $this->cpanelOrderShipping($admin, $order->fresh());
        $shippingCard
            ->assertViewHas('canPrintAnyLabel', false)
            ->assertViewHas('printableShipmentCount', 0);

        $this->cpanelOrderFulfillment($admin, $order->fresh())
            ->call('issueProviderFulfillment')
            ->assertNotified('Resi Komerce belum terbit.');

        $this->cpanelPrintLabel($admin, $order->fresh())->assertStatus(422);
        $this->actingAs($admin)
            ->get(route('shopper.orders.warehouse.bulk-labels', ['orders' => $order->id]))
            ->assertStatus(422);

        $shippingCard->call('refreshTracking', null);
        Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), 'history-airway-bill')
            || str_contains($request->url(), 'track/waybill')
            || str_contains($request->url(), 'print-label'));

        // Verify superseded s1 is also blocked in ShipmentFulfillment & DispatchShipmentFulfillment.
        $this->assertNotNull(ShipmentFulfillment::blockedReason($s1->fresh('order')));
        $this->assertFalse(resolve(DispatchShipmentFulfillment::class)->needsFulfillment($s1->fresh()));

        // Phase B: After s2 gets its own AWB & external_reference.
        $s2->forceFill([
            'awb' => 'NEW-KOM-AWB-2',
            'tracking_number' => 'NEW-KOM-AWB-2',
            'external_reference' => 'KOM-NEW-2',
            'status' => NormalizeShipmentStatus::DELIVERY_FAILED,
            'metadata' => array_merge($s2->metadata ?? [], [
                'komerce' => [
                    'order_no' => 'KOM-NEW-2',
                    'order_id' => 10002,
                ],
            ]),
        ])->save();

        // Mark s1 as delivery_failed (non-terminal) while superseded to prove batch tracking & cron exclude superseded regardless of status.
        $s1->forceFill(['status' => NormalizeShipmentStatus::DELIVERY_FAILED])->save();
        resolve(SyncOrderShippingFromShipments::class)->handle($order->fresh());

        KomerceHttpFakeBuilder::forTier('komship')
            ->withDeliveryPrintLabelResponse([
                'path' => '/storage/label/KOM-NEW-2.pdf',
            ])
            ->withDeliveryDetailResponse([
                'order_no' => 'KOM-NEW-2',
                'airway_bill' => 'NEW-KOM-AWB-2',
                'awb' => 'NEW-KOM-AWB-2',
                'last_status' => 'ON_PROCESS',
            ])
            ->withDeliveryTrackingResponse([
                'airway_bill' => 'NEW-KOM-AWB-2',
                'last_status' => 'ON_PROCESS',
                'history' => [[
                    'desc' => 'On delivery to receiver',
                    'date' => '2026-11-26 12:00:00',
                    'code' => '200',
                    'status' => 'ON_PROCESS',
                ]],
            ])
            ->apply();

        $printResponse = $this->cpanelPrintLabel($admin, $order->fresh());
        $printResponse->assertRedirect(KomerceHttpFakeBuilder::DELIVERY_BASE.'/order/storage/label/KOM-NEW-2.pdf');

        Http::assertSent(static function (Request $request): bool {
            if (! str_contains($request->url(), '/order/api/v1/orders/print-label')) {
                return false;
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['order_no'] ?? null) === 'KOM-NEW-2';
        });
        Http::assertNotSent(static function (Request $request): bool {
            return str_contains($request->url(), '/order/api/v1/orders/print-label')
                && str_contains($request->url(), 'KOM-OLD-1');
        });

        // Direct PrintShipmentLabels batch call also prints only KOM-NEW-2.
        resolve(PrintShipmentLabels::class)->handle($order->fresh());

        // Cpanel KomerceOrderShipping::refreshTracking(null) tracks ONLY NEW-KOM-AWB-2 (1 tracking call).
        $this->cpanelOrderShipping($admin, $order->fresh())
            ->call('refreshTracking', null)
            ->assertSet('overrideError', null);

        $trackingCalls = collect(Http::recorded())
            ->filter(static fn (array $pair): bool => str_contains($pair[0]->url(), 'history-airway-bill')
                || str_contains($pair[0]->url(), 'track/waybill'))
            ->values();

        $this->assertCount(1, $trackingCalls);
        $this->assertStringContainsString('NEW-KOM-AWB-2', $trackingCalls[0][0]->url());
        $this->assertStringNotContainsString('OLD-KOM-AWB-1', $trackingCalls[0][0]->url());

        // Scheduled shipping:refresh-tracking command also skips superseded s1 and only reconciles/tracks s2.
        KomerceHttpFakeBuilder::forTier('komship')
            ->withDeliveryDetailResponse([
                'order_no' => 'KOM-NEW-2',
                'airway_bill' => 'NEW-KOM-AWB-2',
                'awb' => 'NEW-KOM-AWB-2',
                'last_status' => 'ON_PROCESS',
            ])
            ->withDeliveryTrackingResponse([
                'airway_bill' => 'NEW-KOM-AWB-2',
                'last_status' => 'ON_PROCESS',
                'history' => [],
            ])
            ->apply();

        $this->artisan('shipping:refresh-tracking')->assertSuccessful();

        Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), 'OLD-KOM-AWB-1')
            || str_contains($request->url(), 'KOM-OLD-1'));
        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), 'NEW-KOM-AWB-2'));

        // Sibling bulk-labels thermal fallback (Starter / manual) renders only NEW-KOM-AWB-2, never OLD-KOM-AWB-1.
        $this->applyTier('starter');
        $this->actingAs($admin)
            ->get(route('shopper.orders.warehouse.bulk-labels', ['orders' => $order->id]))
            ->assertOk()
            ->assertSee('NEW-KOM-AWB-2')
            ->assertDontSee('OLD-KOM-AWB-1');
    }
}
