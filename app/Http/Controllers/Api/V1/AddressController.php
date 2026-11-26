<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Checkout\PersistUserShippingAddress;
use App\Actions\GetCountriesByZone;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Shipping\AddressDestination;
use App\Shipping\ShippingCapabilities;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Shopper\Core\Enum\AddressType;
use Shopper\Core\Models\Address;
use Shopper\Core\Models\Country;

final class AddressController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->customer($request);

        return response()->json([
            'data' => resolve(PersistUserShippingAddress::class)->mapSavedAddressesForCheckout($user, withLegacyKeys: true),
            'countries' => Country::query()
                ->whereIn('id', resolve(GetCountriesByZone::class)->handle()->pluck('countryId'))
                ->orderBy('name')
                ->get(['id', 'name', 'cca2']),
            'shipping_capabilities' => resolve(ShippingCapabilities::class)->toApi(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->customer($request);
        $data = $this->validated($request);
        $address = $user->addresses()->create($data);

        if ($request->boolean('shipping_default')) {
            $this->applyDefault($user, $address, 'shipping_default');
        }

        return response()->json(['data' => $this->payload($address->fresh())], 201);
    }

    public function update(Request $request, int $address): JsonResponse
    {
        $user = $this->customer($request);
        $row = $this->owned($user, $address);
        $row->update($this->validated($request, $row));

        return response()->json(['data' => $this->payload($row->fresh())]);
    }

    public function destroy(Request $request, int $address): JsonResponse
    {
        $this->owned($this->customer($request), $address)->delete();

        return response()->json(['message' => 'Alamat dihapus.']);
    }

    public function setDefaultShipping(Request $request, int $address): JsonResponse
    {
        $user = $this->customer($request);
        $row = $this->owned($user, $address);
        $this->applyDefault($user, $row, 'shipping_default');

        return response()->json(['data' => $this->payload($row->fresh())]);
    }

    public function setDefaultBilling(Request $request, int $address): JsonResponse
    {
        $user = $this->customer($request);
        $row = $this->owned($user, $address);
        $this->applyDefault($user, $row, 'billing_default');

        return response()->json(['data' => $this->payload($row->fresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Address $existing = null): array
    {
        $allowedCountries = resolve(GetCountriesByZone::class)->handle()->pluck('countryId')->filter()->all();

        // Neutral destination fields; app builds already in the stores send
        // the legacy rajaongkir_* names. Remember which fields the client sent
        // (in either spelling) before merging the neutral aliases.
        $sent = [];
        foreach ([AddressDestination::ID, AddressDestination::LABEL, AddressDestination::PIN_POINT] as $neutral) {
            $legacy = array_search($neutral, AddressDestination::LEGACY_KEYS, true);
            $sent[$neutral] = $request->exists($neutral) || (is_string($legacy) && $request->exists($legacy));
        }
        $sentDriver = is_string($request->input(AddressDestination::DRIVER)) && trim($request->input(AddressDestination::DRIVER)) !== ''
            ? trim($request->input(AddressDestination::DRIVER))
            : null;
        $legacyOnly = AddressDestination::legacyOnly($request->all());
        $currentDriver = resolve(ShippingCapabilities::class)->destinationDriverCode();
        $request->merge(AddressDestination::fromRequest($request->all(), $currentDriver));

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'street_address' => ['required', 'string', 'max:255'],
            'street_address_plus' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['required', 'string', 'max:20'],
            'city' => ['required', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:30'],
            'country_id' => ['required', 'integer', Rule::in($allowedCountries)],
            'type' => ['required', Rule::enum(AddressType::class)],
            ...AddressDestination::rules(),
        ]);

        // On update, keys the client did not send keep their stored value
        // (a partial edit must not erase the destination or pin) UNLESS the
        // region itself (city / postal_code / state) changed without a new destination_id.
        $current = AddressDestination::normalize($existing ? $this->metadataOf($existing) : []);
        $regionChanged = $existing instanceof Address && (
            mb_strtolower(trim((string) $existing->city)) !== mb_strtolower(trim((string) $data['city']))
            || mb_strtolower(trim((string) $existing->postal_code)) !== mb_strtolower(trim((string) $data['postal_code']))
            || mb_strtolower(trim((string) ($existing->state ?? ''))) !== mb_strtolower(trim((string) ($data['state'] ?? '')))
        );

        if ($regionChanged && ! $sent[AddressDestination::ID]) {
            unset(
                $current[AddressDestination::ID],
                $current[AddressDestination::LABEL],
                $current[AddressDestination::DRIVER],
                $current[AddressDestination::PIN_POINT],
            );
        }

        $metadata = [];
        foreach ($sent as $key => $wasSent) {
            $metadata[$key] = $wasSent ? ($data[$key] ?? null) : ($current[$key] ?? null);
        }

        if ($sent[AddressDestination::ID]) {
            // The id posted back unchanged keeps the driver it was stored
            // with (clients do not send destination_driver), so it is never
            // re-stamped as today's destination driver after a provider switch.
            // A new id (neutral or legacy key: old app builds post the id
            // today's destination search returned) belongs to today's driver.
            $unchanged = $metadata[AddressDestination::ID] !== null
                && $metadata[AddressDestination::ID] === ($current[AddressDestination::ID] ?? null);

            $metadata[AddressDestination::DRIVER] = $metadata[AddressDestination::ID] === null
                ? null
                : ($sentDriver
                    ?? ($unchanged ? ($current[AddressDestination::DRIVER] ?? null) : null)
                    ?? $currentDriver
                    ?? ($legacyOnly ? AddressDestination::LEGACY_DRIVER : null)
                    ?? ($current[AddressDestination::DRIVER] ?? null));
        }

        $metadata = array_filter(
            [...$current, ...$metadata],
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );

        foreach (array_keys(AddressDestination::rules()) as $key) {
            unset($data[$key]);
        }
        $data['metadata'] = $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function metadataOf(Address $address): array
    {
        $raw = $address->getAttribute('metadata');
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        return is_array($raw) ? $raw : [];
    }

    private function owned(User $user, int $id): Address
    {
        return $user->addresses()->whereKey($id)->firstOrFail();
    }

    private function applyDefault(User $user, Address $address, string $column): void
    {
        $user->addresses()->where($column, true)->update([$column => false]);
        $address->update([$column => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Address $address): array
    {
        $owner = User::query()->find($address->user_id);
        if (! $owner instanceof User) {
            return ['id' => $address->id];
        }

        $list = resolve(PersistUserShippingAddress::class)->mapSavedAddressesForCheckout($owner, withLegacyKeys: true);
        foreach ($list as $row) {
            if ((int) $row['id'] === (int) $address->id) {
                return $row;
            }
        }

        return ['id' => $address->id];
    }

    private function customer(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
