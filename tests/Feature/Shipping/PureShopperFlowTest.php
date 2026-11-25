<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Enums\OrderNotificationType;
use App\Models\OrderShipment;
use App\Models\Product;
use App\Models\User;
use App\Notifications\OrderStatusNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Shopper\Cart\Models\Cart;
use Shopper\Cart\Models\CartLine;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Enum\ShipmentStatus;
use Shopper\Core\Enum\ShippingStatus;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\CarrierOption;
use Shopper\Core\Models\Country;
use Shopper\Core\Models\Currency;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\OrderShipping;
use Shopper\Core\Models\OrderShippingEvent;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Core\Models\Price;
use Shopper\Core\Models\Zone;
use Tests\TestCase;

final class PureShopperFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake();

        config()->set('shopper.payment.provider', 'none');
        config()->set('shopper.shipping.provider', 'none');
    }

    public function test_manual_cod_checkout_sends_order_placed_notification_and_exposes_manual_capabilities(): void
    {
        Notification::fake();

        [$option, $cod, $inventory] = $this->store();
        $product = $this->product($inventory);

        $webUser = User::factory()->create();
        $cart = Cart::query()->create(['currency_code' => 'IDR', 'customer_id' => $webUser->id]);
        CartLine::query()->create([
            'cart_id' => $cart->id,
            'purchasable_type' => $product->getMorphClass(),
            'purchasable_id' => $product->id,
            'quantity' => 1,
            'unit_price_amount' => 50000,
        ]);

        $this->withoutVite();
        $this->actingAs($webUser)->withSession([
            config('shopper.cart.session.key', 'shopper_cart') => $cart->id,
            'zone_country_code' => 'ID',
        ]);

        $this->post(route('shop.checkout.shipping-address'), $this->address())->assertSessionHasNoErrors();
        $this->post(route('shop.checkout.shipping-option'), ['service_code' => (string) $option->id])->assertSessionHasNoErrors();
        $this->post(route('shop.checkout.place-order'), ['payment_method_id' => $cod->id])->assertSessionHasNoErrors();

        $webOrder = Order::query()->where('customer_id', $webUser->id)->sole();
        Notification::assertSentTo(
            $webUser,
            OrderStatusNotification::class,
            static fn (OrderStatusNotification $notification): bool => ($notification->toDatabase($webUser)['type'] ?? null)
                === OrderNotificationType::OrderPlaced->value,
        );
        Notification::assertNotSentTo(
            $webUser,
            OrderStatusNotification::class,
            static fn (OrderStatusNotification $notification): bool => ($notification->toDatabase($webUser)['type'] ?? null)
                === OrderNotificationType::AwaitingPayment->value,
        );

        // Mobile API checkout
        $mobileUser = User::factory()->create();
        Sanctum::actingAs($mobileUser);
        $this->postJson('/api/v1/cart/items', ['product_id' => $product->id, 'quantity' => 1])->assertOk();
        $this->postJson('/api/v1/checkout/shipping-address', $this->address())->assertOk();
        $this->postJson('/api/v1/checkout/shipping-option', ['service_code' => (string) $option->id])->assertOk();
        $this->postJson('/api/v1/checkout/place-order', ['payment_method_id' => $cod->id])
            ->assertCreated()
            ->assertJsonPath('data.payment_action.kind', 'none');

        $mobileOrder = Order::query()->where('customer_id', $mobileUser->id)->sole();
        Notification::assertSentTo(
            $mobileUser,
            OrderStatusNotification::class,
            static fn (OrderStatusNotification $notification): bool => ($notification->toDatabase($mobileUser)['type'] ?? null)
                === OrderNotificationType::OrderPlaced->value,
        );

        $this->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonPath('data.0.payment_driver', 'manual');

        $this->getJson('/api/v1/orders/'.$mobileOrder->number)
            ->assertOk()
            ->assertJsonPath('data.payment_driver', 'manual')
            ->assertJsonPath('data.payment_action.kind', 'none')
            ->assertJsonPath('data.shipments.0.can_track', false);

        $this->actingAs($webUser)
            ->get(route('account.orders.show', $webOrder))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('paymentAction.kind', 'none')
                ->where('shipments.0.can_track', false));

        Http::assertNothingSent();
    }

    public function test_manual_shipment_tracking_message_is_indonesian_and_shipped_cod_moves_to_shipped_tab(): void
    {
        [, $cod, $inventory, $carrier] = $this->store();
        $product = $this->product($inventory);

        $customer = User::factory()->create();
        $order = Order::factory()->create([
            'number' => 'ORD-PURE-001',
            'customer_id' => $customer->id,
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Pending,
            'shipping_status' => ShippingStatus::Unfulfilled,
            'payment_method_id' => $cod->id,
            'currency_code' => 'IDR',
            'price_amount' => 65000,
        ]);
        $order->items()->create([
            'name' => $product->name,
            'sku' => 'SKU-PURE-1',
            'product_type' => $product->getMorphClass(),
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price_amount' => 50000,
        ]);
        $shipment = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inventory->id,
            'driver' => 'manual',
            'carrier_id' => $carrier->id,
            'carrier_code' => 'manual',
            'carrier_name' => 'Kurir Toko',
            'service_name' => 'Reguler',
            'status' => 'pending',
            'cost' => 15000,
            'currency_code' => 'IDR',
        ]);

        // Staff creates Shopper shipping label + manual event while COD payment is still pending
        $shipping = OrderShipping::query()->create([
            'order_id' => $order->id,
            'carrier_id' => $carrier->id,
            'tracking_number' => 'TOKO-001',
            'status' => ShipmentStatus::InTransit,
        ]);
        OrderShippingEvent::query()->create([
            'order_shipping_id' => $shipping->id,
            'status' => ShipmentStatus::InTransit,
            'description' => 'Paket dibawa kurir toko',
            'occurred_at' => now()->subMinute(),
        ]);
        $order->forceFill(['shipping_status' => ShippingStatus::Shipped])->save();

        $shipment->refresh();
        $this->assertSame('TOKO-001', $shipment->awb);
        $this->assertSame('in_transit', $shipment->status);

        Sanctum::actingAs($customer);

        // Shipped COD order appears in shipped tab and is no longer stuck in unpaid tab
        $this->getJson('/api/v1/orders?status=shipped')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', $order->number)
            ->assertJsonPath('data.0.payment_driver', 'manual');

        $this->getJson('/api/v1/orders?status=unpaid')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Detail includes manual tracking timeline and can_track = false
        $this->getJson('/api/v1/orders/'.$order->number)
            ->assertOk()
            ->assertJsonPath('data.shipments.0.can_track', false)
            ->assertJsonPath('data.shipments.0.tracking_history.0.description', 'Paket dibawa kurir toko');

        // Calling track endpoint directly without a provider returns Indonesian message
        $this->postJson("/api/v1/orders/{$order->number}/shipments/{$shipment->id}/track")
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Pelacakan otomatis tidak tersedia untuk pengiriman ini. Riwayat pengiriman diperbarui oleh toko.',
            );

        // Staff marks COD paid, customer confirms receipt -> completed
        $order->forceFill(['payment_status' => PaymentStatus::Paid])->save();
        $this->postJson("/api/v1/orders/{$order->number}/confirm-received")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.shipping_status', 'delivered');

        Http::assertNothingSent();
    }

    /**
     * @return array{0: CarrierOption, 1: PaymentMethod, 2: Inventory, 3: Carrier}
     */
    private function store(): array
    {
        $currency = Currency::query()->where('code', 'IDR')->first()
            ?? Currency::factory()->create(['code' => 'IDR', 'name' => 'Rupiah', 'symbol' => 'Rp', 'format' => 'Rp1.234']);
        $country = Country::factory()->create(['cca2' => 'ID']);
        $zone = Zone::factory()->create(['is_enabled' => true, 'currency_id' => $currency->id]);
        $zone->countries()->attach($country->id);

        $manual = Carrier::query()->create(['name' => 'Kurir Toko', 'slug' => 'manual', 'driver' => 'manual', 'is_enabled' => true]);
        $zone->carriers()->attach($manual->id);
        $option = CarrierOption::query()->create([
            'carrier_id' => $manual->id,
            'zone_id' => $zone->id,
            'name' => 'Reguler',
            'price' => 15000,
            'is_enabled' => true,
        ]);

        $cod = PaymentMethod::factory()->create([
            'title' => 'Bayar di tempat (COD)',
            'description' => 'Bayar di tempat saat barang diterima.',
            'slug' => 'cod',
            'driver' => 'manual',
            'is_enabled' => true,
        ]);
        $zone->paymentMethods()->attach($cod->id);

        $inventory = Inventory::factory()->create(['is_default' => true, 'country_id' => $country->id]);

        return [$option, $cod, $inventory, $manual];
    }

    private function product(Inventory $inventory): Product
    {
        $product = Product::factory()->standard()->create([
            'published_at' => now()->subDay(),
            'weight_value' => 0.5,
            'weight_unit' => 'kg',
        ]);
        $product->mutateStock($inventory->id, 10);
        $currency = Currency::query()->where('code', shopper_currency())->first()
            ?? Currency::factory()->create(['code' => shopper_currency(), 'name' => shopper_currency(), 'symbol' => shopper_currency(), 'format' => '1,234.56']);
        Price::query()->create([
            'priceable_type' => $product->getMorphClass(),
            'priceable_id' => $product->id,
            'amount' => 50000,
            'currency_id' => $currency->id,
        ]);

        return $product;
    }

    /**
     * @return array<string, string>
     */
    private function address(): array
    {
        return [
            'first_name' => 'Budi',
            'last_name' => 'Santoso',
            'street_address' => 'Jl. Merdeka 1',
            'postal_code' => '45153',
            'city' => 'Cirebon',
            'state' => 'Jawa Barat',
            'phone_number' => '081234567890',
        ];
    }
}
