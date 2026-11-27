<?php

declare(strict_types=1);

namespace Tests\Feature\E2E\VendorPackageTier\Tier5;

use App\Integrations\Komerce\Actions\Payment\RetryKomercePayment;
use App\Integrations\Komerce\Actions\Payment\SyncKomercePaymentStatus;
use App\Integrations\Komerce\Payment\KomerceDriver;
use App\Integrations\Komerce\Services\PaymentClient;
use App\Integrations\Komerce\Services\QrislyClient;
use App\Payment\Exceptions\PaymentRetryRefused;
use Illuminate\Support\Facades\Http;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Payment\Enum\TransactionStatus;
use Shopper\Payment\Enum\TransactionType;
use Shopper\Payment\Models\PaymentTransaction;
use Tests\Feature\E2E\VendorPackageTier\Support\VendorPackageTierTestCase;

final class QrislyTransactionMetadataFallbackTest extends VendorPackageTierTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireModules('Komerce');
    }

    public function test_qrisly_sync_retry_and_expiry_fallback_to_transaction_metadata_when_order_metadata_missing(): void
    {
        $this->applyTier('qrisly_only')
            ->withQrislyStatus('HIST-999', 'paid', 25000)
            ->withQrislyStatus('HIST-UNPAID', 'unpaid', 25000)
            ->withQrislyGenerate(['history_id' => 1000, 'final_amount' => 25001])
            ->apply();

        $customer = $this->fixtures->createVerifiedCustomer();

        // 1. Order with metadata=null, pending tx with komerce_provider='qrisly' -> Sync reconciles via QRISLY
        ['order' => $paidOrder] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'price_amount' => 25000,
                'payment_method_id' => $this->fixtures->paymentMethods['qris']->id,
                'metadata' => [],
            ],
            [],
            null,
        );

        PaymentTransaction::query()->create([
            'order_id' => $paidOrder->id,
            'payment_method_id' => $this->fixtures->paymentMethods['qris']->id,
            'driver' => 'komerce',
            'type' => TransactionType::Initiate,
            'status' => TransactionStatus::Pending,
            'amount' => 25000,
            'currency_code' => 'IDR',
            'reference' => 'HIST-999',
            'metadata' => [
                'komerce_provider' => 'qrisly',
                'komerce_payment_type' => 'qris',
            ],
        ]);

        $this->assertSame('handled', resolve(SyncKomercePaymentStatus::class)->reconcile($paidOrder->fresh()));
        $this->assertSame(PaymentStatus::Paid, $paidOrder->fresh()->payment_status);

        // 2. Retry on an order already paid at QRISLY (with metadata=null) refuses duplicate charge
        ['order' => $retryPaidOrder] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'price_amount' => 25000,
                'payment_method_id' => $this->fixtures->paymentMethods['qris']->id,
                'metadata' => [],
            ],
            [],
            null,
        );

        $retryTx = PaymentTransaction::query()->create([
            'order_id' => $retryPaidOrder->id,
            'payment_method_id' => $this->fixtures->paymentMethods['qris']->id,
            'driver' => 'komerce',
            'type' => TransactionType::Initiate,
            'status' => TransactionStatus::Pending,
            'amount' => 25000,
            'currency_code' => 'IDR',
            'reference' => 'HIST-999',
            'metadata' => [
                'komerce_provider' => 'qrisly',
                'komerce_payment_type' => 'qris',
            ],
        ]);

        try {
            resolve(RetryKomercePayment::class)->handle($retryPaidOrder->fresh());
            $this->fail('Expected PaymentRetryRefused::alreadyPaid() when QRISLY status is paid.');
        } catch (PaymentRetryRefused $e) {
            $this->assertSame(PaymentRetryRefused::ALREADY_PAID, $e->reason);
        }

        $this->assertSame(PaymentStatus::Paid, $retryPaidOrder->fresh()->payment_status);
        $this->assertNotSame(TransactionStatus::Failed, $retryTx->fresh()->status);
        Http::assertNotSent(fn ($req): bool => str_contains($req->url(), 'generate-qris'));

        // 3. Unpaid expired QRISLY order without order.metadata expires cleanly via orders:expire-unpaid
        ['order' => $expiredOrder] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'price_amount' => 25000,
                'payment_method_id' => $this->fixtures->paymentMethods['qris']->id,
                'metadata' => [],
            ],
            [],
            null,
        );

        PaymentTransaction::query()->create([
            'order_id' => $expiredOrder->id,
            'payment_method_id' => $this->fixtures->paymentMethods['qris']->id,
            'driver' => 'komerce',
            'type' => TransactionType::Initiate,
            'status' => TransactionStatus::Pending,
            'amount' => 25000,
            'currency_code' => 'IDR',
            'reference' => 'HIST-UNPAID',
            'metadata' => [
                'komerce_provider' => 'qrisly',
                'komerce_payment_type' => 'qris',
                'expiry_date' => now()->subHour()->toIso8601String(),
            ],
        ]);

        $driver = new KomerceDriver(
            resolve(PaymentClient::class),
            resolve(QrislyClient::class),
        );
        $this->assertTrue($driver->canExpireUnpaidOrder($expiredOrder->fresh()));

        $this->artisan('orders:expire-unpaid')->assertSuccessful();

        $expiredOrder->refresh();
        $this->assertSame(OrderStatus::Cancelled, $expiredOrder->status);
        $this->assertSame(PaymentStatus::Voided, $expiredOrder->payment_status);
    }

    public function test_qrisly_inference_applies_when_transaction_only_records_qris_payment_type(): void
    {
        $this->applyTier('qrisly_only')
            ->withQrislyStatus('HIST-INFER', 'paid', 30000)
            ->apply();

        $customer = $this->fixtures->createVerifiedCustomer();
        ['order' => $order] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::New,
                'payment_status' => PaymentStatus::Pending,
                'price_amount' => 30000,
                'payment_method_id' => $this->fixtures->paymentMethods['qris']->id,
                'metadata' => [],
            ],
            [],
            null,
        );

        PaymentTransaction::query()->create([
            'order_id' => $order->id,
            'payment_method_id' => $this->fixtures->paymentMethods['qris']->id,
            'driver' => 'komerce',
            'type' => TransactionType::Initiate,
            'status' => TransactionStatus::Pending,
            'amount' => 30000,
            'currency_code' => 'IDR',
            'reference' => 'HIST-INFER',
            'metadata' => [
                'komerce_payment_type' => 'qris',
            ],
        ]);

        $this->assertSame('handled', resolve(SyncKomercePaymentStatus::class)->reconcile($order->fresh()));
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
    }
}
