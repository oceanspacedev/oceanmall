<?php

declare(strict_types=1);

namespace Tests\Feature\E2E\VendorPackageTier\Tier3;

use App\Actions\Orders\CancelOrderWithProviders;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Order;
use Tests\Feature\E2E\VendorPackageTier\Support\KomerceHttpFakeBuilder;
use Tests\Feature\E2E\VendorPackageTier\Support\VendorPackageTierTestCase;

final class QrislyFallbackAndCrossProviderRetryTest extends VendorPackageTierTestCase
{
    public function test_qrisly_401_and_403_fall_back_to_payment_api_when_above_10k_and_fail_cleanly_at_5k(): void
    {
        $this->fixtures->manualOption->update(['price' => 0]);

        foreach ([401, 403] as $status) {
            $this->applyTier('payment_and_qrisly')
                ->withQrislyEntitled(false, $status, 'Invalid API Key')
                ->withPaymentCreate([
                    'payment_id' => "KOMPAY-QRIS-FB-{$status}",
                    'qris_string' => "000201-FB-{$status}",
                    'amount' => 25000,
                ])
                ->apply();

            $product25k = $this->fixtures->createStockedProduct(25000, 10, 500);

            // API Rp 25,000 QRIS order falls back seamlessly to Payment API
            $apiUser = $this->apiCustomerWithCart($product25k);
            $this->apiSaveShippingAddress($apiUser, ['destination_id' => null, 'destination_label' => null])->assertOk();
            $this->apiPickShippingOption($apiUser, $this->fixtures->manualOption->id)->assertOk();

            $this->apiPlaceOrder($apiUser, $this->fixtures->paymentMethods['qris']->id)
                ->assertCreated()
                ->assertJsonPath('data.payment_action.kind', 'qr')
                ->assertJsonPath('data.payment_action.data.provider', 'payment_api')
                ->assertJsonPath('data.payment_action.data.payment_id', "KOMPAY-QRIS-FB-{$status}");

            $this->assertFalse($this->komerceProductEnabled('qrisly'));
        }

        // Web Rp 25,000 QRIS order also falls back to Payment API on QRISLY 403
        $this->applyTier('payment_and_qrisly')
            ->withQrislyEntitled(false, 403, 'Invalid API Key')
            ->withPaymentCreate([
                'payment_id' => 'KOMPAY-QRIS-WEB-FB',
                'qris_string' => '000201-WEB-FB',
                'amount' => 25000,
            ])
            ->apply();

        $product25k = $this->fixtures->createStockedProduct(25000, 10, 500);
        ['user' => $webUser] = $this->webCustomerWithCart($product25k);
        $this->webSaveShippingAddress($webUser, ['destination_id' => null, 'destination_label' => null])->assertRedirect(route('shop.checkout.index'));
        $this->webPickShippingOption($webUser, $this->fixtures->manualOption->id)->assertRedirect(route('shop.checkout.index'));

        $this->webPlaceOrder($webUser, $this->fixtures->paymentMethods['qris']->id)->assertRedirect();
        $webOrder = Order::query()->where('customer_id', $webUser->id)->latest('id')->firstOrFail();

        $this->webCheckoutSuccess($webUser, $webOrder)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('paymentAction.kind', 'qr')
                ->where('paymentAction.data.provider', 'payment_api')
                ->where('paymentAction.data.payment_id', 'KOMPAY-QRIS-WEB-FB')
            );

        // Rp 5,000 order (below Payment API 10,000 min) when QRISLY returns 401 during placeOrder:
        // order stays Pending with payment_error and can_retry_payment=false (no 500)
        $this->applyTier('payment_and_qrisly')
            ->withQrislyEntitled(false, 401, 'Invalid API Key')
            ->apply();

        $product5k = $this->fixtures->createStockedProduct(5000, 10, 500);
        $lowApiUser = $this->apiCustomerWithCart($product5k);
        $this->apiSaveShippingAddress($lowApiUser, ['destination_id' => null, 'destination_label' => null])->assertOk();
        $this->apiPickShippingOption($lowApiUser, $this->fixtures->manualOption->id)->assertOk();

        $lowPlaced = $this->apiPlaceOrder($lowApiUser, $this->fixtures->paymentMethods['qris']->id)
            ->assertCreated()
            ->assertJsonPath('data.can_retry_payment', false);
        $this->assertNotNull($lowPlaced->json('data.payment_error'));
    }

    public function test_cross_provider_qris_retry_both_directions_across_web_and_api_with_webhook_settlement(): void
    {
        $this->fixtures->manualOption->update(['price' => 0]);
        $product25k = $this->fixtures->createStockedProduct(25000, 10, 500);

        // Direction 1 (Web): QRISLY -> Payment API on QRISLY 401 -> settled via signed Payment webhook
        $this->applyTier('payment_and_qrisly')
            ->withQrislyGenerate(['history_id' => 9101, 'final_amount' => 25001])
            ->withPaymentCreate([
                'payment_id' => 'KOMPAY-RETRY-1',
                'qris_string' => '000201-RETRY-PAYMENT-API',
                'amount' => 25000,
            ])
            ->withPaymentStatus('KOMPAY-RETRY-1', 'PAID', 25000)
            ->apply();

        ['user' => $webUser] = $this->webCustomerWithCart($product25k);
        $this->webSaveShippingAddress($webUser, ['destination_id' => null, 'destination_label' => null])->assertRedirect(route('shop.checkout.index'));
        $this->webPickShippingOption($webUser, $this->fixtures->manualOption->id)->assertRedirect(route('shop.checkout.index'));
        $this->webPlaceOrder($webUser, $this->fixtures->paymentMethods['qris']->id)->assertRedirect();

        $qrislyOrder = Order::query()->where('customer_id', $webUser->id)->latest('id')->firstOrFail();
        $qrislyMeta = json_decode((string) $qrislyOrder->metadata, true) ?: [];
        $this->assertSame('qrisly', data_get($qrislyMeta, 'komerce.provider'));

        // Revoke QRISLY mid-flight without flushing capability cache
        $this->resetTierState(flushCache: false);
        KomerceHttpFakeBuilder::forTier('payment_and_qrisly')
            ->withQrislyEntitled(false, 401, 'Invalid API Key')
            ->withPaymentCreate([
                'payment_id' => 'KOMPAY-RETRY-1',
                'qris_string' => '000201-RETRY-PAYMENT-API',
                'amount' => 25000,
            ])
            ->withPaymentStatus('KOMPAY-RETRY-1', 'PAID', 25000)
            ->apply();

        $this->webRetryPayment($webUser, $qrislyOrder)
            ->assertRedirect(route('account.orders.show', $qrislyOrder))
            ->assertSessionHas('success');

        $qrislyOrder->refresh();
        $retriedMeta = json_decode((string) $qrislyOrder->metadata, true) ?: [];
        $this->assertSame('payment_api', data_get($retriedMeta, 'komerce.provider'));
        $this->assertSame('KOMPAY-RETRY-1', data_get($retriedMeta, 'komerce.payment_ref'));

        $this->postSignedKomercePaymentWebhook([
            'payment_id' => 'KOMPAY-RETRY-1',
            'order_id' => $qrislyOrder->number,
            'status' => 'PAID',
            'amount' => 25000,
        ])->assertOk();
        $this->assertSame(PaymentStatus::Paid, $qrislyOrder->fresh()->payment_status);

        // Direction 2 (API): Payment API -> QRISLY on Payment API 401 -> settled via QRISLY webhook
        $this->applyTier('payment_and_qrisly')
            ->withQrislyGenerate(['history_id' => 9202, 'final_amount' => 25001])
            ->withQrislyStatus('9202', 'paid', 25001)
            ->apply();

        $apiUser = $this->fixtures->createVerifiedCustomer();
        ['order' => $paymentApiOrder] = $this->fixtures->createOrderWithShipment(
            $apiUser,
            [
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'payment_method_id' => $this->fixtures->paymentMethods['qris']->id,
                'price_amount' => 25000,
                'metadata' => [
                    'komerce' => [
                        'payment_type' => 'qris',
                        'provider' => 'payment_api',
                        'payment_ref' => 'KOMPAY-ORIG-QRIS',
                    ],
                ],
            ],
            [],
            ['amount' => 25000, 'reference' => 'KOMPAY-ORIG-QRIS'],
            $product25k,
        );

        // Revoke Payment API mid-flight while QRISLY remains active
        $this->resetTierState(flushCache: false);
        KomerceHttpFakeBuilder::forTier('payment_and_qrisly')
            ->withPaymentEntitled(false, 401, 'Invalid API key')
            ->withQrislyGenerate(['history_id' => 9202, 'final_amount' => 25001])
            ->withQrislyStatus('9202', 'paid', 25001)
            ->apply();

        $this->apiRetryPayment($apiUser, $paymentApiOrder->number)
            ->assertOk()
            ->assertJsonPath('data.payment_action.kind', 'qr')
            ->assertJsonPath('data.payment_action.data.provider', 'qrisly')
            ->assertJsonPath('data.payment_action.data.payment_id', '9202');

        $this->postQrislyWebhook([
            'event' => 'payment.success',
            'data' => ['history_id' => '9202', 'status' => 'paid'],
        ])->assertOk();

        $this->assertSame(PaymentStatus::Paid, $paymentApiOrder->fresh()->payment_status);
        Http::assertSent(fn ($req): bool => str_contains($req->url(), '/qrisly/payment-status/9202'));
    }

    public function test_staff_and_customer_can_cancel_unpaid_qrisly_order_when_qrisly_disabled_or_revoked_while_payment_enabled(): void
    {
        // 1. Starter tier: Payment API enabled, QRISLY disabled (no key / unentitled)
        $this->applyTier('starter');

        $product = $this->fixtures->createStockedProduct(5000, 10, 500);
        $customer = $this->fixtures->createVerifiedCustomer();

        ['order' => $staffOrder] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'payment_method_id' => $this->fixtures->paymentMethods['qris']->id,
                'price_amount' => 5000,
                'metadata' => [
                    'komerce' => [
                        'payment_type' => 'qris',
                        'provider' => 'qrisly',
                        'payment_ref' => '9404',
                        'payment_instructions' => [
                            'payment_id' => '9404',
                            'payment_type' => 'qris',
                            'provider' => 'qrisly',
                        ],
                    ],
                ],
            ],
            [],
            ['amount' => 5000, 'reference' => '9404'],
            $product,
        );
        $product->decreaseStock($this->fixtures->inventory->id, 1);

        ['order' => $customerOrder] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'payment_method_id' => $this->fixtures->paymentMethods['qris']->id,
                'price_amount' => 5000,
                'metadata' => [
                    'komerce' => [
                        'payment_type' => 'qris',
                        'provider' => 'qrisly',
                        'payment_ref' => '9405',
                        'payment_instructions' => [
                            'payment_id' => '9405',
                            'payment_type' => 'qris',
                            'provider' => 'qrisly',
                        ],
                    ],
                ],
            ],
            [],
            ['amount' => 5000, 'reference' => '9405'],
            $product,
        );
        $product->decreaseStock($this->fixtures->inventory->id, 1);

        $cancelledByStaff = resolve(CancelOrderWithProviders::class)->handle($staffOrder);
        $this->assertSame(OrderStatus::Cancelled, $cancelledByStaff->status);
        $this->assertSame(PaymentStatus::Voided, $cancelledByStaff->payment_status);

        $this->apiCancelOrder($customer, $customerOrder->number, 'Ganti metode')
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Cancelled->value)
            ->assertJsonPath('data.payment_status', PaymentStatus::Voided->value);

        $this->assertSame(10, $product->fresh()->stockInventory($this->fixtures->inventory->id));
        Http::assertNothingSent();

        // 2. Fail-closed preserved when QRISLY IS enabled and status lookup fails (500)
        $this->applyTier('payment_and_qrisly')
            ->withQrislyStatus('9406', 'paid', 5001, 500)
            ->apply();

        ['order' => $activeQrislyOrder] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'payment_method_id' => $this->fixtures->paymentMethods['qris']->id,
                'price_amount' => 5000,
                'metadata' => [
                    'komerce' => [
                        'payment_type' => 'qris',
                        'provider' => 'qrisly',
                        'payment_ref' => '9406',
                        'payment_instructions' => [
                            'payment_id' => '9406',
                            'payment_type' => 'qris',
                            'provider' => 'qrisly',
                        ],
                    ],
                ],
            ],
            [],
            ['amount' => 5000, 'reference' => '9406'],
            $product,
        );

        $this->apiCancelOrder($customer, $activeQrislyOrder->number)->assertStatus(422);
        $this->assertSame(OrderStatus::New, $activeQrislyOrder->fresh()->status);

        try {
            resolve(CancelOrderWithProviders::class)->handle($activeQrislyOrder);
            $this->fail('Expected RuntimeException when QRISLY is enabled and lookup fails.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(OrderStatus::New, $activeQrislyOrder->fresh()->status);

        // 3. Mid-flight passive 401 revocation on QRISLY while Payment API remains active -> subsequent cancel succeeds locally
        $this->applyTier('payment_and_qrisly')
            ->withQrislyEntitled(false, 401, 'Invalid API Key')
            ->apply();

        $this->apiCancelOrder($customer, $activeQrislyOrder->number)->assertStatus(422);
        $this->assertFalse($this->komerceProductEnabled('qrisly'));
        $this->assertTrue($this->komerceProductEnabled('payment'));

        $this->apiCancelOrder($customer, $activeQrislyOrder->number)
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Cancelled->value);
    }
}
