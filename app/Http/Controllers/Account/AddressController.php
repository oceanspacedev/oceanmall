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

        return Inertia::render('account/addresses', [
            'addresses' => auth()->user()
                ->addresses()
                ->with('country')
                ->get(),
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

        $sentDestId = $request->exists(AddressDestination::ID)
            || $request->exists('rajaongkir_destination_id');

        $rawExistingMeta = $existing?->getAttribute('metadata');
        if (is_string($rawExistingMeta)) {
            $rawExistingMeta = json_decode($rawExistingMeta, true);
        }
        $current = AddressDestination::normalize(is_array($rawExistingMeta) ? $rawExistingMeta : []);

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

        foreach ([AddressDestination::ID, AddressDestination::LABEL, AddressDestination::PIN_POINT, AddressDestination::DRIVER] as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '') {
                $current[$key] = $data[$key];
            }
            unset($data[$key]);
        }

        if ($sentDestId && filled($current[AddressDestination::ID] ?? null) && blank($current[AddressDestination::DRIVER] ?? null)) {
            $current[AddressDestination::DRIVER] = $currentDriver;
        }

        $metadata = array_filter($current, static fn (mixed $v): bool => $v !== null && $v !== '');
        $data['metadata'] = $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR);

        return $data;
    }
}
