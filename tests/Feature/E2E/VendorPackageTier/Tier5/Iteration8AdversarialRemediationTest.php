<?php

declare(strict_types=1);

namespace Tests\Feature\E2E\VendorPackageTier\Tier5;

use App\Actions\Account\CancelCustomerOrder;
use App\Actions\Orders\CancelOrderWithProviders;
use App\Actions\Orders\SyncOrderPayment;
use App\Actions\Shipping\NormalizeShipmentStatus;
use App\Actions\Shipping\ReshipOrderShipment;
use App\Actions\Shipping\SyncOrderShippingFromShipments;
use App\Integrations\Komerce\Exceptions\KomerceNotConfiguredException;
use App\Integrations\Komerce\Komerce;
use App\Integrations\Komerce\Services\ShippingDeliveryClient;
use App\Integrations\Komerce\Support\KomerceCapabilities;
use App\Livewire\Shopper\OrderFulfillment;
use App\Livewire\Shopper\SlideOvers\CreateShippingLabel;
use App\Models\DiscountRedemption;
use App\Models\OrderShipment;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Shopper\Core\Enum\DiscountApplyTo;
use Shopper\Core\Enum\DiscountEligibility;
use Shopper\Core\Enum\DiscountRequirement;
use Shopper\Core\Enum\DiscountType;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Enum\ShippingStatus;
use Shopper\Core\Models\Discount;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\OrderItem;
use Shopper\Payment\Enum\TransactionStatus;
use Shopper\Payment\Facades\Payment;
use Tests\Feature\E2E\VendorPackageTier\Support\VendorPackageTierTestCase;

final class Iteration8AdversarialRemediationTest extends VendorPackageTierTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireModules('Komerce');
    }

    public function test_bug1_delivery_webhook_self_heals_false_negative_unentitled_capability_while_normal_calls_stay_gated(): void
    {
        $this->applyTier('komship')
            ->withDeliveryDetailResponse([
                'order_no' => 'RO-HEAL-1',
                'airway_bill' => 'JNE-HEAL-1',
                'awb' => 'JNE-HEAL-1',
                'last_status' => 'DELIVERED',
            ])
            ->withDeliveryTrackingResponse([
                'airway_bill' => 'JNE-HEAL-1',
                'last_status' => 'DELIVERED',
                'history' => [
                    [
                        'desc' => 'Delivered to recipient',
                        'date' => '2026-11-26 12:00:00',
                        'code' => '200',
                        'status' => 'DELIVERED',
                    ],
                ],
            ])
            ->apply();

        $customer = $this->fixtures->createVerifiedCustomer();
        ['shipment' => $shipment] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::Processing,
                'payment_status' => PaymentStatus::Paid,
                'shipping_status' => ShippingStatus::Shipped,
            ],
            [
                'driver' => 'komerce',
                'status' => NormalizeShipmentStatus::PICKED_UP,
                'external_reference' => 'RO-HEAL-1',
                'awb' => null,
                'tracking_number' => null,
                'metadata' => ['komerce' => ['order_no' => 'RO-HEAL-1']],
            ],
        );

        // Passive false negative locks out shipping_delivery.
        $this->observeVendorCapability('shipping_delivery', 401, 'Invalid API key');
        $this->assertFalse(resolve(KomerceCapabilities::class)->entitled('shipping_delivery'));
        $this->assertFalse(Komerce::shippingDeliveryEnabled());

        // Normal non-webhook calls still respect entitled=false and send zero outbound requests.
        try {
            resolve(ShippingDeliveryClient::class)->detailOrder('RO-HEAL-1');
            $this->fail('Expected KomerceNotConfiguredException when shipping_delivery is unentitled.');
        } catch (KomerceNotConfiguredException) {
            // Expected.
        }
        Http::assertNothingSent();

        // Webhook read-back bypasses entitlement gate, sends detail-order, updates shipment, and restores entitled=true.
        $this->postDeliveryWebhook([
            'order_no' => 'RO-HEAL-1',
            'cnote' => 'JNE-HEAL-1',
            'status' => 'DELIVERED',
        ])->assertOk()->assertJson(['status' => 'handled']);

        Http::assertSent(fn (Request $req): bool => str_contains($req->url(), '/order/api/v1/orders/detail'));
        $this->assertTrue(resolve(KomerceCapabilities::class)->entitled('shipping_delivery'));
        $this->assertTrue(Komerce::shippingDeliveryEnabled());

        $shipment->refresh();
        $this->assertSame('JNE-HEAL-1', $shipment->awb);
        $this->assertSame(NormalizeShipmentStatus::DELIVERED, $shipment->status);
    }

    public function test_bug1_payment_and_qrisly_webhooks_self_heal_false_negative_capabilities_and_fail_closed_when_still_401(): void
    {
        // 1. Payment webhook self-healing ([PROBE 1B])
        $this->applyTier('komship')
            ->withPaymentStatus('KOMPAY-HEAL-1', 'PAID', 112000)
            ->apply();

        $customer = $this->fixtures->createVerifiedCustomer();
        ['order' => $paymentOrder] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'number' => 'ORD-HEAL-PAY-1',
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'shipping_status' => ShippingStatus::Unfulfilled,
                'price_amount' => 112000,
                'metadata' => [
                    'komerce' => [
                        'payment_type' => 'bank_transfer',
                        'provider' => 'payment_api',
                        'payment_ref' => 'KOMPAY-HEAL-1',
                    ],
                ],
            ],
            [],
            ['reference' => 'KOMPAY-HEAL-1', 'metadata' => ['komerce_provider' => 'payment_api']],
        );

        $this->observeVendorCapability('payment', 401, 'Invalid API key');
        $this->assertFalse(resolve(KomerceCapabilities::class)->entitled('payment'));
        $this->assertFalse(Komerce::paymentEnabled());

        // Normal sync still respects entitled=false (0 outbound HTTP calls).
        $this->assertSame(SyncOrderPayment::NOT_PAID, resolve(SyncOrderPayment::class)->handle($paymentOrder));
        Http::assertNothingSent();

        $this->postSignedKomercePaymentWebhook([
            'payment_id' => 'KOMPAY-HEAL-1',
            'order_id' => $paymentOrder->number,
            'status' => 'PAID',
            'amount' => 112000,
        ])->assertOk()->assertJson(['status' => 'handled']);

        Http::assertSent(fn (Request $req): bool => str_contains($req->url(), '/api/v1/user/payment/status/KOMPAY-HEAL-1'));
        $this->assertTrue(resolve(KomerceCapabilities::class)->entitled('payment'));
        $this->assertTrue(Komerce::paymentEnabled());
        $this->assertSame(PaymentStatus::Paid, $paymentOrder->fresh()->payment_status);

        // 2. QRISLY webhook self-healing ([PROBE 1C])
        $this->applyTier('qrisly_only')
            ->withQrislyStatus('QR-HEAL-1', 'paid', 50001)
            ->apply();

        ['order' => $qrislyOrder] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'number' => 'ORD-HEAL-QR-1',
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'shipping_status' => ShippingStatus::Unfulfilled,
                'price_amount' => 50000,
                'payment_method_id' => $this->fixtures->paymentMethods['qris']->id,
                'metadata' => [
                    'komerce' => [
                        'payment_type' => 'qris',
                        'provider' => 'qrisly',
                        'payment_ref' => 'QR-HEAL-1',
                        'qrisly_history_id' => 'QR-HEAL-1',
                    ],
                ],
            ],
            [],
            ['reference' => 'QR-HEAL-1', 'metadata' => ['komerce_provider' => 'qrisly', 'komerce_payment_type' => 'qris']],
        );

        $this->observeVendorCapability('qrisly', 401, 'Invalid API key');
        $this->assertFalse(resolve(KomerceCapabilities::class)->entitled('qrisly'));
        $this->assertFalse(Komerce::qrislyEnabled());

        $this->postQrislyWebhook([
            'event' => 'payment.success',
            'data' => ['history_id' => 'QR-HEAL-1', 'status' => 'paid'],
        ])->assertOk()->assertJson([
            'success' => true,
            'message' => 'Webhook received successfully',
        ]);

        Http::assertSent(fn (Request $req): bool => str_contains($req->url(), '/api/v1/qrisly/payment-status/QR-HEAL-1'));
        $this->assertTrue(resolve(KomerceCapabilities::class)->entitled('qrisly'));
        $this->assertTrue(Komerce::qrislyEnabled());
        $this->assertSame(PaymentStatus::Paid, $qrislyOrder->fresh()->payment_status);

        // 3. Negative control: when outbound verification STILL returns 401, webhook returns 503 and stays unentitled.
        $this->applyTier('qrisly_only')
            ->withQrislyEntitled(false, 401, 'Invalid API key')
            ->apply();

        ['order' => $stillDeniedOrder] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'number' => 'ORD-HEAL-QR-401',
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'shipping_status' => ShippingStatus::Unfulfilled,
                'price_amount' => 50000,
                'payment_method_id' => $this->fixtures->paymentMethods['qris']->id,
                'metadata' => [
                    'komerce' => [
                        'payment_type' => 'qris',
                        'provider' => 'qrisly',
                        'payment_ref' => 'QR-STILL-401',
                        'qrisly_history_id' => 'QR-STILL-401',
                    ],
                ],
            ],
            [],
            ['reference' => 'QR-STILL-401', 'metadata' => ['komerce_provider' => 'qrisly']],
        );

        $this->observeVendorCapability('qrisly', 401, 'Invalid API key');

        $this->postQrislyWebhook([
            'event' => 'payment.success',
            'data' => ['history_id' => 'QR-STILL-401', 'status' => 'paid'],
        ])->assertStatus(503)->assertJson(['status' => 'lookup_failed']);

        Http::assertSent(fn (Request $req): bool => str_contains($req->url(), '/api/v1/qrisly/payment-status/QR-STILL-401'));
        $this->assertFalse(resolve(KomerceCapabilities::class)->entitled('qrisly'));
        $this->assertSame(PaymentStatus::Pending, $stillDeniedOrder->fresh()->payment_status);
    }

    public function test_bug2_qrisly_retrieve_and_expire_unpaid_work_on_qrisly_only_and_komship_even_when_instructions_omit_provider(): void
    {
        foreach (['qrisly_only', 'komship'] as $tier) {
            $this->applyTier($tier)
                ->withQrislyStatus('QR-EXP-'.$tier, 'unpaid', 50001)
                ->withQrislyStatus('QR-EXP-C-'.$tier, 'unpaid', 50001)
                ->apply();

            $customer = $this->fixtures->createVerifiedCustomer();
            $product = $this->fixtures->createStockedProduct(50000, 10, 500, $this->fixtures->inventory);

            // [PROBE 4C]: Direct Payment::driver('komerce')->retrievePayment() & cancelPayment() on QRISLY reference.
            ['order' => $probeCOrder] = $this->fixtures->createOrderWithShipment(
                $customer,
                [
                    'number' => 'ORD-QR-C-'.$tier,
                    'status' => OrderStatus::New,
                    'payment_status' => PaymentStatus::Pending,
                    'shipping_status' => ShippingStatus::Unfulfilled,
                    'price_amount' => 50000,
                    'payment_method_id' => $this->fixtures->paymentMethods['qris']->id,
                    'metadata' => [
                        'komerce' => [
                            'payment_type' => 'qris',
                            'provider' => 'qrisly',
                            'payment_ref' => 'QR-EXP-C-'.$tier,
                            'qrisly_history_id' => 'QR-EXP-C-'.$tier,
                        ],
                    ],
                ],
                [],
                ['reference' => 'QR-EXP-C-'.$tier, 'metadata' => ['komerce_provider' => 'qrisly']],
                $product,
            );

            $retrieved = Payment::driver('komerce')->retrievePayment('QR-EXP-C-'.$tier);
            $this->assertSame('pending', $retrieved->status);
            $this->assertSame('qrisly', $retrieved->data['provider'] ?? null);

            $cancelledDirect = Payment::driver('komerce')->cancelPayment('QR-EXP-C-'.$tier);
            $this->assertTrue($cancelledDirect->success);
            $this->assertSame('canceled', $cancelledDirect->status);

            // [PROBE 4D]: 25h-old expired QRISLY order whose payment_instructions omits 'provider' (plus reserved stock & coupon).
            $discount = Discount::query()->create([
                'code' => 'QRIS25H-'.strtoupper($tier),
                'is_active' => true,
                'type' => DiscountType::FixedAmount,
                'value' => 5000,
                'apply_to' => DiscountApplyTo::Order,
                'eligibility' => DiscountEligibility::Everyone,
                'min_required' => DiscountRequirement::None->value,
                'start_at' => now()->subDay(),
                'total_use' => 1,
            ]);

            ['order' => $expiredOrder, 'shipment' => $expiredShipment, 'transaction' => $expiredTx] = $this->fixtures->createOrderWithShipment(
                $customer,
                [
                    'number' => 'ORD-QR-'.$tier,
                    'status' => OrderStatus::New,
                    'payment_status' => PaymentStatus::Pending,
                    'shipping_status' => ShippingStatus::Unfulfilled,
                    'price_amount' => 50000,
                    'discount_id' => $discount->id,
                    'payment_method_id' => $this->fixtures->paymentMethods['qris']->id,
                    'created_at' => now()->subHours(25),
                    'metadata' => [
                        'komerce' => [
                            'payment_type' => 'qris',
                            'provider' => 'qrisly',
                            'payment_ref' => 'QR-EXP-'.$tier,
                            'qrisly_history_id' => 'QR-EXP-'.$tier,
                            'expiry_date' => now()->subHour()->toDateTimeString(),
                            'payment_instructions' => [
                                'payment_id' => 'QR-EXP-'.$tier,
                                'payment_type' => 'qris',
                                'qris_string' => '000201010212QRISLY',
                                'expiry_date' => now()->subHour()->toDateTimeString(),
                                'amount' => 50001,
                                // Intentionally omit 'provider' to guard against ResolveKomercePaymentInstructions fabrication.
                            ],
                        ],
                    ],
                ],
                [
                    'driver' => 'manual',
                    'status' => NormalizeShipmentStatus::PENDING,
                ],
                [
                    'reference' => 'QR-EXP-'.$tier,
                    'metadata' => [
                        'komerce_provider' => 'qrisly',
                        'komerce_payment_type' => 'qris',
                        'expiry_date' => now()->subHour()->toDateTimeString(),
                    ],
                ],
                $product,
            );
            // Unset payment_instructions.provider that array_replace_recursive inherited from defaultOrderMeta.
            $rawMeta = json_decode((string) $expiredOrder->getAttribute('metadata'), true);
            unset($rawMeta['komerce']['payment_instructions']['provider']);
            $expiredOrder->forceFill(['metadata' => json_encode($rawMeta, JSON_THROW_ON_ERROR)])->save();

            DiscountRedemption::record($discount->id, $expiredOrder->id, (string) $customer->email);
            $product->decreaseStock($this->fixtures->inventory->id, 1);

            // Prevent probeCOrder from interfering with orders:expire-unpaid count.
            $probeCOrder->forceFill(['status' => OrderStatus::Cancelled])->save();

            $this->artisan('orders:expire-unpaid')
                ->expectsOutputToContain('Expired 1 order(s); skipped 0.')
                ->assertSuccessful();

            $expiredOrder->refresh();
            $this->assertSame(OrderStatus::Cancelled, $expiredOrder->status);
            $this->assertSame(PaymentStatus::Voided, $expiredOrder->payment_status);
            $this->assertNull($expiredOrder->discount_id);
            $this->assertSame(TransactionStatus::Failed, $expiredTx->fresh()->status);
            $this->assertSame(NormalizeShipmentStatus::CANCELLED, $expiredShipment->fresh()->status);
            $this->assertSame(10, (int) $product->fresh()->stockInventory($this->fixtures->inventory->id));
            $this->assertSame(0, (int) $discount->fresh()->total_use);

            Http::assertSent(fn (Request $req): bool => str_contains($req->url(), '/api/v1/qrisly/payment-status/QR-EXP-'.$tier));
            Http::assertNotSent(fn (Request $req): bool => str_contains($req->url(), 'payment.example.test'));
        }
    }

    public function test_bug2_customer_and_staff_cancel_of_pending_qrisly_order_cancels_cleanly_and_never_returns_200_new(): void
    {
        foreach (['qrisly_only', 'komship'] as $tier) {
            $this->applyTier($tier)
                ->withQrislyStatus('QR-P8-'.$tier, 'PENDING', 50001)
                ->withQrislyStatus('QR-P8-STAFF-'.$tier, 'PENDING', 50001)
                ->withQrislyStatus('QR-P8-MISMATCH-'.$tier, 'PAID', 10000)
                ->apply();

            $customer = $this->fixtures->createVerifiedCustomer();
            $product = $this->fixtures->createStockedProduct(50000, 10, 500, $this->fixtures->inventory);

            // 1. Customer self-cancel via POST /api/v1/orders/{number}/cancel ([PROBE 7] / [PROBE 8])
            ['order' => $apiOrder, 'shipment' => $apiShipment, 'transaction' => $apiTx] = $this->fixtures->createOrderWithShipment(
                $customer,
                [
                    'number' => 'ORD-P8-QR-'.$tier,
                    'status' => OrderStatus::New,
                    'payment_status' => PaymentStatus::Pending,
                    'shipping_status' => ShippingStatus::Unfulfilled,
                    'price_amount' => 50000,
                    'payment_method_id' => $this->fixtures->paymentMethods['qris']->id,
                    'metadata' => [
                        'komerce' => [
                            'payment_type' => 'qris',
                            'provider' => 'qrisly',
                            'payment_ref' => 'QR-P8-'.$tier,
                            'qrisly_history_id' => 'QR-P8-'.$tier,
                        ],
                    ],
                ],
                [
                    'driver' => 'manual',
                    'status' => NormalizeShipmentStatus::PENDING,
                ],
                [
                    'reference' => 'QR-P8-'.$tier,
                    'metadata' => ['komerce_provider' => 'qrisly', 'komerce_payment_type' => 'qris'],
                ],
                $product,
            );
            $product->decreaseStock($this->fixtures->inventory->id, 1);

            $this->apiCancelOrder($customer, $apiOrder->number, 'Ganti metode')
                ->assertOk()
                ->assertJsonPath('data.status', 'cancelled')
                ->assertJsonPath('data.payment_status', 'voided')
                ->assertJsonPath('data.can_cancel', false);

            $this->assertSame(OrderStatus::Cancelled, $apiOrder->fresh()->status);
            $this->assertSame(PaymentStatus::Voided, $apiOrder->fresh()->payment_status);
            $this->assertSame(TransactionStatus::Failed, $apiTx->fresh()->status);
            $this->assertSame(NormalizeShipmentStatus::CANCELLED, $apiShipment->fresh()->status);
            $this->assertSame(10, (int) $product->fresh()->stockInventory($this->fixtures->inventory->id));
            Http::assertSent(fn (Request $req): bool => str_contains($req->url(), '/api/v1/qrisly/payment-status/QR-P8-'.$tier));
            Http::assertNotSent(fn (Request $req): bool => str_contains($req->url(), 'payment.example.test'));

            // 2. Direct CancelOrderWithProviders on pending QRISLY order
            ['order' => $staffOrder] = $this->fixtures->createOrderWithShipment(
                $customer,
                [
                    'number' => 'ORD-P8-STAFF-'.$tier,
                    'status' => OrderStatus::New,
                    'payment_status' => PaymentStatus::Pending,
                    'shipping_status' => ShippingStatus::Unfulfilled,
                    'price_amount' => 50000,
                    'payment_method_id' => $this->fixtures->paymentMethods['qris']->id,
                    'metadata' => [
                        'komerce' => [
                            'payment_type' => 'qris',
                            'provider' => 'qrisly',
                            'payment_ref' => 'QR-P8-STAFF-'.$tier,
                            'qrisly_history_id' => 'QR-P8-STAFF-'.$tier,
                        ],
                    ],
                ],
                [
                    'driver' => 'manual',
                    'status' => NormalizeShipmentStatus::PENDING,
                ],
                [
                    'reference' => 'QR-P8-STAFF-'.$tier,
                    'metadata' => ['komerce_provider' => 'qrisly', 'komerce_payment_type' => 'qris'],
                ],
                $product,
            );

            $cancelledStaff = resolve(CancelOrderWithProviders::class)->handle($staffOrder->fresh(), true, 'Dibatalkan staff');
            $this->assertSame(OrderStatus::Cancelled, $cancelledStaff->status);
            $this->assertSame(PaymentStatus::Voided, $cancelledStaff->payment_status);

            // 3. Amount-mismatch QRISLY order cannot return HTTP 200 with status='new' on cancel
            ['order' => $mismatchOrder] = $this->fixtures->createOrderWithShipment(
                $customer,
                [
                    'number' => 'ORD-P8-MM-'.$tier,
                    'status' => OrderStatus::New,
                    'payment_status' => PaymentStatus::Pending,
                    'shipping_status' => ShippingStatus::Unfulfilled,
                    'price_amount' => 50000,
                    'payment_method_id' => $this->fixtures->paymentMethods['qris']->id,
                    'metadata' => [
                        'komerce' => [
                            'payment_type' => 'qris',
                            'provider' => 'qrisly',
                            'payment_ref' => 'QR-P8-MISMATCH-'.$tier,
                            'qrisly_history_id' => 'QR-P8-MISMATCH-'.$tier,
                        ],
                    ],
                ],
                [
                    'driver' => 'manual',
                    'status' => NormalizeShipmentStatus::PENDING,
                ],
                [
                    'reference' => 'QR-P8-MISMATCH-'.$tier,
                    'metadata' => ['komerce_provider' => 'qrisly', 'komerce_payment_type' => 'qris'],
                ],
                $product,
            );

            $this->apiCancelOrder($customer, $mismatchOrder->number)
                ->assertStatus(422);
            $this->assertSame(OrderStatus::New, $mismatchOrder->fresh()->status);

            try {
                resolve(CancelCustomerOrder::class)->handle($mismatchOrder->fresh(), $customer);
                $this->fail('Expected ValidationException when order could not be cancelled due to payment mismatch.');
            } catch (ValidationException) {
                // Expected.
            }
        }
    }

    public function test_bug2_real_api_checkout_qrisly_order_can_be_cancelled_and_expired_end_to_end(): void
    {
        foreach (['qrisly_only', 'komship'] as $tier) {
            $this->applyTier($tier)
                ->withQrislyGenerate(['history_id' => 9101, 'final_amount' => 115001])
                ->withQrislyStatus(9101, 'PENDING', 115001)
                ->apply();

            $customer = $this->fixtures->createVerifiedCustomer();
            $product = $this->fixtures->createStockedProduct(100000, 5, 500, $this->fixtures->inventory);

            $this->apiCustomerWithCart($product, 1, $customer);
            $this->apiSaveShippingAddress($customer)->assertOk();
            $serviceCode = $tier === 'qrisly_only'
                ? (string) $this->fixtures->manualOption->id
                : 'ide:IDFLAT';
            $this->apiPickShippingOption($customer, $serviceCode)->assertOk();

            $placed = $this->apiPlaceOrder($customer, $this->fixtures->paymentMethods['qris']->id)
                ->assertCreated()
                ->assertJsonPath('data.payment_action.kind', 'qr')
                ->assertJsonPath('data.payment_action.data.provider', 'qrisly');

            $orderNumber = (string) $placed->json('data.number');
            $this->assertSame(4, (int) $product->fresh()->stockInventory($this->fixtures->inventory->id));

            $this->apiCancelOrder($customer, $orderNumber, 'Berubah pikiran')
                ->assertOk()
                ->assertJsonPath('data.status', 'cancelled')
                ->assertJsonPath('data.payment_status', 'voided')
                ->assertJsonPath('data.can_cancel', false);

            $order = Order::query()->where('number', $orderNumber)->firstOrFail();
            $this->assertSame(OrderStatus::Cancelled, $order->status);
            $this->assertSame(PaymentStatus::Voided, $order->payment_status);
            $this->assertSame(5, (int) $product->fresh()->stockInventory($this->fixtures->inventory->id));
            Http::assertSent(fn (Request $req): bool => str_contains($req->url(), '/api/v1/qrisly/payment-status/9101'));
            Http::assertNotSent(fn (Request $req): bool => str_contains($req->url(), '/api/v1/user/payment/'));
        }
    }

    public function test_bug3_split_stock_single_sku_komerce_and_manual_unblocks_manual_awb_and_confirm_received(): void
    {
        // [PROBE 2A]: 1 SKU qty 3 split across komerce (qty 2) + manual (qty 1); komerce fulfilled first.
        $this->applyTier('komship');
        $admin = $this->fixtures->createAdmin();
        $customer = $this->fixtures->createVerifiedCustomer();
        $secondInventory = $this->fixtures->ensureSecondInventory();

        $product = $this->fixtures->createStockedProduct(100000, 10, 500, $this->fixtures->inventory);
        $product->mutateStock($secondInventory->id, 10);

        ['order' => $order, 'shipment' => $komerceShipment] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'number' => 'ORD-SPLIT-2A',
                'status' => OrderStatus::Processing,
                'payment_status' => PaymentStatus::Paid,
                'shipping_status' => ShippingStatus::Unfulfilled,
                'shipping_option_id' => null,
                'price_amount' => 327000,
            ],
            [
                'driver' => 'komerce',
                'carrier_id' => $this->fixtures->carriers['jne']->id,
                'carrier_code' => 'jne',
                'carrier_name' => 'JNE Express',
                'service_code' => 'REG',
                'service_name' => 'REG',
                'status' => NormalizeShipmentStatus::LABELED,
                'awb' => 'KOM-SPLIT-2A',
                'tracking_number' => 'KOM-SPLIT-2A',
                'external_reference' => 'RO-SPLIT-2A',
                'cost' => 12000,
            ],
            null,
            $product,
            $this->fixtures->inventory,
        );

        // Make the single OrderItem qty 3, komerce line qty 2, manual line qty 1.
        $singleItem = OrderItem::query()->where('order_id', $order->id)->sole();
        $singleItem->forceFill(['quantity' => 3])->save();
        $komerceShipment->lines()->update(['qty' => 2]);

        $manualShipment = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $secondInventory->id,
            'driver' => 'manual',
            'carrier_id' => $this->fixtures->carriers['manual']->id,
            'carrier_code' => 'manual',
            'carrier_name' => 'Kurir Toko',
            'service_code' => 'Reguler',
            'service_name' => 'Reguler',
            'status' => NormalizeShipmentStatus::PENDING,
            'cost' => 15000,
            'currency_code' => 'IDR',
        ]);
        $manualShipment->lines()->create([
            'purchasable_type' => $product->getMorphClass(),
            'purchasable_id' => $product->id,
            'qty' => 1,
        ]);

        // Komerce fulfillment sync binds the ONLY OrderItem's order_shipping_id.
        resolve(SyncOrderShippingFromShipments::class)->handle($order->refresh());
        $this->assertNotNull($singleItem->refresh()->order_shipping_id);

        // Customer still cannot confirm receipt while manual shipment lacks AWB.
        $this->apiConfirmReceived($customer, $order->number)->assertStatus(422);

        // OrderFulfillment MUST still report unfulfilled items so manual AWB entry is available.
        $fulfillment = $this->cpanelOrderFulfillment($admin, $order->refresh());
        $this->assertTrue($fulfillment->instance()->hasUnfulfilledItems());
        $fulfillment->assertSee(__('shopper::pages/orders.create_shipping_label'), false);

        // Slide-over exposes the single OrderItem and mirrors onto manualShipment without touching komerceShipment.
        $slideOver = $this->cpanelCreateShippingLabel($admin, $order->refresh())
            ->assertSet('data.carrier_id', $this->fixtures->carriers['manual']->id);
        $this->assertSame([$singleItem->id], $slideOver->instance()->unfulfilledItems->pluck('id')->all());

        $slideOver->set('data.tracking_number', 'MANUAL-SPLIT-2A')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('MANUAL-SPLIT-2A', $manualShipment->refresh()->awb);
        $this->assertSame(NormalizeShipmentStatus::LABELED, $manualShipment->status);
        $this->assertSame('KOM-SPLIT-2A', $komerceShipment->refresh()->awb);

        $this->assertFalse($this->cpanelOrderFulfillment($admin, $order->refresh())->instance()->hasUnfulfilledItems());

        $this->apiConfirmReceived($customer, $order->number)->assertOk();
        $this->assertSame(NormalizeShipmentStatus::DELIVERED, $komerceShipment->refresh()->status);
        $this->assertSame(NormalizeShipmentStatus::DELIVERED, $manualShipment->refresh()->status);
        $this->assertSame(OrderStatus::Completed, $order->refresh()->status);
    }

    public function test_bug3_dual_manual_warehouses_with_single_order_item_labels_both_and_completes(): void
    {
        // [PROBE 2B] & [PROBE 2B-2]: 1 SKU qty 3 split across 2 manual warehouses (even with null carrier_id).
        $this->applyTier('keyless');
        $admin = $this->fixtures->createAdmin();
        $customer = $this->fixtures->createVerifiedCustomer();
        $secondInventory = $this->fixtures->ensureSecondInventory();

        $product = $this->fixtures->createStockedProduct(100000, 10, 500, $this->fixtures->inventory);
        $product->mutateStock($secondInventory->id, 10);

        ['order' => $order, 'shipment' => $s1] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'number' => 'ORD-DUAL-MAN-2B',
                'status' => OrderStatus::Processing,
                'payment_status' => PaymentStatus::Paid,
                'shipping_status' => ShippingStatus::Unfulfilled,
                'shipping_option_id' => null,
                'price_amount' => 330000,
            ],
            [
                'driver' => 'manual',
                'carrier_id' => null,
                'carrier_code' => 'manual',
                'carrier_name' => 'Kurir Toko',
                'service_code' => 'Reguler',
                'service_name' => 'Reguler',
                'status' => NormalizeShipmentStatus::PENDING,
                'awb' => null,
                'tracking_number' => null,
                'cost' => 15000,
            ],
            null,
            $product,
            $this->fixtures->inventory,
        );

        $singleItem = OrderItem::query()->where('order_id', $order->id)->sole();
        $singleItem->forceFill(['quantity' => 3])->save();
        $s1->lines()->update(['qty' => 2]);

        $s2 = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $secondInventory->id,
            'driver' => 'manual',
            'carrier_id' => null,
            'carrier_code' => 'manual',
            'carrier_name' => 'Kurir Toko',
            'service_code' => 'Reguler',
            'service_name' => 'Reguler',
            'status' => NormalizeShipmentStatus::PENDING,
            'cost' => 15000,
            'currency_code' => 'IDR',
        ]);
        $s2->lines()->create([
            'purchasable_type' => $product->getMorphClass(),
            'purchasable_id' => $product->id,
            'qty' => 1,
        ]);

        // Step 1: Label first manual shipment s1 via CreateShippingLabel.
        $this->assertTrue($this->cpanelOrderFulfillment($admin, $order->fresh())->instance()->hasUnfulfilledItems());

        $slideOver1 = $this->cpanelCreateShippingLabel($admin, $order->fresh());
        $this->assertSame($s1->id, $slideOver1->instance()->targetShipmentId);
        $slideOver1->set('data.tracking_number', 'MAN-DUAL-S1')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('MAN-DUAL-S1', $s1->refresh()->awb);
        $this->assertNull($s2->refresh()->awb);
        $this->assertNotNull($singleItem->refresh()->order_shipping_id);

        // Confirm receipt still blocked while s2 has no AWB.
        $this->apiConfirmReceived($customer, $order->number)->assertStatus(422);

        // Step 2: OrderFulfillment MUST still show Create Shipping Label for s2 despite order_shipping_id being bound.
        $this->assertTrue($this->cpanelOrderFulfillment($admin, $order->fresh())->instance()->hasUnfulfilledItems());

        $slideOver2 = $this->cpanelCreateShippingLabel($admin, $order->fresh());
        $this->assertSame($s2->id, $slideOver2->instance()->targetShipmentId);
        $this->assertSame([$singleItem->id], $slideOver2->instance()->unfulfilledItems->pluck('id')->all());

        $slideOver2->set('data.tracking_number', 'MAN-DUAL-S2')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('MAN-DUAL-S1', $s1->refresh()->awb);
        $this->assertSame('MAN-DUAL-S2', $s2->refresh()->awb);
        $this->assertFalse($this->cpanelOrderFulfillment($admin, $order->fresh())->instance()->hasUnfulfilledItems());

        // Step 3: Customer can now confirm receipt and complete the order.
        $this->apiConfirmReceived($customer, $order->number)->assertOk();
        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
    }

    public function test_bug3_reshipped_returned_shipments_on_starter_and_keyless_unblock_manual_awb_and_confirm_received(): void
    {
        foreach ([
            ['tier' => 'starter', 'driver' => 'komerce', 'code' => '5B-RTS'],
            ['tier' => 'keyless', 'driver' => 'manual', 'code' => '6B'],
        ] as $case) {
            $this->applyTier($case['tier']);
            $admin = $this->fixtures->createAdmin();
            $customer = $this->fixtures->createVerifiedCustomer();

            ['order' => $order, 'shipment' => $returnedShipment] = $this->fixtures->createOrderWithShipment(
                $customer,
                [
                    'number' => 'ORD-RTS-'.$case['code'],
                    'status' => OrderStatus::Processing,
                    'payment_status' => PaymentStatus::Paid,
                    'shipping_status' => ShippingStatus::Unfulfilled,
                    'shipping_option_id' => null,
                    'price_amount' => 115000,
                ],
                [
                    'driver' => $case['driver'],
                    'carrier_id' => $case['driver'] === 'komerce'
                        ? $this->fixtures->carriers['jne']->id
                        : $this->fixtures->carriers['manual']->id,
                    'carrier_code' => $case['driver'] === 'komerce' ? 'jne' : 'manual',
                    'status' => NormalizeShipmentStatus::RETURNED,
                    'awb' => 'OLD-RTS-'.$case['code'],
                    'tracking_number' => 'OLD-RTS-'.$case['code'],
                    'cost' => 15000,
                ],
            );

            // Mirror returned shipment so order_items.order_shipping_id is populated & fulfillment_status=Cancelled.
            resolve(SyncOrderShippingFromShipments::class)->handle($order->fresh());
            $this->assertSame(ShippingStatus::Returned, $order->fresh()->shipping_status);

            // Staff triggers reship.
            $reship = resolve(ReshipOrderShipment::class)->handle($returnedShipment->fresh(), $admin);
            $this->assertSame(NormalizeShipmentStatus::PENDING, $reship->status);
            $this->assertNull($reship->awb);

            // Confirm receipt is blocked until the reshipment gets a new AWB.
            $this->apiConfirmReceived($customer, $order->number)->assertStatus(422);

            // OrderFulfillment exposes manual label button and CreateShippingLabel labels the reshipment.
            $fulfillment = $this->cpanelOrderFulfillment($admin, $order->fresh());
            $this->assertTrue($fulfillment->instance()->hasUnfulfilledItems());
            $fulfillment->assertSee(__('shopper::pages/orders.create_shipping_label'), false);

            $slideOver = $this->cpanelCreateShippingLabel($admin, $order->fresh());
            $this->assertSame($reship->id, $slideOver->instance()->targetShipmentId);
            $this->assertCount(1, $slideOver->instance()->unfulfilledItems);

            $slideOver->set('data.tracking_number', 'RESHIP-AWB-'.$case['code'])
                ->call('save')
                ->assertHasNoErrors();

            $this->assertSame('RESHIP-AWB-'.$case['code'], $reship->refresh()->awb);
            $this->assertSame('OLD-RTS-'.$case['code'], $returnedShipment->refresh()->awb);
            $this->assertFalse($this->cpanelOrderFulfillment($admin, $order->fresh())->instance()->hasUnfulfilledItems());

            // Customer can now confirm receipt and complete the order.
            $this->apiConfirmReceived($customer, $order->number)->assertOk();
            $this->assertSame(NormalizeShipmentStatus::DELIVERED, $reship->refresh()->status);
            $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
        }
    }

    public function test_bug3_non_regression_probe_2d_separate_skus_mixed_komerce_and_manual_filters_to_manual_sku_only(): void
    {
        $this->applyTier('komship');
        $admin = $this->fixtures->createAdmin();
        $customer = $this->fixtures->createVerifiedCustomer();
        $secondInventory = $this->fixtures->ensureSecondInventory();

        $komerceProduct = $this->fixtures->createStockedProduct(100000, 10, 500, $this->fixtures->inventory);
        $manualProduct = $this->fixtures->createStockedProduct(100000, 10, 500, $secondInventory);

        ['order' => $order, 'shipment' => $komerceShipment] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'number' => 'ORD-PROBE-2D',
                'status' => OrderStatus::Processing,
                'payment_status' => PaymentStatus::Paid,
                'shipping_status' => ShippingStatus::Unfulfilled,
                'shipping_option_id' => null,
                'price_amount' => 227000,
            ],
            [
                'driver' => 'komerce',
                'carrier_id' => $this->fixtures->carriers['jne']->id,
                'carrier_code' => 'jne',
                'status' => NormalizeShipmentStatus::PENDING,
                'awb' => null,
                'tracking_number' => null,
                'cost' => 12000,
            ],
            null,
            $komerceProduct,
            $this->fixtures->inventory,
        );

        $manualItem = OrderItem::query()->create([
            'order_id' => $order->id,
            'name' => $manualProduct->name,
            'sku' => $manualProduct->sku ?? 'SKU-2D-MAN',
            'product_type' => $manualProduct->getMorphClass(),
            'product_id' => $manualProduct->id,
            'quantity' => 1,
            'unit_price_amount' => 100000,
        ]);

        $manualShipment = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $secondInventory->id,
            'driver' => 'manual',
            'carrier_id' => $this->fixtures->carriers['manual']->id,
            'carrier_code' => 'manual',
            'carrier_name' => 'Kurir Toko',
            'service_code' => 'Reguler',
            'service_name' => 'Reguler',
            'status' => NormalizeShipmentStatus::PENDING,
            'cost' => 15000,
            'currency_code' => 'IDR',
        ]);
        $manualShipment->lines()->create([
            'purchasable_type' => $manualProduct->getMorphClass(),
            'purchasable_id' => $manualProduct->id,
            'qty' => 1,
        ]);

        $slideOver = $this->cpanelCreateShippingLabel($admin, $order->fresh())
            ->assertSet('data.carrier_id', $this->fixtures->carriers['manual']->id);

        $this->assertSame([$manualItem->id], $slideOver->instance()->unfulfilledItems->pluck('id')->all());

        $slideOver->set('data.tracking_number', 'MAN-BOTH-1')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('MAN-BOTH-1', $manualShipment->refresh()->awb);
        $this->assertNull($komerceShipment->refresh()->awb);

        // Once the manual parcel is labeled, OrderFulfillment hides the manual label button while komerce remains pending.
        $this->assertFalse($this->cpanelOrderFulfillment($admin, $order->fresh())->instance()->hasUnfulfilledItems());
    }
}
