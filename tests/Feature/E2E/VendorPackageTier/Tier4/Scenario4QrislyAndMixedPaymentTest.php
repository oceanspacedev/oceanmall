<?php

declare(strict_types=1);

namespace Tests\Feature\E2E\VendorPackageTier\Tier4;

use Inertia\Testing\AssertableInertia as Assert;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Order;
use Tests\Feature\E2E\VendorPackageTier\Support\KomerceHttpFakeBuilder;
use Tests\Feature\E2E\VendorPackageTier\Support\VendorPackageTierTestCase;

final class Scenario4QrislyAndMixedPaymentTest extends VendorPackageTierTestCase
{
    public function test_qrisly_only_and_mixed_payment_qrisly_with_amount_tolerance_and_cross_provider_retry(): void
    {
        $this->fixtures->manualOption->update(['price' => 0]);
        $product5k = $this->fixtures->createStockedProduct(5000, 10, 500);
        $product15k = $this->fixtures->createStockedProduct(15000, 10, 500);

        // 1. QRISLY-Only Rp 5,000 purchase -> QRISLY webhook settles despite unique_amount suffix (5001 vs 5000, tolerance 999)
        $this->applyTier('qrisly_only')
            ->withQrislyGenerate(['history_id' => 9401, 'final_amount' => 5001])
            ->withQrislyStatus('9401', 'paid', 5001)
            ->apply();

        $qrislyOnlyUser = $this->apiCustomerWithCart($product5k);
        $checkoutQrislyOnly = $this->apiLoadCheckout($qrislyOnlyUser)->assertOk();
        $methods = collect($checkoutQrislyOnly->json('data.payment_methods'))->keyBy('slug');
        $this->assertTrue($methods->has('komerce-qris'));
        $this->assertFalse($methods->has('komerce-va-bca'));
        $this->assertSame(1000, (int) data_get($methods->get('komerce-qris'), 'min_amount'));
        $this->assertSame(100000000, (int) data_get($methods->get('komerce-qris'), 'max_amount'));

        $this->apiSaveShippingAddress($qrislyOnlyUser, ['destination_id' => null, 'destination_label' => null])->assertOk();
        $this->apiPickShippingOption($qrislyOnlyUser, $this->fixtures->manualOption->id)->assertOk();
        $placed5k = $this->apiPlaceOrder($qrislyOnlyUser, $this->fixtures->paymentMethods['qris']->id)
            ->assertCreated()
            ->assertJsonPath('data.payment_action.kind', 'qr')
            ->assertJsonPath('data.payment_action.data.provider', 'qrisly')
            ->assertJsonPath('data.payment_action.data.payment_id', '9401')
            ->assertJsonPath('data.payment_action.data.amount', 5001);

        $order5kPaid = Order::query()->where('number', $placed5k->json('data.number'))->firstOrFail();

        $this->postQrislyWebhook([
            'event' => 'payment.success',
            'data' => ['history_id' => '9401', 'status' => 'paid'],
        ])->assertOk();

        $order5kPaid->refresh();
        $this->assertSame(PaymentStatus::Paid, $order5kPaid->payment_status);
        $this->assertSame(OrderStatus::Processing, $order5kPaid->status);

        // 2. Mixed Payment+QRISLY: Rp 5,000 VA rejected 422 pre-order; unpaid Rp 5,000 QRISLY order placed
        $this->applyTier('payment_and_qrisly')
            ->withQrislyGenerate(['history_id' => 9402, 'final_amount' => 5001])
            ->apply();

        $mixedUser = $this->apiCustomerWithCart($product5k);
        $this->apiSaveShippingAddress($mixedUser, ['destination_id' => null, 'destination_label' => null])->assertOk();
        $this->apiPickShippingOption($mixedUser, $this->fixtures->manualOption->id)->assertOk();

        $this->apiPlaceOrder($mixedUser, $this->fixtures->paymentMethods['bca']->id)->assertStatus(422);
        $placed5kUnpaid = $this->apiPlaceOrder($mixedUser, $this->fixtures->paymentMethods['qris']->id)
            ->assertCreated()
            ->assertJsonPath('data.payment_action.data.provider', 'qrisly');
        $order5kUnpaidNumber = (string) $placed5kUnpaid->json('data.number');

        // 3. Mixed Payment+QRISLY: Rp 15,000 Web QRIS placed via QRISLY ('qrisly', history_id 9403)
        KomerceHttpFakeBuilder::forTier('payment_and_qrisly')
            ->withQrislyGenerate(['history_id' => 9403, 'final_amount' => 15001])
            ->withPaymentCreate([
                'payment_id' => 'KOMPAY-QRIS-15K',
                'qris_string' => '000201-PAYMENT-API-15K',
                'amount' => 15000,
            ])
            ->withPaymentStatus('KOMPAY-QRIS-15K', 'PAID', 15000)
            ->apply();

        ['user' => $webUser] = $this->webCustomerWithCart($product15k);
        $this->webSaveShippingAddress($webUser, ['destination_id' => null, 'destination_label' => null])->assertRedirect(route('shop.checkout.index'));
        $this->webPickShippingOption($webUser, $this->fixtures->manualOption->id)->assertRedirect(route('shop.checkout.index'));
        $this->webPlaceOrder($webUser, $this->fixtures->paymentMethods['qris']->id)->assertRedirect();

        $order15k = Order::query()->where('customer_id', $webUser->id)->latest('id')->firstOrFail();
        $this->webCheckoutSuccess($webUser, $order15k)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('paymentAction.kind', 'qr')
                ->where('paymentAction.data.provider', 'qrisly')
                ->where('paymentAction.data.payment_id', '9403')
            );

        // 4. Mid-flight QRISLY revocation (401) -> Rp 15,000 retries onto Payment API QRIS & settles via signed webhook,
        //    while unpaid Rp 5,000 QRISLY order drops to can_retry_payment=false
        $this->resetTierState(flushCache: false);
        KomerceHttpFakeBuilder::forTier('payment_and_qrisly')
            ->withQrislyEntitled(false, 401, 'Invalid API Key')
            ->withPaymentCreate([
                'payment_id' => 'KOMPAY-QRIS-15K',
                'qris_string' => '000201-PAYMENT-API-15K',
                'amount' => 15000,
            ])
            ->withPaymentStatus('KOMPAY-QRIS-15K', 'PAID', 15000)
            ->apply();

        $this->webRetryPayment($webUser, $order15k)
            ->assertRedirect(route('account.orders.show', $order15k))
            ->assertSessionHas('success');

        $order15k->refresh();
        $order15kMeta = json_decode((string) $order15k->metadata, true) ?: [];
        $this->assertSame('payment_api', data_get($order15kMeta, 'komerce.provider'));
        $this->assertSame('KOMPAY-QRIS-15K', data_get($order15kMeta, 'komerce.payment_ref'));

        // Rp 5,000 unpaid QRISLY order cannot retry via Payment API (below 10,000 minimum)
        $this->apiShowOrder($mixedUser, $order5kUnpaidNumber)
            ->assertOk()
            ->assertJsonPath('data.can_retry_payment', false);

        // Signed Payment webhook settles the retried Rp 15,000 order
        $this->postSignedKomercePaymentWebhook([
            'payment_id' => 'KOMPAY-QRIS-15K',
            'order_id' => $order15k->number,
            'status' => 'PAID',
            'amount' => 15000,
        ])->assertOk();

        $this->assertSame(PaymentStatus::Paid, $order15k->fresh()->payment_status);
    }
}
