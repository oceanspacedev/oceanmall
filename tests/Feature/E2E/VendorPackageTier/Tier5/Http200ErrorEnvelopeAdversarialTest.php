<?php

declare(strict_types=1);

namespace Tests\Feature\E2E\VendorPackageTier\Tier5;

use App\Integrations\Komerce\Actions\Shipping\CancelKomerceDelivery;
use App\Integrations\Komerce\Komerce;
use App\Integrations\Komerce\Services\PaymentClient;
use App\Integrations\Komerce\Services\QrislyClient;
use App\Integrations\Komerce\Services\ShippingCostClient;
use App\Integrations\Komerce\Services\ShippingDeliveryClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Feature\E2E\VendorPackageTier\Support\KomerceHttpFakeBuilder;
use Tests\Feature\E2E\VendorPackageTier\Support\VendorPackageTierTestCase;

final class Http200ErrorEnvelopeAdversarialTest extends VendorPackageTierTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireModules('Komerce');
    }

    public function test_cancel_komerce_delivery_rejects_http_200_error_envelopes_without_marking_cancelled(): void
    {
        $envelopes = [
            ['success' => false, 'meta' => ['code' => 400, 'message' => 'Cannot cancel already picked up order']],
            ['meta' => ['code' => 429, 'message' => 'Daily limit exceeded']],
            ['meta' => ['status' => 'error', 'message' => 'Cancellation rejected']],
        ];

        foreach ($envelopes as $index => $body) {
            $this->applyTier('komship')
                ->withRoute(
                    KomerceHttpFakeBuilder::DELIVERY_BASE.'/order/api/v1/orders/cancel*',
                    Http::response($body, 200),
                )
                ->apply();

            $customer = $this->fixtures->createVerifiedCustomer();
            ['shipment' => $shipment] = $this->fixtures->createOrderWithShipment(
                $customer,
                [],
                [
                    'driver' => 'komerce',
                    'status' => 'labeled',
                    'awb' => 'JNE-CAN-'.$index,
                    'external_reference' => 'RO-CAN-'.$index,
                    'metadata' => ['komerce' => ['order_no' => 'RO-CAN-'.$index, 'awb' => 'JNE-CAN-'.$index]],
                ],
            );

            try {
                resolve(CancelKomerceDelivery::class)->cancelShipment($shipment);
                $this->fail('Expected CancelKomerceDelivery to throw on HTTP 200 error envelope.');
            } catch (RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage());
            }

            $fresh = $shipment->fresh();
            $this->assertSame('labeled', $fresh->status);
            $this->assertNull(data_get($fresh->metadata, 'komerce.cancelled_at'));
            $this->assertNotNull(data_get($fresh->metadata, 'komerce.cancel_error'));
        }
    }

    public function test_print_label_http_200_auth_and_quota_envelopes_return_422_label_error_while_sandbox_404_keeps_thermal_fallback(): void
    {
        $admin = $this->fixtures->createAdmin();
        $customer = $this->fixtures->createVerifiedCustomer();

        // 1. HTTP 200 with meta.code 401 Invalid API key -> 422 label-error + disables delivery entitlement
        $this->applyTier('komship')
            ->withRoute(
                KomerceHttpFakeBuilder::DELIVERY_BASE.'/order/api/v1/orders/print-label*',
                Http::response([
                    'meta' => ['code' => 401, 'status' => 'error', 'message' => 'Invalid API key'],
                    'data' => null,
                ], 200),
            )
            ->apply();

        ['order' => $order401] = $this->fixtures->createOrderWithShipment(
            $customer,
            [],
            [
                'driver' => 'komerce',
                'status' => 'labeled',
                'awb' => 'JNE-LBL-401',
                'tracking_number' => 'JNE-LBL-401',
                'external_reference' => 'RO-LBL-401',
                'metadata' => ['komerce' => ['order_no' => 'RO-LBL-401', 'awb' => 'JNE-LBL-401']],
            ],
        );

        $this->cpanelPrintLabel($admin, $order401)
            ->assertStatus(422)
            ->assertViewIs('shopper.shipping.label-error')
            ->assertSee('Akses layanan cetak label pengiriman tidak aktif atau ditolak penyedia', false)
            ->assertDontSee('Invalid API key', false);
        $this->assertFalse(Komerce::shippingDeliveryEnabled());

        // 2. HTTP 200 with meta.code 429 quota exceeded -> 422 label-error
        $this->applyTier('komship')
            ->withRoute(
                KomerceHttpFakeBuilder::DELIVERY_BASE.'/order/api/v1/orders/print-label*',
                Http::response([
                    'meta' => ['code' => 429, 'status' => 'error', 'message' => 'Too many requests'],
                    'data' => null,
                ], 200),
            )
            ->apply();

        ['order' => $order429] = $this->fixtures->createOrderWithShipment(
            $customer,
            [],
            [
                'driver' => 'komerce',
                'status' => 'labeled',
                'awb' => 'JNE-LBL-429',
                'tracking_number' => 'JNE-LBL-429',
                'external_reference' => 'RO-LBL-429',
                'metadata' => ['komerce' => ['order_no' => 'RO-LBL-429', 'awb' => 'JNE-LBL-429']],
            ],
        );

        $this->cpanelPrintLabel($admin, $order429)
            ->assertStatus(422)
            ->assertViewIs('shopper.shipping.label-error')
            ->assertSee('Batas permintaan layanan cetak label pengiriman sedang tercapai', false);

        // 3. HTTP 200 with success:false & meta.code:400 -> 422 label-error
        $this->applyTier('komship')
            ->withRoute(
                KomerceHttpFakeBuilder::DELIVERY_BASE.'/order/api/v1/orders/print-label*',
                Http::response([
                    'success' => false,
                    'meta' => ['code' => 400, 'message' => 'Order cancelled remotely'],
                    'data' => null,
                ], 200),
            )
            ->apply();

        ['order' => $order400] = $this->fixtures->createOrderWithShipment(
            $customer,
            [],
            [
                'driver' => 'komerce',
                'status' => 'labeled',
                'awb' => 'JNE-LBL-400',
                'tracking_number' => 'JNE-LBL-400',
                'external_reference' => 'RO-LBL-400',
                'metadata' => ['komerce' => ['order_no' => 'RO-LBL-400', 'awb' => 'JNE-LBL-400']],
            ],
        );

        $this->cpanelPrintLabel($admin, $order400)
            ->assertStatus(422)
            ->assertViewIs('shopper.shipping.label-error');

        // 4. Genuine sandbox "awb not found" (while delivery remains entitled) -> 200 thermal-label fallback
        $this->applyTier('komship')
            ->withRoute(
                KomerceHttpFakeBuilder::DELIVERY_BASE.'/order/api/v1/orders/print-label*',
                Http::response([
                    'meta' => ['code' => 500, 'status' => 'error', 'message' => 'awb not found'],
                    'data' => null,
                ], 500),
            )
            ->apply();

        ['order' => $orderSandbox] = $this->fixtures->createOrderWithShipment(
            $customer,
            [],
            [
                'driver' => 'komerce',
                'status' => 'labeled',
                'awb' => 'JNE-LBL-SANDBOX',
                'tracking_number' => 'JNE-LBL-SANDBOX',
                'external_reference' => 'RO-LBL-SANDBOX',
                'metadata' => ['komerce' => ['order_no' => 'RO-LBL-SANDBOX', 'awb' => 'JNE-LBL-SANDBOX']],
            ],
        );

        $this->cpanelPrintLabel($admin, $orderSandbox)
            ->assertOk()
            ->assertViewIs('shopper.shipping.thermal-label')
            ->assertSee('JNE-LBL-SANDBOX', false);
    }

    public function test_sibling_delivery_cost_payment_and_qrisly_clients_reject_http_200_error_envelopes(): void
    {
        $this->applyTier('komship')
            ->withRoute(
                KomerceHttpFakeBuilder::DELIVERY_BASE.'/tariff/api/v1/calculate*',
                Http::response(['success' => false, 'meta' => ['code' => 400, 'message' => 'Tariff rejected']], 200),
            )
            ->withRoute(
                KomerceHttpFakeBuilder::COST_BASE.'/api/v1/calculate/domestic-cost*',
                Http::response(['success' => false, 'meta' => ['code' => 429, 'message' => 'Daily limit exceeded']], 200),
            )
            ->withRoute(
                KomerceHttpFakeBuilder::COST_BASE.'/api/v1/track/waybill*',
                Http::response(['meta' => ['code' => 400, 'status' => 'error', 'message' => 'Waybill rejected']], 200),
            )
            ->withRoute(
                KomerceHttpFakeBuilder::COST_BASE.'/api/v1/destination/province*',
                Http::response(['success' => false, 'message' => 'Province lookup rejected'], 200),
            )
            ->withRoute(
                KomerceHttpFakeBuilder::PAYMENT_BASE.'/api/v1/user/payment/status/*',
                Http::response(['success' => false, 'meta' => ['code' => 400, 'message' => 'Payment status error']], 200),
            )
            ->withRoute(
                KomerceHttpFakeBuilder::QRISLY_BASE.'/api/v1/qrisly/payment-status/*',
                Http::response(['success' => false, 'meta' => ['code' => 400, 'message' => 'QRISLY status error']], 200),
            )
            ->apply();

        $delivery = resolve(ShippingDeliveryClient::class);
        $cost = resolve(ShippingCostClient::class);
        $payment = resolve(PaymentClient::class);
        $qrisly = resolve(QrislyClient::class);

        $this->assertThrowsRuntime(fn () => $delivery->calculate(17248, 17601, '-6.7,108.5', '-6.1,106.8', 1.0, 100000), 'Tariff rejected');
        $this->assertThrowsRuntime(fn () => $cost->calculate(['id' => 17248], ['id' => 17601], 1000, ['jne']), 'Daily limit exceeded');
        $this->assertThrowsRuntime(fn () => $cost->trackWaybill('JNE123', 'jne'), 'Waybill rejected');
        $this->assertThrowsRuntime(fn () => $cost->searchProvinces(), 'Province lookup rejected');
        $this->assertThrowsRuntime(fn () => $payment->getStatus('KOMPAY-1'), 'Payment status error');
        $this->assertThrowsRuntime(fn () => $qrisly->getPaymentStatus('9001'), 'QRISLY status error');
    }

    private function assertThrowsRuntime(callable $callback, string $expectedSubstring): void
    {
        try {
            $callback();
            $this->fail("Expected RuntimeException containing [{$expectedSubstring}].");
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($expectedSubstring, $e->getMessage());
        }
    }
}
