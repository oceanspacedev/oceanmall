<?php

declare(strict_types=1);

namespace Tests\Feature\Checkout;

use App\Actions\Checkout\CreateKomercePayment;
use App\Actions\CreateOrder;
use App\CheckoutSession;
use App\Models\OrderShipment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Shopper\Cart\Models\Cart;
use Shopper\Cart\Models\CartLine;
use Shopper\Core\Enum\DiscountApplyTo;
use Shopper\Core\Enum\DiscountEligibility;
use Shopper\Core\Enum\DiscountRequirement;
use Shopper\Core\Enum\DiscountType;
use Shopper\Core\Models\Discount;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Payment\Models\PaymentTransaction;
use Tests\TestCase;

/**
 * Guards whole-rupiah money handling end to end: IDR is stored without a
 * minor unit (patches/shopper-core-idr-zero-decimal.patch), and the amount
 * charged through Komerce equals subtotal - discount + shipping.
 */
final class IdrAmountIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_idr_is_formatted_as_whole_rupiah_without_division(): void
    {
        app()->setLocale('id');

        $this->assertTrue(is_no_division_currency('IDR'));
        $this->assertTrue(is_no_division_currency('idr'));
        $this->assertSame('Rp 150.000', $this->normalizeSpaces(shopper_money_format(150000, 'IDR')));
        $this->assertSame('Rp 1.250.500', $this->normalizeSpaces(shopper_money_format(1250500, 'IDR')));
    }

    public function test_order_total_is_subtotal_plus_shipping_and_matches_komerce_charge(): void
    {
        [$order, $shipment] = $this->placeOrder(couponCode: null);

        // 2 x Rp125.000 + Rp18.000 ongkir.
        $this->assertSame(250000 + 18000, (int) $order->price_amount);
        $this->assertSame(18000, $shipment->cost);

        $this->chargeThroughKomerce($order, 268000);
    }

    public function test_order_total_with_coupon_matches_komerce_charge(): void
    {
        Discount::query()->create([
            'code' => 'OCEAN10',
            'is_active' => true,
            'type' => DiscountType::Percentage,
            'value' => 10,
            'apply_to' => DiscountApplyTo::Order->value,
            'min_required' => DiscountRequirement::None->value,
            'eligibility' => DiscountEligibility::Everyone->value,
            'usage_limit' => null,
            'usage_limit_per_user' => false,
            'total_use' => 0,
            'start_at' => now()->subDay(),
            'end_at' => now()->addYear(),
        ]);

        [$order] = $this->placeOrder(couponCode: 'OCEAN10');

        // Rp250.000 - 10% (Rp25.000) + Rp18.000 ongkir.
        $this->assertSame(250000 - 25000 + 18000, (int) $order->price_amount);
        $this->assertSame('OCEAN10', $order->discount_code);

        $this->chargeThroughKomerce($order, 243000);
    }

    /**
     * @return array{0: Order, 1: OrderShipment}
     */
    private function placeOrder(?string $couponCode): array
    {
        $user = User::factory()->create([
            'first_name' => 'Budi',
            'last_name' => 'Santoso',
            'email' => 'budi@example.com',
        ]);
        $paymentMethod = PaymentMethod::factory()->create(['driver' => 'komerce', 'is_enabled' => true]);
        $inventory = Inventory::factory()->create(['is_default' => true]);
        $product = Product::factory()->standard()->create(['name' => 'Kopi Cirebon 500g']);
        $product->mutateStock($inventory->id, 5);

        $cart = Cart::query()->create([
            'currency_code' => 'IDR',
            'customer_id' => $user->id,
            'coupon_code' => $couponCode,
        ]);
        CartLine::query()->create([
            'cart_id' => $cart->id,
            'purchasable_type' => $product->getMorphClass(),
            'purchasable_id' => $product->id,
            'quantity' => 2,
            'unit_price_amount' => 125000,
        ]);

        $this->actingAs($user);
        session()->put(config('shopper.cart.session.key', 'shopper_cart'), $cart->id);
        session()->put(CheckoutSession::KEY, [
            'shipping_address' => [
                'first_name' => 'Budi',
                'last_name' => 'Santoso',
                'street_address' => 'Jl. Merdeka 1',
                'postal_code' => '10110',
                'city' => 'Jakarta',
                'country_id' => $inventory->country_id,
                'phone_number' => '081234567890',
                'rajaongkir_destination_id' => '152',
            ],
            'shipping_option' => [[
                'id' => 'jne:REG',
                'name' => 'Reguler',
                'price' => 18000,
                'amount' => 18000,
                'service_code' => 'jne:REG',
                'carrier_code' => 'jne',
                'currency' => 'IDR',
            ]],
            'payment' => [[
                'id' => $paymentMethod->id,
                'driver' => 'komerce',
                'channel_code' => 'BCA',
                'payment_type' => 'bank_transfer',
            ]],
        ]);

        $order = resolve(CreateOrder::class)->handle()->refresh();
        $shipment = OrderShipment::query()->where('order_id', $order->id)->sole();

        return [$order, $shipment];
    }

    private function chargeThroughKomerce(Order $order, int $expected): void
    {
        config()->set('komerce.payment_api_key', 'test-komerce-key');
        config()->set('komerce.payment_base_url', 'https://payment.example.test/user');
        config()->set('komerce.webhook_secret', 'webhook-secret');

        Http::fake([
            'https://payment.example.test/user/api/v1/user/payment/create' => Http::response([
                'success' => true,
                'data' => [
                    'payment_id' => 'KOMPAY-IDR-001',
                    'virtual_account_number' => '8808001111111111',
                    'bank_code' => 'BCA',
                    'expiry_date' => '2026-12-31 23:59:59',
                    'amount' => $expected,
                ],
            ]),
        ]);

        resolve(CreateKomercePayment::class)->handle($order, [
            'id' => $order->payment_method_id,
            'driver' => 'komerce',
            'channel_code' => 'BCA',
            'payment_type' => 'bank_transfer',
        ]);

        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/payment/create')
            && data_get($request->data(), 'amount') === $expected);

        $this->assertSame(
            $expected,
            (int) PaymentTransaction::query()
                ->where('order_id', $order->id)
                ->where('reference', 'KOMPAY-IDR-001')
                ->value('amount'),
        );
    }

    private function normalizeSpaces(string $value): string
    {
        return str_replace(["\u{00A0}", "\u{202F}"], ' ', $value);
    }
}
