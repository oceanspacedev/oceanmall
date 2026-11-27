<?php

declare(strict_types=1);

namespace Tests\Feature\E2E\VendorPackageTier\Support\Concerns;

use App\Models\OrderShipment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Shopper\Cart\Models\Cart;
use Shopper\Cart\Models\CartLine;
use Shopper\Core\Enum\AddressType;
use Shopper\Core\Models\Order;

/**
 * Reusable Storefront Web & Account HTTP/Inertia actor helpers.
 */
trait InteractsWithWebStorefront
{
    /**
     * @return array{user: User, cart: Cart}
     */
    protected function webCustomerWithCart(
        Product $product,
        int $qty = 1,
        ?int $unitPrice = null,
        ?User $user = null,
    ): array {
        $customer = $user ?? $this->fixtures->createVerifiedCustomer();
        $price = $unitPrice ?? (int) ($product->prices()->first()?->amount ?? 100000);

        $cart = Cart::query()->create([
            'currency_code' => 'IDR',
            'customer_id' => $customer->id,
        ]);

        CartLine::query()->create([
            'cart_id' => $cart->id,
            'purchasable_type' => $product->getMorphClass(),
            'purchasable_id' => $product->id,
            'quantity' => $qty,
            'unit_price_amount' => $price,
        ]);

        $this->actingAs($customer)->withSession([
            config('shopper.cart.session.key', 'shopper_cart') => $cart->id,
            'zone_country_code' => 'ID',
        ]);

        return ['user' => $customer, 'cart' => $cart];
    }

    protected function webLoadHome(User $customer): TestResponse
    {
        return $this->actingAs($customer)->get(route('home'));
    }

    protected function webSearchDestinations(User $customer, string $query, int $limit = 10): TestResponse
    {
        return $this->actingAs($customer)
            ->getJson(route('shop.checkout.destinations', ['q' => $query, 'limit' => $limit]));
    }

    protected function webLoadCheckout(User $customer, ?int $step = null): TestResponse
    {
        return $this->actingAs($customer)
            ->get(route('shop.checkout.index', $step !== null ? ['step' => $step] : []));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function webSaveShippingAddress(User $customer, array $overrides = []): TestResponse
    {
        $payload = array_merge([
            'first_name' => 'Budi',
            'last_name' => 'Santoso',
            'street_address' => 'Jl. Merdeka No. 1',
            'postal_code' => '10110',
            'city' => 'Jakarta Pusat',
            'state' => 'DKI Jakarta',
            'phone_number' => '081234567890',
            'destination_id' => '17601',
            'destination_label' => 'GAMBIR, GAMBIR, JAKARTA PUSAT, DKI JAKARTA, 10110',
            'pin_point' => '-6.1754,106.8272',
        ], $overrides);

        return $this->actingAs($customer)
            ->from(route('shop.checkout.index', ['step' => 1]))
            ->post(route('shop.checkout.shipping-address'), $payload);
    }

    protected function webPickShippingOption(User $customer, string|int $serviceCode): TestResponse
    {
        return $this->actingAs($customer)
            ->from(route('shop.checkout.index', ['step' => 2]))
            ->post(route('shop.checkout.shipping-option'), [
                'service_code' => (string) $serviceCode,
            ]);
    }

    /**
     * @param  array<int|string, string|int>  $rates
     */
    protected function webPickShippingOptionsByShipment(User $customer, array $rates): TestResponse
    {
        return $this->actingAs($customer)
            ->from(route('shop.checkout.index', ['step' => 2]))
            ->post(route('shop.checkout.shipping-option'), [
                'rates' => array_map(static fn (string|int $code): string => (string) $code, $rates),
            ]);
    }

    protected function webPlaceOrder(User $customer, int $paymentMethodId): TestResponse
    {
        return $this->actingAs($customer)
            ->from(route('shop.checkout.index', ['step' => 3]))
            ->post(route('shop.checkout.place-order'), [
                'payment_method_id' => $paymentMethodId,
            ]);
    }

    protected function webAddToCart(Product $product, int $qty = 1, ?User $customer = null): TestResponse
    {
        $test = $customer !== null ? $this->actingAs($customer) : $this;

        return $test->from(route('shop.cart'))
            ->post(route('shop.cart.add'), [
                'product_id' => $product->id,
                'quantity' => $qty,
            ]);
    }

    protected function webUpdateCartLine(int $lineId, int $qty, ?User $customer = null): TestResponse
    {
        $test = $customer !== null ? $this->actingAs($customer) : $this;

        return $test->from(route('shop.cart'))
            ->patch(route('shop.cart.update', $lineId), [
                'quantity' => $qty,
            ]);
    }

    protected function webRemoveCartLine(int $lineId, ?User $customer = null): TestResponse
    {
        $test = $customer !== null ? $this->actingAs($customer) : $this;

        return $test->from(route('shop.cart'))
            ->delete(route('shop.cart.destroy', $lineId));
    }

    protected function webCheckoutSuccess(User $customer, Order $order): TestResponse
    {
        return $this->actingAs($customer)
            ->get(route('shop.checkout.success', ['order' => $order->id]));
    }

    protected function webLoadAddresses(User $customer): TestResponse
    {
        return $this->actingAs($customer)->get(route('account.addresses'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function webCreateAccountAddress(User $customer, array $overrides = []): TestResponse
    {
        $payload = array_merge([
            'first_name' => 'Budi',
            'last_name' => 'Santoso',
            'street_address' => 'Jl. Merdeka No. 1',
            'postal_code' => '10110',
            'city' => 'Jakarta Pusat',
            'state' => 'DKI Jakarta',
            'phone_number' => '081234567890',
            'country_id' => $this->fixtures->country->id,
            'type' => AddressType::Shipping->value,
            'destination_id' => '17601',
            'destination_label' => 'GAMBIR, GAMBIR, JAKARTA PUSAT, DKI JAKARTA, 10110',
            'pin_point' => '-6.1754,106.8272',
        ], $overrides);

        return $this->actingAs($customer)
            ->from(route('account.addresses'))
            ->post(route('account.addresses.store'), $payload);
    }

    protected function webShowOrder(User $customer, Order $order): TestResponse
    {
        return $this->actingAs($customer)->get(route('account.orders.show', $order));
    }

    protected function webRetryPayment(User $customer, Order $order): TestResponse
    {
        return $this->actingAs($customer)
            ->from(route('account.orders.show', $order))
            ->post(route('account.orders.retry-payment', $order));
    }

    protected function webSyncPayment(User $customer, Order $order, bool $silent = false): TestResponse
    {
        return $this->actingAs($customer)
            ->from(route('account.orders.show', $order))
            ->post(route('account.orders.sync-payment', ['order' => $order, 'silent' => $silent ? '1' : '0']));
    }

    protected function webTrackShipment(User $customer, Order $order, OrderShipment $shipment): TestResponse
    {
        return $this->actingAs($customer)
            ->from(route('account.orders.show', $order))
            ->post(route('account.orders.shipments.track', ['order' => $order, 'shipment' => $shipment]));
    }

    protected function webConfirmReceived(User $customer, Order $order): TestResponse
    {
        return $this->actingAs($customer)
            ->from(route('account.orders.show', $order))
            ->post(route('account.orders.confirm-received', $order));
    }

    protected function webCancelOrder(User $customer, Order $order, ?string $reason = null): TestResponse
    {
        return $this->actingAs($customer)
            ->from(route('account.orders.show', $order))
            ->post(route('account.orders.cancel', $order), $reason !== null ? ['reason' => $reason] : []);
    }
}
