<?php

declare(strict_types=1);

namespace Tests\Feature\QaRegression;

use App\Actions\Account\CancelCustomerOrder;
use App\Actions\Account\SubmitReturnRequest;
use App\Actions\Cpanel\ProcessReturnRequest;
use App\Actions\Cpanel\VerifyPaymentProof;
use App\Actions\Orders\RecordOrderRefund;
use App\Actions\Orders\RecordPaymentDispute;
use App\Models\OrderPaymentProof;
use App\Models\OrderReturnRequest;
use App\Models\OrderShipment;
use App\Models\OrderShipmentLine;
use App\Models\PaymentDispute;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Enum\ShippingStatus;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\OrderItem;
use Shopper\Core\Models\PaymentMethod;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class Fase2RefundRmaAndProofTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_self_cancel_releases_reserved_stock_on_unpaid_order(): void
    {
        $customer = User::factory()->create(['email' => 'cancel-cust@example.com']);
        $inventory = Inventory::factory()->create(['is_default' => true]);
        $product = Product::factory()->standard()->create(['published_at' => now()->subDay()]);
        $product->mutateStock($inventory->id, 5);

        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'status' => OrderStatus::New,
            'payment_status' => PaymentStatus::Pending,
            'shipping_status' => ShippingStatus::Unfulfilled,
            'currency_code' => 'IDR',
        ]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_type' => $product->getMorphClass(),
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => 'CANCEL-1',
            'quantity' => 2,
            'unit_price_amount' => 100000,
        ]);

        $shipment = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inventory->id,
            'carrier_code' => 'manual',
            'service_code' => 'REG',
            'cost' => 10000,
            'currency_code' => 'IDR',
            'status' => 'pending',
        ]);
        OrderShipmentLine::query()->create([
            'order_shipment_id' => $shipment->id,
            'purchasable_type' => $product->getMorphClass(),
            'purchasable_id' => $product->id,
            'qty' => 2,
        ]);

        // Simulate checkout debit
        $product->decreaseStock($inventory->id, 2, -2, 'order', null, null, $order);
        $this->assertSame(3, $product->stockInventory($inventory->id));

        resolve(CancelCustomerOrder::class)->handle($order->refresh(), $customer, 'Salah pilih varian');

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(5, $product->stockInventory($inventory->id));
    }

    public function test_rma_submit_approve_and_restock_restores_inventory(): void
    {
        $customer = User::factory()->create(['email' => 'rma-cust@example.com']);
        $staff = User::factory()->create(['email' => 'rma-staff@example.com']);
        $inventory = Inventory::factory()->create(['is_default' => true]);
        $product = Product::factory()->standard()->create(['published_at' => now()->subDay()]);
        $product->mutateStock($inventory->id, 4);

        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Delivered,
            'price_amount' => 200000,
            'currency_code' => 'IDR',
            'created_at' => now()->subDay(),
            'updated_at' => now(),
        ])->refresh();
        $item = OrderItem::query()->create([
            'order_id' => $order->id,
            'product_type' => $product->getMorphClass(),
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => 'RMA-1',
            'quantity' => 2,
            'unit_price_amount' => 100000,
        ]);

        $rma = resolve(SubmitReturnRequest::class)->handle(
            order: $order,
            customer: $customer,
            reason: 'damaged',
            description: 'Kemasan penyok saat tiba',
            requestedItems: [['order_item_id' => $item->id, 'qty' => 2]],
        );

        $this->assertSame(OrderReturnRequest::STATUS_REQUESTED, $rma->status);

        $ops = resolve(ProcessReturnRequest::class);
        $ops->approve($rma, $staff, 'Disetujui retur');
        $this->assertSame(OrderReturnRequest::STATUS_APPROVED, $rma->refresh()->status);

        $ops->restock($rma, $staff, $inventory->id);
        $this->assertSame(OrderReturnRequest::STATUS_RESTOCKED, $rma->refresh()->status);
        $this->assertSame(6, $product->stockInventory($inventory->id));
    }

    public function test_partial_refund_ledger_caps_at_paid_amount_and_records_dispute(): void
    {
        $staff = User::factory()->create(['email' => 'refund-staff@example.com']);
        $order = Order::factory()->create([
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'price_amount' => 100000,
            'currency_code' => 'IDR',
        ]);

        $refundService = resolve(RecordOrderRefund::class);
        $refundService->handle($order, 40000, 'Partial refund #1', $staff, 'manual_bank_transfer', 'REF-1');
        $refundService->handle($order, 60000, 'Partial refund #2', $staff, 'manual_bank_transfer', 'REF-2');

        $this->assertSame(PaymentStatus::Refunded, $order->refresh()->payment_status);

        try {
            $refundService->handle($order, 10000, 'Over refund', $staff);
            $this->fail('Expected RuntimeException when refunding beyond paid amount.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('melebihi nilai pesanan', $e->getMessage());
        }

        $dispute = resolve(RecordPaymentDispute::class)->handle(
            order: $order,
            driver: 'manual',
            type: PaymentDispute::TYPE_AMOUNT_MISMATCH,
            reference: 'DISP-1',
            amount: 95000,
            expectedAmount: 100000,
        );
        $this->assertNotNull($dispute);
        $this->assertSame(PaymentDispute::STATUS_OPEN, $dispute->status);
    }

    public function test_manual_bank_transfer_proof_upload_and_staff_approval_marks_order_paid(): void
    {
        Storage::fake('local');
        $this->configureShopperCpanel();
        Role::query()->firstOrCreate(['name' => config('shopper.admin.roles.admin'), 'guard_name' => 'web']);

        $customer = User::factory()->create(['email' => 'proof-cust@example.com']);
        $staff = User::factory()->create(['email' => 'proof-staff@example.com']);
        $staff->assignRole(config('shopper.admin.roles.admin'));

        $manualMethod = PaymentMethod::factory()->create([
            'title' => 'Transfer Bank BCA',
            'slug' => 'bank-transfer',
            'driver' => 'manual',
            'is_enabled' => true,
            'metadata' => json_encode(['payment_type' => 'bank_transfer']),
        ]);

        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'payment_method_id' => $manualMethod->id,
            'status' => OrderStatus::New,
            'payment_status' => PaymentStatus::Pending,
            'price_amount' => 250000,
            'currency_code' => 'IDR',
        ]);

        $this->actingAs($customer)
            ->post(route('account.orders.payment-proof.store', $order), [
                'proof' => UploadedFile::fake()->image('bukti.jpg', 400, 400),
                'sender_name' => 'Budi Santoso',
                'sender_bank' => 'BCA',
                'transfer_amount' => 250000,
            ])
            ->assertRedirect();

        $proof = OrderPaymentProof::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(OrderPaymentProof::STATUS_PENDING, $proof->status);

        resolve(VerifyPaymentProof::class)->approve($proof, $staff, 250000, 'MUTASI-889');

        $order->refresh();
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertSame(OrderPaymentProof::STATUS_APPROVED, $proof->refresh()->status);
        $this->assertNotNull($order->invoice_number);
        $this->assertNotNull($order->paid_at);
    }
}
