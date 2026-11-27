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
use Tests\Feature\E2E\VendorPackageTier\Support\VendorPackageTierTestCase;

final class Scenario3DeliveryOnlyWebPurchaseTest extends VendorPackageTierTestCase
{
    public function test_delivery_only_web_purchase_migrated_origin_cpanel_mark_paid_auto_fulfillment_and_tracking(): void
    {
        $this->applyTier('delivery_only');

        $admin = $this->fixtures->createAdmin();
        $product = $this->fixtures->createStockedProduct(100000, 10, 500);
        ['user' => $customer] = $this->webCustomerWithCart($product, 1, 100000);

        // 1. Delivery destination search uses Delivery API (zero Cost requests)
        $this->webSearchDestinations($customer, 'Gambir')
            ->assertOk()
            ->assertJsonPath('data.0.id', '17601');
        Http::assertNotSent(fn ($req): bool => str_contains($req->url(), 'cost.example.test'));

        $this->webSaveShippingAddress($customer)->assertRedirect(route('shop.checkout.index'));

        // 2. Web checkout allocates with migrated 'rajaongkir' origin (M1 #2) and quotes via Delivery
        $this->webLoadCheckout($customer, 2)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('deliveryOptions.0.driver', 'komerce')
                ->where('deliveryOptions.0.service_code', 'jne:JNEFlat')
                ->where('shippingRatesHint', null)
            );
        Http::assertNotSent(fn ($req): bool => str_contains($req->url(), 'cost.example.test'));

        $this->webPickShippingOption($customer, 'jne:JNEFlat')->assertRedirect(route('shop.checkout.index'));

        // 3. Payment step degrades to manual / COD (no Komerce payment keys)
        $this->webLoadCheckout($customer, 3)
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $drivers = collect($page->toArray()['props']['paymentOptions'])->pluck('driver')->all();
                $this->assertNotContains('komerce', $drivers);
            });

        $this->webPlaceOrder($customer, $this->fixtures->paymentMethods['manual']->id)
            ->assertRedirect();

        $order = Order::query()->where('customer_id', $customer->id)->latest('id')->firstOrFail();
        $this->assertSame(112000, (int) $order->price_amount);

        // 4. Cpanel OrderDetail marks manual payment Paid -> OrderPaid synchronously dispatches Store Order + Pickup + Print Label
        $this->cpanelOrderDetail($admin, $order)
            ->callAction('markPaid', data: [
                'verified_amount' => 112000,
                'bank_reference' => 'MANUAL-DELIVERY-ONLY-1',
            ])
            ->assertHasNoActionErrors();

        $order->refresh();
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);

        $shipment = OrderShipment::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame('RO-E2E-1', $shipment->external_reference);
        $this->assertSame('JNE-E2E-1', $shipment->awb);
        $this->assertSame('labeled', $shipment->status);

        Http::assertSent(fn ($req): bool => str_contains($req->url(), '/order/api/v1/orders/store'));
        Http::assertSent(fn ($req): bool => str_contains($req->url(), '/order/api/v1/pickup/request'));
        Http::assertSent(fn ($req): bool => str_contains($req->url(), '/order/api/v1/orders/print-label'));

        // 5. Cpanel print-label redirects to official Komerce Delivery PDF label
        $this->cpanelPrintLabel($admin, $order, $shipment->id)
            ->assertRedirect('https://delivery.example.test/order/storage/label/RO-E2E-1.pdf');

        // 6. Web tracking queries Delivery history-airway-bill & customer confirms receipt
        $this->webTrackShipment($customer, $order, $shipment)
            ->assertRedirect(route('account.orders.show', $order));

        $shipment->refresh();
        $this->assertSame('in_transit', $shipment->status);
        $this->assertSame('Manifested at Cirebon Hub', data_get($shipment->metadata, 'komerce.tracking_history.0.description'));

        $this->webConfirmReceived($customer, $order)
            ->assertRedirect(route('account.orders.show', $order));

        $order->refresh();
        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertSame(ShippingStatus::Delivered, $order->shipping_status);
    }
}
