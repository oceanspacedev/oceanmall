<?php

declare(strict_types=1);

namespace Tests\Feature\E2E\VendorPackageTier\Tier4;

use App\Models\OrderShipment;
use Illuminate\Support\Facades\Http;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Enum\ShippingStatus;
use Shopper\Core\Models\Order;
use Tests\Feature\E2E\VendorPackageTier\Support\VendorPackageTierTestCase;

final class Scenario2StarterApiPurchaseTest extends VendorPackageTierTestCase
{
    public function test_starter_api_purchase_webhook_paid_cpanel_manual_awb_cost_tracking_and_confirm_received(): void
    {
        $this->applyTier('starter');

        $admin = $this->fixtures->createAdmin();
        $product = $this->fixtures->createStockedProduct(100000, 10, 500);
        $customer = $this->apiCustomerWithCart($product, 1);

        // 1. Mobile API destination search & shipping quote via Shipping Cost
        $this->apiSearchDestinations($customer, 'Gambir')
            ->assertOk()
            ->assertJsonPath('data.0.id', '17601');

        $this->apiSaveShippingAddress($customer)->assertOk();

        $checkout = $this->apiLoadCheckout($customer)->assertOk();
        $this->assertSame('rajaongkir', $checkout->json('data.shipping_rates.0.driver'));
        $this->assertTrue(collect($checkout->json('data.payment_methods'))->contains('slug', 'komerce-va-bca'));

        $this->apiPickShippingOption($customer, 'jne:REG')
            ->assertOk()
            ->assertJsonPath('data.shipping_option.service_code', 'jne:REG');

        // 2. Place order with Payment API BCA VA (100,000 + 18,000 = 118,000)
        $placed = $this->apiPlaceOrder($customer, $this->fixtures->paymentMethods['bca']->id)
            ->assertCreated()
            ->assertJsonPath('data.amount', 118000)
            ->assertJsonPath('data.payment_action.kind', 'instructions')
            ->assertJsonPath('data.payment_action.data.payment_id', 'KOMPAY-VA-1');

        $orderNumber = (string) $placed->json('data.number');
        $order = Order::query()->where('number', $orderNumber)->firstOrFail();

        // 3. Signed Payment webhook settles order Paid without calling Delivery Store Order
        $manifestTime = now()->addMinutes(5);
        $this->applyTier('starter')
            ->withPaymentStatus('KOMPAY-VA-1', 'PAID', 118000)
            ->withCostTracking([
                'delivered' => false,
                'summary' => ['waybill_number' => 'JNE-STARTER-1', 'status' => 'ON PROCESS'],
                'delivery_status' => ['status' => 'ON PROCESS'],
                'manifest' => [
                    [
                        'manifest_code' => '100',
                        'manifest_description' => 'Shipment received by JNE',
                        'manifest_date' => $manifestTime->format('Y-m-d'),
                        'manifest_time' => $manifestTime->format('H:i'),
                        'city_name' => 'Cirebon',
                    ],
                ],
            ])
            ->apply();

        $this->postSignedKomercePaymentWebhook([
            'payment_id' => 'KOMPAY-VA-1',
            'order_id' => $orderNumber,
            'status' => 'PAID',
            'amount' => 118000,
        ])->assertOk();

        $order->refresh();
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame(OrderStatus::Processing, $order->status);
        Http::assertNotSent(fn ($req): bool => str_contains($req->url(), '/order/api/v1/orders/store'));

        // 4. Cpanel manual AWB entry + Starter badges/print parity (M3 #10)
        $this->cpanelOrderFulfillment($admin, $order)
            ->assertSee(__('shopper::pages/orders.create_shipping_label'), false)
            ->assertDontSee('Terbitkan Resi Komerce', false);

        $this->cpanelCreateShippingLabel($admin, $order)
            ->set('data.tracking_number', 'JNE-STARTER-1')
            ->call('save')
            ->assertHasNoErrors();

        $shipment = OrderShipment::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame('JNE-STARTER-1', $shipment->awb);

        // Starter KomerceOrderShipping card must NOT show 'Sebagian Terdaftar' and MUST expose print buttons (M3 #10)
        $this->cpanelOrderShipping($admin, $order)
            ->assertDontSee('Sebagian Terdaftar', false)
            ->assertDontSee('Belum Terdaftar', false)
            ->assertSee('Cetak Semua Stiker Resi', false)
            ->assertSee('Cetak Stiker Resi', false);

        $this->cpanelPrintLabel($admin, $order, $shipment->id)
            ->assertOk()
            ->assertViewIs('shopper.shipping.thermal-label');

        // 5. Mobile API order detail shows can_track=true & Cost tracking populates history
        $this->apiShowOrder($customer, $orderNumber)
            ->assertOk()
            ->assertJsonPath('data.shipments.0.can_track', true)
            ->assertJsonPath('data.shipments.0.awb', 'JNE-STARTER-1');

        $this->apiTrackShipment($customer, $orderNumber, $shipment->id)
            ->assertOk()
            ->assertJsonPath('tracking_refreshed', true)
            ->assertJsonPath('data.shipments.0.tracking_history.0.description', 'Shipment received by JNE');

        Http::assertSent(fn ($req): bool => str_contains($req->url(), '/api/v1/track/waybill')
            && $req->hasHeader('key', 'cost-key'));

        // 6. Customer confirms receipt via API -> Completed / Delivered
        $this->apiConfirmReceived($customer, $orderNumber)
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Completed->value)
            ->assertJsonPath('data.shipping_status', ShippingStatus::Delivered->value);
    }
}
