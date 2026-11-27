<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\GetCountriesByZone;
use App\Actions\ZoneSessionManager;
use App\Http\Controllers\Controller;
use App\Shipping\AddressDestination;
use App\Shipping\ShippingCapabilities;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Shopper\Core\Enum\AddressType;
use Shopper\Core\Models\Address;
use Shopper\Core\Models\Country;

final class AddressController extends Controller
{
    public function index(): Response
    {
        $currentZoneId = ZoneSessionManager::getSession()?->zoneId;

        $allowedCountryIds = resolve(GetCountriesByZone::class)
            ->handle()
            ->when($currentZoneId, fn ($items) => $items->where('zoneId', $currentZoneId))
            ->pluck('countryId');

        $capabilities = resolve(ShippingCapabilities::class);
        $drivers = $capabilities->destinationDriverCode() !== null
            ? $capabilities->destinationDriverFamilyCodes()
            : null;

        $addresses = auth()->user()
            ->addresses()
            ->with('country')
            ->get()
            ->each(static function (Address $address) use ($drivers): void {
                $rawMeta = $address->getAttribute('metadata');
                if (is_string($rawMeta)) {
                    $decoded = json_decode($rawMeta, true);
                    $rawMeta = is_array($decoded) ? $decoded : [];
                }

                $normalized = AddressDestination::forDriver(is_array($rawMeta) ? $rawMeta : [], $drivers);
                $address->setAttribute('metadata', $normalized === [] ? null : $normalized);
            });

        return Inertia::render('account/addresses', [
            'addresses' => $addresses,
            'countries' => Country::query()
                ->when($allowedCountryIds->isNotEmpty(), fn ($q) => $q->whereIn('id', $allowedCountryIds))
                ->orderBy('name')
                ->get(['id', 'name', 'cca2']),
            'shippingCapabilities' => resolve(ShippingCapabilities::class)->toInertia(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateAddress($request);

        auth()->user()->addresses()->create($data);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Alamat berhasil ditambahkan.',
        ]);

        return redirect()->route('account.addresses');
    }

    public function update(Request $request, Address $address): RedirectResponse
    {
        abort_unless($address->user_id === auth()->id(), 403);

        $data = $this->validateAddress($request, $address);

        $address->update($data);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Alamat berhasil diperbarui.',
        ]);

        return redirect()->route('account.addresses');
    }

    public function destroy(Address $address): RedirectResponse
    {
        abort_unless($address->user_id === auth()->id(), 403);

        $address->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Alamat dihapus.',
        ]);

        return redirect()->route('account.addresses');
    }

    public function setDefaultShipping(Address $address): RedirectResponse
    {
        abort_unless($address->user_id === auth()->id(), 403);

        $this->applyDefault($address, 'shipping_default');

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Alamat pengiriman utama diperbarui.',
        ]);

        return redirect()->route('account.addresses');
    }

    public function setDefaultBilling(Address $address): RedirectResponse
    {
        abort_unless($address->user_id === auth()->id(), 403);

        $this->applyDefault($address, 'billing_default');

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Alamat penagihan utama diperbarui.',
        ]);

        return redirect()->route('account.addresses');
    }

    private function applyDefault(Address $address, string $column): void
    {
        auth()->user()->addresses()
            ->where($column, true)
            ->update([$column => false]);

        $address->update([$column => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateAddress(Request $request, ?Address $existing = null): array
    {
        $allowedCountries = resolve(GetCountriesByZone::class)->handle()->pluck('countryId')->filter()->all();
        $capabilities = resolve(ShippingCapabilities::class);
        $currentDriver = $capabilities->destinationDriverCode();

        $sent = [];
        foreach ([AddressDestination::ID, AddressDestination::LABEL, AddressDestination::PIN_POINT, AddressDestination::DRIVER] as $neutral) {
            $legacy = array_search($neutral, AddressDestination::LEGACY_KEYS, true);
            $sent[$neutral] = $request->exists($neutral) || (is_string($legacy) && $request->exists($legacy));
        }

        $request->merge(AddressDestination::fromRequest($request->all(), $currentDriver));

        $isShipping = (string) $request->input('type') === AddressType::Shipping->value;

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'street_address' => ['required', 'string', 'max:255'],
            'street_address_plus' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['required', 'string', 'max:20'],
            'city' => ['required', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'phone_number' => [$isShipping ? 'required' : 'nullable', 'string', 'max:30'],
            'country_id' => array_values(array_filter([
                'required',
                'integer',
                'exists:'.(new Country)->getTable().',id',
                $allowedCountries !== [] ? Rule::in($allowedCountries) : null,
            ])),
            'type' => ['required', Rule::enum(AddressType::class)],
            ...AddressDestination::rules(),
        ]);

        $sentDestId = $sent[AddressDestination::ID];

        $rawExistingMeta = $existing?->getAttribute('metadata');
        if (is_string($rawExistingMeta)) {
            $rawExistingMeta = json_decode($rawExistingMeta, true);
        }
        $current = AddressDestination::normalize(is_array($rawExistingMeta) ? $rawExistingMeta : []);
        $existingDestId = $current[AddressDestination::ID] ?? null;

        $regionChanged = $existing instanceof Address && (
            mb_strtolower(trim((string) $existing->city)) !== mb_strtolower(trim((string) $data['city']))
            || mb_strtolower(trim((string) $existing->postal_code)) !== mb_strtolower(trim((string) $data['postal_code']))
            || mb_strtolower(trim((string) ($existing->state ?? ''))) !== mb_strtolower(trim((string) ($data['state'] ?? '')))
        );

        if ($regionChanged && ! $sentDestId) {
            unset(
                $current[AddressDestination::ID],
                $current[AddressDestination::LABEL],
                $current[AddressDestination::DRIVER],
                $current[AddressDestination::PIN_POINT],
            );
        }

        $submittedDestId = $data[AddressDestination::ID] ?? null;

        foreach ([AddressDestination::ID, AddressDestination::LABEL, AddressDestination::PIN_POINT, AddressDestination::DRIVER] as $key) {
            if ($sent[$key] || array_key_exists($key, $data)) {
                $value = $data[$key] ?? null;

                if ($value === null || $value === '') {
                    unset($current[$key]);
                } else {
                    $current[$key] = $value;
                }
            }
            unset($data[$key]);
        }

        if (blank($current[AddressDestination::ID] ?? null)) {
            unset($current[AddressDestination::DRIVER]);
        }

        if ($sentDestId && filled($submittedDestId) && filled($current[AddressDestination::ID] ?? null)) {
            $destChanged = $current[AddressDestination::ID] !== $existingDestId;

            if (! $request->filled(AddressDestination::DRIVER) && ($destChanged || blank($current[AddressDestination::DRIVER] ?? null) || ($current[AddressDestination::DRIVER] ?? null) !== $currentDriver)) {
                if ($currentDriver !== null) {
                    $current[AddressDestination::DRIVER] = $currentDriver;
                } else {
                    unset($current[AddressDestination::DRIVER]);
                }
            }
        }

        $metadata = array_filter($current, static fn (mixed $v): bool => $v !== null && $v !== '');
        $data['metadata'] = $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR);

        return $data;
    }
}
