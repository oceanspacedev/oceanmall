<?php

declare(strict_types=1);

namespace Tests\Feature\E2E\VendorPackageTier\Support\Concerns;

use App\Models\Product;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Shopper\Core\Enum\AddressType;

/**
 * Reusable Customer Mobile API v1 (`/api/v1/*`) Sanctum actor helpers.
 */
trait InteractsWithCustomerApi
{
    protected function apiCustomerWithCart(Product $product, int $qty = 1, ?User $user = null): User
    {
        $customer = $user ?? $this->fixtures->createVerifiedCustomer();
        Sanctum::actingAs($customer);

        $this->postJson('/api/v1/cart/items', [
            'product_id' => $product->id,
            'quantity' => $qty,
        ])->assertOk();

        return $customer;
    }

    protected function apiGuestCartAdd(string $guestToken, Product $product, int $qty = 1): TestResponse
    {
        return $this->postJson('/api/v1/cart/items', [
            'product_id' => $product->id,
            'quantity' => $qty,
        ], [
            'X-Cart-Token' => $guestToken,
        ]);
    }

    protected function apiLogin(string $email, string $password = 'password', ?string $guestToken = null): TestResponse
    {
        $headers = $guestToken !== null ? ['X-Cart-Token' => $guestToken] : [];

        return $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => $password,
        ], $headers);
    }

    protected function apiUpdateCartItem(User $customer, int $lineId, int $qty): TestResponse
    {
        Sanctum::actingAs($customer);

        return $this->patchJson("/api/v1/cart/items/{$lineId}", [
            'quantity' => $qty,
        ]);
    }

    protected function apiRemoveCartItem(User $customer, int $lineId): TestResponse
    {
        Sanctum::actingAs($customer);

        return $this->deleteJson("/api/v1/cart/items/{$lineId}");
    }

    protected function apiAs(User $customer): self
    {
        Sanctum::actingAs($customer);

        return $this;
    }

    protected function apiLoadCheckout(User $customer): TestResponse
    {
        Sanctum::actingAs($customer);

        return $this->getJson('/api/v1/checkout');
    }

    protected function apiSearchDestinations(User $customer, string $query, int $limit = 10): TestResponse
    {
        Sanctum::actingAs($customer);

        return $this->getJson('/api/v1/checkout/destinations?q='.urlencode($query).'&limit='.$limit);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function apiSaveShippingAddress(User $customer, array $overrides = []): TestResponse
    {
        Sanctum::actingAs($customer);

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

        return $this->postJson('/api/v1/checkout/shipping-address', $payload);
    }

    protected function apiApplySavedAddress(User $customer, int $addressId): TestResponse
    {
        Sanctum::actingAs($customer);

        return $this->postJson('/api/v1/checkout/shipping-address/saved', [
            'address_id' => $addressId,
        ]);
    }

    /**
     * @param  string|int|array<int|string, string|int>  $serviceCodeOrRates
     */
    protected function apiPickShippingOption(User $customer, string|int|array $serviceCodeOrRates): TestResponse
    {
        Sanctum::actingAs($customer);

        $payload = is_array($serviceCodeOrRates)
            ? ['rates' => array_map(static fn (string|int $code): string => (string) $code, $serviceCodeOrRates)]
            : ['service_code' => (string) $serviceCodeOrRates];

        return $this->postJson('/api/v1/checkout/shipping-option', $payload);
    }

    protected function apiPlaceOrder(User $customer, int $paymentMethodId): TestResponse
    {
        Sanctum::actingAs($customer);

        return $this->postJson('/api/v1/checkout/place-order', [
            'payment_method_id' => $paymentMethodId,
        ]);
    }

    protected function apiListAddresses(User $customer): TestResponse
    {
        Sanctum::actingAs($customer);

        return $this->getJson('/api/v1/addresses');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function apiCreateAddress(User $customer, array $overrides = []): TestResponse
    {
        Sanctum::actingAs($customer);

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

        return $this->postJson('/api/v1/addresses', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function apiUpdateAddress(User $customer, int $addressId, array $payload): TestResponse
    {
        Sanctum::actingAs($customer);

        return $this->patchJson("/api/v1/addresses/{$addressId}", $payload);
    }

    protected function apiListOrders(User $customer, ?string $status = null): TestResponse
    {
        Sanctum::actingAs($customer);

        $uri = $status !== null ? '/api/v1/orders?status='.urlencode($status) : '/api/v1/orders';

        return $this->getJson($uri);
    }

    protected function apiShowOrder(User $customer, string $number): TestResponse
    {
        Sanctum::actingAs($customer);

        return $this->getJson("/api/v1/orders/{$number}");
    }

    protected function apiRetryPayment(User $customer, string $number): TestResponse
    {
        Sanctum::actingAs($customer);

        return $this->postJson("/api/v1/orders/{$number}/retry-payment");
    }

    protected function apiSyncPayment(User $customer, string $number): TestResponse
    {
        Sanctum::actingAs($customer);

        return $this->postJson("/api/v1/orders/{$number}/sync-payment");
    }

    protected function apiTrackShipment(User $customer, string $number, int $shipmentId): TestResponse
    {
        Sanctum::actingAs($customer);

        return $this->postJson("/api/v1/orders/{$number}/shipments/{$shipmentId}/track");
    }

    protected function apiConfirmReceived(User $customer, string $number): TestResponse
    {
        Sanctum::actingAs($customer);

        return $this->postJson("/api/v1/orders/{$number}/confirm-received");
    }

    protected function apiCancelOrder(User $customer, string $number, ?string $reason = null): TestResponse
    {
        Sanctum::actingAs($customer);

        return $this->postJson("/api/v1/orders/{$number}/cancel", $reason !== null ? ['reason' => $reason] : []);
    }
}
