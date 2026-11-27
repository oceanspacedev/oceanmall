<?php

declare(strict_types=1);

namespace Tests\Feature\E2E\VendorPackageTier\Tier1;

use Illuminate\Support\Facades\Http;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Payment\Enum\TransactionStatus;
use Shopper\Payment\Enum\TransactionType;
use Shopper\Payment\Models\PaymentTransaction;
use Tests\Feature\E2E\VendorPackageTier\Support\VendorPackageTierTestCase;

final class F5PaymentLifecycleTest extends VendorPackageTierTestCase
{
    public function test_signed_payment_webhook_verifies_status_marks_order_paid_and_triggers_fulfillment(): void
    {
        $this->applyTier('komship');
        $customer = $this->fixtures->createVerifiedCustomer();

        ['order' => $order, 'shipment' => $shipment] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'price_amount' => 112000,
            ],
            [],
            ['amount' => 112000, 'reference' => 'KOMPAY-WH-1'],
        );

        $this->applyTier('komship')
            ->withPaymentStatus('KOMPAY-WH-1', 'PAID', 112000)
            ->apply();

        $this->postSignedKomercePaymentWebhook([
            'payment_id' => 'KOMPAY-WH-1',
            'order_id' => $order->number,
            'status' => 'PAID',
            'amount' => 112000,
        ])
            ->assertOk()
            ->assertJson(['status' => 'handled']);

        $order->refresh();
        $shipment->refresh();

        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame(OrderStatus::Processing, $order->status);
        $this->assertSame('JNE-E2E-1', $shipment->awb);

        $this->assertDatabaseHas((new PaymentTransaction)->getTable(), [
            'order_id' => $order->id,
            'reference' => 'KOMPAY-WH-1',
            'status' => TransactionStatus::Success->value,
            'type' => TransactionType::Capture->value,
        ]);
    }

    public function test_qrisly_webhook_verifies_history_status_and_marks_order_paid(): void
    {
        $this->applyTier('qrisly_only')
            ->withQrislyStatus('9001', 'paid', 50001)
            ->apply();

        $customer = $this->fixtures->createVerifiedCustomer();
        ['order' => $order] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'payment_method_id' => $this->fixtures->paymentMethods['qris']->id,
                'price_amount' => 50000,
                'metadata' => [
                    'komerce' => [
                        'payment_type' => 'qris',
                        'provider' => 'qrisly',
                        'payment_ref' => '9001',
                    ],
                ],
            ],
            [],
            ['amount' => 50000, 'reference' => '9001'],
        );

        $this->postQrislyWebhook([
            'event' => 'payment.success',
            'data' => [
                'history_id' => '9001',
                'status' => 'paid',
            ],
        ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $order->refresh();
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame(OrderStatus::Processing, $order->status);

        Http::assertSent(fn ($req): bool => str_contains($req->url(), '/qrisly/payment-status/9001')
            && $req->hasHeader('X-API-Key', 'qrisly-key'));
    }

    public function test_api_and_web_retry_payment_cancels_pending_va_and_issues_new_va(): void
    {
        $this->applyTier('starter')
            ->withPaymentStatus('KOMPAY-OLD-1', 'PENDING', 112000)
            ->withPaymentCreate([
                'payment_id' => 'KOMPAY-NEW-1',
                'virtual_account_number' => '8808009999999999',
                'bank_code' => 'BCA',
                'amount' => 112000,
            ])
            ->apply();

        $customer = $this->fixtures->createVerifiedCustomer();
        ['order' => $apiOrder] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'price_amount' => 112000,
                'metadata' => ['komerce' => ['payment_ref' => 'KOMPAY-OLD-1']],
            ],
            [],
            ['amount' => 112000, 'reference' => 'KOMPAY-OLD-1'],
        );

        $this->apiRetryPayment($customer, $apiOrder->number)
            ->assertOk()
            ->assertJsonPath('data.payment_action.data.payment_id', 'KOMPAY-NEW-1')
            ->assertJsonPath('data.payment_action.data.virtual_account_number', '8808009999999999');

        $this->assertDatabaseHas((new PaymentTransaction)->getTable(), [
            'order_id' => $apiOrder->id,
            'reference' => 'KOMPAY-OLD-1',
            'status' => TransactionStatus::Failed->value,
        ]);
        $this->assertDatabaseHas((new PaymentTransaction)->getTable(), [
            'order_id' => $apiOrder->id,
            'reference' => 'KOMPAY-NEW-1',
            'status' => TransactionStatus::Pending->value,
        ]);

        // Web retry path
        $this->applyTier('starter')
            ->withPaymentStatus('KOMPAY-OLD-2', 'PENDING', 112000)
            ->withPaymentCreate([
                'payment_id' => 'KOMPAY-NEW-2',
                'virtual_account_number' => '8808007777777777',
                'bank_code' => 'BCA',
                'amount' => 112000,
            ])
            ->apply();

        ['order' => $webOrder] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'price_amount' => 112000,
                'metadata' => ['komerce' => ['payment_ref' => 'KOMPAY-OLD-2']],
            ],
            [],
            ['amount' => 112000, 'reference' => 'KOMPAY-OLD-2'],
        );

        $this->webRetryPayment($customer, $webOrder)
            ->assertRedirect(route('account.orders.show', $webOrder))
            ->assertSessionHas('success');

        $this->assertDatabaseHas((new PaymentTransaction)->getTable(), [
            'order_id' => $webOrder->id,
            'reference' => 'KOMPAY-NEW-2',
            'status' => TransactionStatus::Pending->value,
        ]);
    }

    public function test_api_and_web_sync_payment_reconciles_paid_order_idempotently(): void
    {
        $this->applyTier('starter')
            ->withPaymentStatus('KOMPAY-SYNC-1', 'PENDING', 112000)
            ->apply();

        $customer = $this->fixtures->createVerifiedCustomer();
        ['order' => $order] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'price_amount' => 112000,
                'metadata' => ['komerce' => ['payment_ref' => 'KOMPAY-SYNC-1']],
            ],
            [],
            ['amount' => 112000, 'reference' => 'KOMPAY-SYNC-1'],
        );

        // First sync while pending
        $this->apiSyncPayment($customer, $order->number)
            ->assertOk()
            ->assertJsonPath('data.sync', 'not_paid')
            ->assertJsonPath('data.payment_status', PaymentStatus::Pending->value);

        // Clear short 3s status cache and transition upstream to PAID
        $this->resetTierState();
        $this->applyTier('starter')
            ->withPaymentStatus('KOMPAY-SYNC-1', 'PAID', 112000)
            ->apply();

        $this->apiSyncPayment($customer, $order->number)
            ->assertOk()
            ->assertJsonPath('data.sync', 'handled')
            ->assertJsonPath('data.payment_status', PaymentStatus::Paid->value);

        // Idempotent repeat on Web sync
        $this->webSyncPayment($customer, $order)
            ->assertRedirect(route('account.orders.show', $order))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            1,
            PaymentTransaction::query()
                ->where('order_id', $order->id)
                ->where('type', TransactionType::Capture->value)
                ->count(),
        );
    }

    public function test_orders_expire_unpaid_cancels_expired_va_order_remotely_and_restores_stock(): void
    {
        $this->applyTier('starter')
            ->withPaymentStatus('KOMPAY-EXP-1', 'PENDING', 112000)
            ->apply();

        $customer = $this->fixtures->createVerifiedCustomer();
        $product = $this->fixtures->createStockedProduct(100000, 10, 500);

        ['order' => $order] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'price_amount' => 112000,
                'metadata' => [
                    'komerce' => [
                        'payment_ref' => 'KOMPAY-EXP-1',
                        'expiry_date' => now()->subMinutes(5)->toDateTimeString(),
                    ],
                ],
            ],
            [],
            [
                'amount' => 112000,
                'reference' => 'KOMPAY-EXP-1',
                'metadata' => [
                    'komerce_payment_ref' => 'KOMPAY-EXP-1',
                    'expiry_date' => now()->subMinutes(5)->toDateTimeString(),
                ],
            ],
            $product,
        );

        // Simulate stock reserved when order was created
        $product->decreaseStock($this->fixtures->inventory->id, 1);
        $this->assertSame(9, $product->fresh()->stockInventory($this->fixtures->inventory->id));

        $this->artisan('orders:expire-unpaid')->assertSuccessful();

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(PaymentStatus::Voided, $order->payment_status);
        $this->assertSame(10, $product->fresh()->stockInventory($this->fixtures->inventory->id));

        Http::assertSent(fn ($req): bool => str_contains($req->url(), '/user/payment/cancel')
            && $req->hasHeader('x-api-key', 'payment-key')
            && ($req->data()['payment_id'] ?? null) === 'KOMPAY-EXP-1');
    }

    public function test_qrisly_expired_order_expires_locally_when_unpaid_without_cancel_call(): void
    {
        $this->applyTier('qrisly_only')
            ->withQrislyStatus('9002', 'unpaid', 50001)
            ->apply();

        $customer = $this->fixtures->createVerifiedCustomer();
        $product = $this->fixtures->createStockedProduct(50000, 10, 500);

        ['order' => $order] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'payment_method_id' => $this->fixtures->paymentMethods['qris']->id,
                'price_amount' => 50000,
                'metadata' => [
                    'komerce' => [
                        'payment_type' => 'qris',
                        'provider' => 'qrisly',
                        'payment_ref' => '9002',
                        'expiry_date' => now()->subMinutes(5)->toDateTimeString(),
                    ],
                ],
            ],
            [],
            [
                'amount' => 50000,
                'reference' => '9002',
                'metadata' => [
                    'komerce_payment_ref' => '9002',
                    'expiry_date' => now()->subMinutes(5)->toDateTimeString(),
                ],
            ],
            $product,
        );

        $product->decreaseStock($this->fixtures->inventory->id, 1);

        $this->artisan('orders:expire-unpaid')->assertSuccessful();

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(PaymentStatus::Voided, $order->payment_status);
        $this->assertSame(10, $product->fresh()->stockInventory($this->fixtures->inventory->id));

        Http::assertNotSent(fn ($req): bool => str_contains($req->url(), '/user/payment/cancel'));
    }
}
