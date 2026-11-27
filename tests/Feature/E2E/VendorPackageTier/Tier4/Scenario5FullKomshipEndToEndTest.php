<?php

declare(strict_types=1);

namespace Tests\Feature\E2E\VendorPackageTier\Tier4;

use App\Models\OrderShipment;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Enum\ShippingStatus;
use Shopper\Core\Models\Order;
use Tests\Feature\E2E\VendorPackageTier\Support\KomerceHttpFakeBuilder;
use Tests\Feature\E2E\VendorPackageTier\Support\VendorPackageTierTestCase;

final class Scenario5FullKomshipEndToEndTest extends VendorPackageTierTestCase
{
    public function test_full_komship_end_to_end_across_web_api_cpanel_and_webhooks(): void
    {
        $this->applyTier('komship')
            ->withPaymentStatus('KOMPAY-VA-1', 'PENDING', 112000)
            ->apply();

        $admin = $this->fixtures->createAdmin();
        $product = $this->fixtures->createStockedProduct(100000, 10, 500);
        ['user' => $customer] = $this->webCustomerWithCart($product, 1, 100000);

        // 1. Storefront Web: Delivery quote (is_cod: true on jne:JNEFlat) + VA order placement
        $this->webSearchDestinations($customer, 'Gambir')
            ->assertOk()
            ->assertJsonPath('data.0.id', '17601');

        $this->webSaveShippingAddress($customer)->assertRedirect(route('shop.checkout.index'));

        $this->webLoadCheckout($customer, 2)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('deliveryOptions.0.driver', 'komerce')
                ->where('deliveryOptions.0.service_code', 'jne:JNEFlat')
                ->where('deliveryOptions.0.is_cod', true)
            );

        $this->webPickShippingOption($customer, 'jne:JNEFlat')->assertRedirect(route('shop.checkout.index'));
        $this->webPlaceOrder($customer, $this->fixtures->paymentMethods['bca']->id)->assertRedirect();

        $order = Order::query()->where('customer_id', $customer->id)->latest('id')->firstOrFail();
        $shipment = OrderShipment::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame('komerce', $shipment->driver);
        $this->assertSame('shipping_delivery', data_get($shipment->metadata, 'rate.provider'));
        $this->assertSame(112000, (int) $order->price_amount);

        // 2. Web checkout success page shows VA instructions
        $this->webCheckoutSuccess($customer, $order)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.number', $order->number)
                ->where('paymentAction.kind', 'instructions')
                ->where('paymentAction.data.payment_id', 'KOMPAY-VA-1')
            );

        // 3. Customer Mobile API v1 inspects order & syncs while still pending
        $this->apiShowOrder($customer, $order->number)
            ->assertOk()
            ->assertJsonPath('data.can_retry_payment', true)
            ->assertJsonPath('data.payment_status', PaymentStatus::Pending->value);

        $this->apiSyncPayment($customer, $order->number)
            ->assertOk()
            ->assertJsonPath('data.payment_status', PaymentStatus::Pending->value);

        // 4. Signed Payment webhook settles order Paid -> triggers Store Order + Pickup + Print Label
        KomerceHttpFakeBuilder::forTier('komship')
            ->withPaymentStatus('KOMPAY-VA-1', 'PAID', 112000)
            ->apply();

        $this->postSignedKomercePaymentWebhook([
            'payment_id' => 'KOMPAY-VA-1',
            'order_id' => $order->number,
            'status' => 'PAID',
            'amount' => 112000,
        ])->assertOk();

        $order->refresh();
        $shipment->refresh();
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame(OrderStatus::Processing, $order->status);
        $this->assertSame('RO-E2E-1', $shipment->external_reference);
        $this->assertSame('JNE-E2E-1', $shipment->awb);
        $this->assertSame('labeled', $shipment->status);

        // 5. Admin Cpanel displays registered status & redirects print-label to Delivery PDF URL
        $this->cpanelOrderShipping($admin, $order)
            ->assertSee('Terkirim ke Komerce', false)
            ->assertSee('RO-E2E-1', false)
            ->assertSee('JNE-E2E-1', false)
            ->assertSee('Cetak Semua Stiker Resi', false);

        $this->cpanelPrintLabel($admin, $order, $shipment->id)
            ->assertRedirect('https://delivery.example.test/order/storage/label/RO-E2E-1.pdf');

        // 6. Delivery PUT webhook updates tracking history & status -> API confirmReceived completes order
        $this->postDeliveryWebhook([
            'order_no' => 'RO-E2E-1',
            'cnote' => 'JNE-E2E-1',
            'status' => 'ON_PROCESS',
        ], 'PUT')
            ->assertOk()
            ->assertJson(['status' => 'handled']);

        $shipment->refresh();
        $this->assertSame('in_transit', $shipment->status);
        $this->assertSame('Manifested at Cirebon Hub', data_get($shipment->metadata, 'komerce.tracking_history.0.description'));

        $this->apiConfirmReceived($customer, $order->number)
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Completed->value)
            ->assertJsonPath('data.shipping_status', ShippingStatus::Delivered->value);

        Http::assertSent(fn ($req): bool => str_contains($req->url(), '/order/api/v1/orders/store'));
        Http::assertSent(fn ($req): bool => str_contains($req->url(), '/order/api/v1/pickup/request'));
        Http::assertSent(fn ($req): bool => str_contains($req->url(), '/order/api/v1/orders/print-label'));
    }
}
