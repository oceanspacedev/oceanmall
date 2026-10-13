<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\OrderShipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Enum\ShippingStatus;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Tests\TestCase;

/**
 * Marketplace-style order list (status tabs + counters) and the richer order detail.
 */
final class CustomerOrdersApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_list_filters_by_tab_and_reports_counts(): void
    {
        $user = User::factory()->create();
        $this->order($user, 'UNPAID-1', OrderStatus::New, PaymentStatus::Pending, ShippingStatus::Unfulfilled);
        $this->order($user, 'PACK-1', OrderStatus::Processing, PaymentStatus::Paid, ShippingStatus::Unfulfilled);
        $this->order($user, 'SHIP-1', OrderStatus::Processing, PaymentStatus::Paid, ShippingStatus::Shipped);
        $this->order($user, 'DONE-1', OrderStatus::Completed, PaymentStatus::Paid, ShippingStatus::Delivered);
        $this->order($user, 'VOID-1', OrderStatus::Cancelled, PaymentStatus::Voided, ShippingStatus::Unfulfilled);
        $this->order(User::factory()->create(), 'OTHER-1', OrderStatus::New, PaymentStatus::Pending, ShippingStatus::Unfulfilled);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.counts', ['unpaid' => 1, 'processing' => 1, 'shipped' => 1, 'completed' => 1, 'cancelled' => 1]);

        foreach (['unpaid' => 'UNPAID-1', 'processing' => 'PACK-1', 'shipped' => 'SHIP-1', 'completed' => 'DONE-1', 'cancelled' => 'VOID-1'] as $tab => $number) {
            $this->getJson("/api/v1/orders?status={$tab}")
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.number', $number);
        }

        // Unknown tab falls back to all orders.
        $this->getJson('/api/v1/orders?status=bogus')->assertOk()->assertJsonCount(5, 'data');
    }

    public function test_order_detail_includes_address_items_and_totals(): void
    {
        $user = User::factory()->create();
        $order = $this->order($user, 'ORD-DETAIL', OrderStatus::Processing, PaymentStatus::Paid, ShippingStatus::Unfulfilled, 418000, [
            'shipping_address' => [
                'first_name' => 'Budi',
                'last_name' => 'Santoso',
                'phone_number' => '0812',
                'street_address' => 'Jl. Melawai 1',
                'rajaongkir_destination_label' => 'MELAWAI, KEBAYORAN BARU',
            ],
        ]);
        $order->items()->create([
            'name' => 'Realme Buds',
            'sku' => 'BUDS',
            'product_type' => 'product',
            'product_id' => 999,
            'quantity' => 1,
            'unit_price_amount' => 400000,
        ]);
        OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => Inventory::factory()->create()->id,
            'carrier_code' => 'jne',
            'carrier_name' => 'JNE',
            'service_code' => 'REG',
            'service_name' => 'Regular',
            'cost' => 18000,
            'currency_code' => 'IDR',
            'status' => 'pending',
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/orders/ORD-DETAIL')
            ->assertOk()
            ->assertJsonPath('data.shipping_address.name', 'Budi Santoso')
            ->assertJsonPath('data.shipping_address.area', 'MELAWAI, KEBAYORAN BARU')
            ->assertJsonPath('data.totals', ['subtotal' => 400000, 'shipping' => 18000, 'adjustment' => 0, 'total' => 418000])
            ->assertJsonPath('data.items.0.name', 'Realme Buds')
            ->assertJsonPath('data.item_count', 1);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function order(
        User $user,
        string $number,
        OrderStatus $status,
        PaymentStatus $payment,
        ShippingStatus $shipping,
        int $amount = 100000,
        array $metadata = [],
    ): Order {
        return Order::factory()->create([
            'number' => $number,
            'customer_id' => $user->id,
            'status' => $status,
            'payment_status' => $payment,
            'shipping_status' => $shipping,
            'price_amount' => $amount,
            'currency_code' => 'IDR',
            'metadata' => $metadata === [] ? null : json_encode($metadata),
        ]);
    }
}
