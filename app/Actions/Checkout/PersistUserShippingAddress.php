<?php

declare(strict_types=1);

namespace App\Actions\Checkout;

use App\Models\User;
use App\Shipping\AddressDestination;
use App\Shipping\ShippingCapabilities;
use Shopper\Core\Enum\AddressType;
use Shopper\Core\Models\Address;

final class PersistUserShippingAddress
{
    /**
     * Upsert the checkout shipping address into the customer's address book
     * so the next purchase can reuse street + provider destination.
     *
     * Destination fields are stored under the neutral metadata keys
     * (destination_id, destination_label, destination_driver, pin_point);
     * legacy rajaongkir_* input is accepted.
     *
     * @param  array<string, mixed>  $shippingAddress
     */
    public function handle(User $user, array $shippingAddress): Address
    {
        $countryId = (int) ($shippingAddress['country_id'] ?? 0);
        $street = trim((string) ($shippingAddress['street_address'] ?? ''));
        $postal = trim((string) ($shippingAddress['postal_code'] ?? ''));
        $city = trim((string) ($shippingAddress['city'] ?? ''));

        $existing = $user->addresses()
            ->where('type', AddressType::Shipping)
            ->where('street_address', $street)
            ->where('postal_code', $postal)
            ->where('city', $city)
            ->when($countryId > 0, fn ($q) => $q->where('country_id', $countryId))
            ->first();

        $metadata = $this->mergeMetadata($existing, $shippingAddress);

        $attributes = [
            'first_name' => (string) ($shippingAddress['first_name'] ?? ''),
            'last_name' => (string) ($shippingAddress['last_name'] ?? ''),
            'street_address' => $street,
            'street_address_plus' => $shippingAddress['street_address_plus'] ?? null,
            'postal_code' => $postal,
            'city' => $city,
            'state' => $shippingAddress['state'] ?? null,
            'phone_number' => $shippingAddress['phone_number'] ?? null,
            'country_id' => $countryId > 0 ? $countryId : ($existing?->country_id ?? null),
            'type' => AddressType::Shipping,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
        ];

        if ($existing) {
            $existing->update($attributes);
            $address = $existing->fresh();
        } else {
            $address = $user->addresses()->create($attributes);
        }

        $this->makeShippingDefault($user, $address);

        return $address->fresh();
    }

    /**
     * Build checkout session shipping address from a saved address book row.
     *
     * @return array<string, mixed>|null
     */
    public function toCheckoutShippingAddress(Address $address): ?array
    {
        $metadata = $this->decodeMetadata($address->metadata);
        $capabilities = resolve(ShippingCapabilities::class);

        // Only an id from the active destination driver's vocabulary counts:
        // an id saved for another provider is stale after a switch.
        $destinationId = AddressDestination::id($metadata, $capabilities->destinationDriverCode());

        if ($destinationId === null && $capabilities->requiresDestination()) {
            // Destination missing — still usable as form prefills, but not as
            // a complete checkout session address for rate quotes.
            return null;
        }

        return [
            'first_name' => (string) ($address->first_name ?? ''),
            'last_name' => (string) $address->last_name,
            'street_address' => (string) $address->street_address,
            'street_address_plus' => $address->street_address_plus,
            'postal_code' => (string) $address->postal_code,
            'city' => (string) $address->city,
            'state' => $address->state,
            'phone_number' => $address->phone_number,
            'country_id' => $address->country_id,
            AddressDestination::ID => $destinationId,
            AddressDestination::LABEL => $destinationId !== null ? AddressDestination::label($metadata) : null,
            AddressDestination::DRIVER => $destinationId !== null ? AddressDestination::driver($metadata) : null,
            AddressDestination::PIN_POINT => AddressDestination::pinPoint($metadata),
            'saved_address_id' => $address->id,
        ];
    }

    /**
     * @param  bool  $withLegacyKeys  Also emit rajaongkir_* aliases (mobile API, for app builds released before the neutral keys)
     * @return list<array<string, mixed>>
     */
    public function mapSavedAddressesForCheckout(User $user, bool $withLegacyKeys = false): array
    {
        // Only a destination in the vocabulary of today's destination driver
        // is offered: clients post the id back without its driver and the
        // checkout stamps the current one, so a stale id from a previous
        // provider must never round-trip (the customer picks again instead).
        // The address book itself keeps the stored id (see mergeMetadata()).
        $destinationDriver = resolve(ShippingCapabilities::class)->destinationDriverCode();

        return $user->addresses()
            ->with('country')
            ->orderByDesc('shipping_default')
            ->orderByDesc('updated_at')
            ->get()
            ->map(function (Address $address) use ($withLegacyKeys, $destinationDriver): array {
                $metadata = $this->decodeMetadata($address->metadata);
                // Without a destination driver nothing is quoted with the id,
                // so it is listed as stored (the address book keeps it).
                $destinationId = AddressDestination::id($metadata, $destinationDriver);

                $row = [
                    'id' => $address->id,
                    'first_name' => $address->first_name,
                    'last_name' => $address->last_name,
                    'street_address' => $address->street_address,
                    'street_address_plus' => $address->street_address_plus,
                    'postal_code' => $address->postal_code,
                    'city' => $address->city,
                    'state' => $address->state,
                    'phone_number' => $address->phone_number,
                    'country_id' => $address->country_id,
                    'type' => $address->type,
                    'shipping_default' => $address->shipping_default,
                    'billing_default' => $address->billing_default,
                    'country' => $address->country
                        ? [
                            'id' => $address->country->id,
                            'name' => $address->country->name,
                            'cca2' => $address->country->cca2,
                        ]
                        : null,
                    AddressDestination::ID => $destinationId,
                    AddressDestination::LABEL => $destinationId !== null ? AddressDestination::label($metadata) : null,
                    AddressDestination::DRIVER => $destinationId !== null
                        ? (AddressDestination::driver($metadata) ?? $destinationDriver)
                        : null,
                    AddressDestination::PIN_POINT => AddressDestination::pinPoint($metadata),
                ];

                return $withLegacyKeys
                    ? AddressDestination::withLegacyKeys($row, freeTextFallback: $destinationDriver === null)
                    : $row;
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $shippingAddress
     * @return array<string, mixed>
     */
    private function mergeMetadata(?Address $existing, array $shippingAddress): array
    {
        // Existing rows may still carry legacy keys: rewrite them first.
        $metadata = AddressDestination::normalize($existing ? $this->decodeMetadata($existing->metadata) : []);
        $incoming = AddressDestination::fromInput($shippingAddress);

        if (isset($incoming[AddressDestination::ID])) {
            // The stored id coming back unchanged keeps its driver; a new id
            // belongs to today's destination driver.
            $unchanged = $incoming[AddressDestination::ID] === ($metadata[AddressDestination::ID] ?? null);
            $storedDriver = $metadata[AddressDestination::DRIVER] ?? null;

            $metadata[AddressDestination::ID] = $incoming[AddressDestination::ID];
            $metadata[AddressDestination::DRIVER] = $incoming[AddressDestination::DRIVER]
                ?? ($unchanged ? $storedDriver : null)
                ?? resolve(ShippingCapabilities::class)->destinationDriverCode()
                ?? $storedDriver;
        }

        foreach ([AddressDestination::LABEL, AddressDestination::PIN_POINT] as $key) {
            if (isset($incoming[$key])) {
                $metadata[$key] = $incoming[$key];
            }
        }

        return array_filter($metadata, static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeMetadata(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }

        if (is_string($metadata) && $metadata !== '') {
            $decoded = json_decode($metadata, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function makeShippingDefault(User $user, Address $address): void
    {
        $user->addresses()
            ->where('shipping_default', true)
            ->whereKeyNot($address->id)
            ->update(['shipping_default' => false]);

        if (! $address->shipping_default) {
            $address->update(['shipping_default' => true]);
        }
    }
}
