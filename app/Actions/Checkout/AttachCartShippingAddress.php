<?php

declare(strict_types=1);

namespace App\Actions\Checkout;

use Shopper\Cart\CartManager;
use Shopper\Cart\Models\Cart;
use Shopper\Core\Enum\AddressType;

/**
 * Copies the checkout shipping address (session / API checkout state) onto
 * the Shopper cart. Shopper builds the order address and the tax from the
 * cart's own address row, so a checkout address that lives only in the
 * checkout state (a saved address applied on web or mobile) would place an
 * order without a delivery address and with tax 0.
 */
final class AttachCartShippingAddress
{
    /**
     * @param  array<string, mixed>  $address  checkout shipping address
     */
    public function handle(Cart $cart, array $address): void
    {
        $countryId = $address['country_id'] ?? null;
        $countryId = is_numeric($countryId) && (int) $countryId > 0
            ? (int) $countryId
            : $cart->addresses()->where('type', AddressType::Shipping)->value('country_id');

        $payload = [
            'first_name' => (string) ($address['first_name'] ?? ''),
            'last_name' => (string) ($address['last_name'] ?? ''),
            'address_1' => (string) ($address['street_address'] ?? ''),
            'address_2' => $address['street_address_plus'] ?? null,
            'postal_code' => (string) ($address['postal_code'] ?? ''),
            'city' => (string) ($address['city'] ?? ''),
            'state' => filled($address['state'] ?? null) ? (string) $address['state'] : null,
            'phone' => $address['phone_number'] ?? null,
            'country_id' => $countryId,
        ];

        $cartManager = resolve(CartManager::class);
        $cartManager->addAddress($cart, AddressType::Shipping, $payload);

        if (! $cart->addresses()->where('type', AddressType::Billing)->exists()) {
            $cartManager->addAddress($cart, AddressType::Billing, $payload);
        }
    }
}
