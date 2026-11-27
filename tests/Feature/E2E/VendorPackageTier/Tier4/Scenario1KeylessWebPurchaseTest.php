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

final class Scenario1KeylessWebPurchaseTest extends VendorPackageTierTestCase
{
    public function test_keyless_web_purchase_manual_payment_cpanel_awb_label_and_confirm_received(): void
    {
        $this->applyTier('keyless');

        $admin = $this->fixtures->createAdmin();
        $product = $this->fixtures->createStockedProduct(100000, 10, 500);
        ['user' => $customer] = $this->webCustomerWithCart($product, 1, 100000);

        // 1. Web checkout address (no destination_id required on keyless)
        $this->webSaveShippingAddress($customer, [
            'destination_id' => null,
            'destination_label' => null,
            'pin_point' => null,
        ])->assertRedirect(route('shop.checkout.index'));

        // 2. Step 2 shows manual zone option (Rp 15,000), zero Komerce rates
        $this->webLoadCheckout($customer, 2)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('deliveryOptions.0.driver', 'manual')
                ->where('deliveryOptions.0.amount', 15000)
                ->where('shippingRatesHint', null)
            );

        $this->webPickShippingOption($customer, $this->fixtures->manualOption->id)
            ->assertRedirect(route('shop.checkout.index'));

        // 3. Step 3 shows manual & COD only (no Komerce methods)
        $this->webLoadCheckout($customer, 3)
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $drivers = collect($page->toArray()['props']['paymentOptions'])->pluck('driver')->all();
                $this->assertNotContains('komerce', $drivers);
                $this->assertContains('manual', $drivers);
            });

        $this->webPlaceOrder($customer, $this->fixtures->paymentMethods['manual']->id)
            ->assertRedirect();

        $order = Order::query()->where('customer_id', $customer->id)->latest('id')->firstOrFail();
        $this->assertSame(115000, (int) $order->price_amount);
        $this->assertSame(OrderStatus::New, $order->status);
        $this->assertSame(PaymentStatus::Pending, $order->payment_status);

        $this->webCheckoutSuccess($customer, $order)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.number', $order->number)
                ->where('paymentAction.kind', 'none')
            );

        // 4. Cpanel OrderDetail marks manual payment as Paid
        $this->cpanelOrderDetail($admin, $order)
            ->callAction('markPaid', data: [
                'verified_amount' => 115000,
                'bank_reference' => 'BCA-MANUAL-E2E-1',
            ])
            ->assertHasNoActionErrors();

        $order->refresh();
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame(OrderStatus::Processing, $order->status);

        // 5. Cpanel OrderFulfillment opens manual label slide-over -> CreateShippingLabel saves AWB
        $this->cpanelOrderFulfillment($admin, $order)
            ->assertSee(__('shopper::pages/orders.create_shipping_label'), false)
            ->call('openShippingLabel')
            ->assertDispatched('openPanel', component: 'shopper-slide-overs.create-shipping-label');

        $this->cpanelCreateShippingLabel($admin, $order)
            ->set('data.tracking_number', 'MANUAL-AWB-E2E-1')
            ->call('save')
            ->assertHasNoErrors();

        $shipment = OrderShipment::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame('MANUAL-AWB-E2E-1', $shipment->awb);

        // 6. Cpanel thermal print label renders cleanly
        $printResponse = $this->cpanelPrintLabel($admin, $order, $shipment->id);
        $this->assertSame(
            200,
            $printResponse->getStatusCode(),
            'Keyless manual checkout shipment with manual AWB must print thermal-label with HTTP 200.',
        );
        $printResponse->assertViewIs('shopper.shipping.thermal-label');

        // 7. Customer confirms received on Web -> Completed / Delivered, zero HTTP calls
        $this->webConfirmReceived($customer, $order)
            ->assertRedirect(route('account.orders.show', $order));

        $order->refresh();
        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertSame(ShippingStatus::Delivered, $order->shipping_status);

        Http::assertNothingSent();
    }
}
