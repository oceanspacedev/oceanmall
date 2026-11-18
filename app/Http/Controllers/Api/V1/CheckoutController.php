<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Checkout\BuildShippingPackages;
use App\Actions\Checkout\FetchDeliveryRates;
use App\Actions\Checkout\FetchPaymentMethods;
use App\Actions\Checkout\InitiateOrderPayment;
use App\Actions\Checkout\PersistUserShippingAddress;
use App\Actions\CreateOrder;
use App\Actions\GetCountriesByZone;
use App\Actions\Notify\NotifyOrderCustomer;
use App\Actions\Warehouse\SuggestAllocation;
use App\DTO\CountryByZoneData;
use App\Enums\OrderNotificationType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Payment\PaymentActions;
use App\Shipping\AddressDestination;
use App\Shipping\SelectedRate;
use App\Shipping\ShippingCapabilities;
use App\Support\CustomerCart;
use App\Support\CustomerCatalogPresenter;
use App\Support\CustomerCheckoutState;
use App\Support\ShipmentItemValue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Shopper\Cart\CartManager;
use Shopper\Cart\Models\Cart;
use Shopper\Core\Enum\AddressType;
use Shopper\Core\Models\PaymentMethod;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class CheckoutController extends Controller
{
    public function __construct(
        private readonly CustomerCart $customerCart,
        private readonly CustomerCheckoutState $checkoutState,
        private readonly CustomerCatalogPresenter $presenter,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $user = $this->customer($request);
        $cart = $this->customerCart->current($user)->load(['lines.purchasable.media']);
        $state = $this->checkoutState->get($user);
        $address = is_array($state['shipping_address'] ?? null) ? $state['shipping_address'] : null;
        $capabilities = resolve(ShippingCapabilities::class);
        $destinationDriver = $capabilities->destinationDriverCode();

        $rates = [];
        if (is_array($address) && $cart->lines->isNotEmpty()) {
            $rates = $this->ratesForCart($cart, $address);
        }

        $countryId = is_array($address) ? (int) ($address['country_id'] ?? 0) : 0;
        if ($countryId < 1) {
            $countryId = (int) ($this->defaultCountry()?->countryId ?? 0);
        }

        $methods = $this->appPaymentMethods($countryId);

        $context = $cart->lines->isEmpty() ? null : resolve(CartManager::class)->calculate($cart);

        return response()->json([
            'data' => [
                'cart' => $this->presenter->cart($cart, $context),
                // Neutral destination keys plus rajaongkir_* aliases for app
                // builds released before the neutral keys existed. Like web
                // checkout, a destination outside today's driver vocabulary
                // (stale after a provider switch) is cleared, so the app never
                // posts a foreign id back as the current driver's. Without a
                // destination driver nothing reads the id and it is echoed;
                // an address without one gets a free-text legacy id so old
                // builds reuse it without a new search.
                'shipping_address' => is_array($address)
                    ? AddressDestination::withLegacyKeys($destinationDriver !== null
                        ? AddressDestination::forDriver($address, $destinationDriver)
                        : $address, freeTextFallback: $destinationDriver === null)
                    : null,
                'shipping_option' => $state['shipping_option'][0] ?? null,
                'shipping_rates' => $rates,
                'shipping_capabilities' => $capabilities->toApi(),
                'payment_methods' => $methods,
                'payment' => $state['payment'][0] ?? null,
                'saved_addresses' => resolve(PersistUserShippingAddress::class)->mapSavedAddressesForCheckout($user, withLegacyKeys: true),
            ],
        ]);
    }

    public function applySavedAddress(Request $request): JsonResponse
    {
        $data = $request->validate([
            'address_id' => ['required', 'integer'],
        ]);

        $user = $this->customer($request);
        $address = $user->addresses()->whereKey($data['address_id'])->firstOrFail();
        $mapped = resolve(PersistUserShippingAddress::class)->toCheckoutShippingAddress($address);
        if ($mapped === null) {
            return response()->json([
                'message' => 'Alamat belum punya kecamatan tujuan. Pilih kecamatan dulu.',
            ], 422);
        }

        $this->checkoutState->putShippingAddress($user, $mapped);

        return $this->show($request);
    }

    public function saveShippingAddress(Request $request): JsonResponse
    {
        $capabilities = resolve(ShippingCapabilities::class);
        $destinationRule = $capabilities->requiresDestination()
            ? ['required', 'string', 'max:50']
            : ['nullable', 'string', 'max:50'];

        // Neutral fields; app builds already in the stores send rajaongkir_*
        // holding an id of today's destination driver (see fromRequest()).
        $request->merge(AddressDestination::fromRequest($request->all(), $capabilities->destinationDriverCode()));

        $data = $this->validateWithLegacyErrorKeys($request, [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'street_address' => ['required', 'string', 'max:255'],
            'street_address_plus' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['required', 'string', 'max:20'],
            'city' => ['required', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'phone_number' => ['required', 'string', 'max:20'],
            ...AddressDestination::rules($destinationRule),
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
        ]);

        $data = AddressDestination::normalize($data, $capabilities->destinationDriverCode());

        $zone = $this->defaultCountry();
        $data['country_id'] = $zone?->countryId;
        if (! $data['country_id']) {
            return response()->json(['message' => 'Wilayah pengiriman belum tersedia.'], 422);
        }

        if (filled($data['latitude'] ?? null) && filled($data['longitude'] ?? null) && blank($data[AddressDestination::PIN_POINT] ?? null)) {
            $data[AddressDestination::PIN_POINT] = $data['latitude'].','.$data['longitude'];
        }

        $user = $this->customer($request);

        // Builds released before shipping_capabilities reusing an address (no
        // destination driver) send no province: keep the one of that region.
        $current = $this->checkoutState->get($user)['shipping_address'] ?? null;
        if (! $request->has('state') && is_array($current)
            && (string) ($current['postal_code'] ?? '') === (string) $data['postal_code']
            && (string) ($current['city'] ?? '') === (string) $data['city']) {
            $data['state'] = $current['state'] ?? null;
        }

        $saved = resolve(PersistUserShippingAddress::class)->handle($user, $data);
        $data['saved_address_id'] = $saved->id;
        $this->checkoutState->putShippingAddress($user, $data);

        $cart = $this->customerCart->current($user);
        resolve(CartManager::class)->addAddress($cart, AddressType::Shipping, [
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'address_1' => $data['street_address'],
            'address_2' => $data['street_address_plus'] ?? null,
            'postal_code' => $data['postal_code'],
            'city' => $data['city'],
            'phone' => $data['phone_number'] ?? null,
            'country_id' => $data['country_id'],
        ]);

        return $this->show($request);
    }

    public function saveShippingOption(Request $request): JsonResponse
    {
        $user = $this->customer($request);
        $state = $this->checkoutState->get($user);
        $address = $state['shipping_address'] ?? null;
        if (! is_array($address)) {
            return response()->json(['message' => 'Alamat pengiriman belum diisi.'], 422);
        }

        $data = $request->validate([
            'service_code' => ['required', 'string'],
        ]);

        $cart = $this->customerCart->current($user);
        $rates = $this->ratesForCart($cart, $address);
        $selected = collect($rates)->first(
            fn (array $option): bool => (string) $option['service_code'] === (string) $data['service_code'],
        );

        if (! is_array($selected)) {
            return response()->json(['message' => 'Opsi pengiriman tidak tersedia.'], 422);
        }

        // Generic fields plus whatever details the quoting driver attached.
        $this->checkoutState->putShippingOption($user, [SelectedRate::fromQuote($selected)]);

        $state = $this->checkoutState->get($user);
        try {
            $plan = resolve(SuggestAllocation::class)->handle($cart, $address);
            $state['allocation_plan'] = $plan;
            $this->checkoutState->put($user, $state);
        } catch (Throwable $e) {
            report($e);
        }

        return $this->show($request);
    }

    public function placeOrder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'payment_method_id' => ['required', 'integer'],
        ]);

        $user = $this->customer($request);
        $method = PaymentMethod::query()->where('is_enabled', true)->find($data['payment_method_id']);
        if (! $method) {
            return response()->json(['message' => 'Metode pembayaran tidak valid.'], 422);
        }

        $countryId = (int) (data_get($this->checkoutState->get($user), 'shipping_address.country_id')
            ?: $this->defaultCountry()?->countryId
            ?: 0);
        $options = $countryId > 0 ? resolve(FetchPaymentMethods::class)->handle($countryId) : [];
        $selected = collect($options)->first(fn (array $row): bool => (int) $row['id'] === (int) $method->id);
        if (! is_array($selected)) {
            return response()->json(['message' => 'Metode pembayaran tidak tersedia.'], 422);
        }

        // Confirm-first gateways (ConfirmsBeforeOrder: card / embedded form)
        // must be confirmed before the order exists, which only web checkout
        // can do; the app would be left with an order it cannot pay.
        if (PaymentActions::confirmFirstDriver($selected['driver'] ?? null) !== null) {
            return response()->json(['message' => 'Metode pembayaran ini hanya tersedia di website.'], 422);
        }

        $this->checkoutState->putPayment($user, $selected);
        $checkout = $this->checkoutState->get($user);
        $cart = $this->customerCart->current($user);

        if ($cart->lines->isEmpty()) {
            return response()->json(['message' => 'Keranjang kosong.'], 422);
        }

        if (! is_array(data_get($checkout, 'shipping_option.0'))) {
            return response()->json(['message' => 'Pilih kurir pengiriman dulu.'], 422);
        }

        // Reject a method the gateway would refuse for this amount *before*
        // the order exists (e.g. QRIS above its maximum).
        $payable = (int) (resolve(CartManager::class)->calculate($cart)->total ?? 0)
            + (int) data_get($checkout, 'shipping_option.0.amount', data_get($checkout, 'shipping_option.0.price', 0));
        if ($message = $this->amountOutOfRange($selected, $payable)) {
            return response()->json(['message' => $message], 422);
        }

        try {
            $order = resolve(CreateOrder::class)->handle($checkout, $cart);
        } catch (HttpExceptionInterface $e) {
            return response()->json([
                'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Gagal membuat pesanan.',
            ], $e->getStatusCode());
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Gagal membuat pesanan. Coba lagi sebentar.'], 422);
        }

        // From here the order exists and the cart is completed: always answer
        // with the order number, even if the payment step fails, so the app can
        // open the order and offer "retry payment" instead of a dead end.
        $this->checkoutState->forget($user);

        try {
            resolve(NotifyOrderCustomer::class)->handle($order, OrderNotificationType::AwaitingPayment);
        } catch (Throwable $e) {
            report($e);
        }

        // Same shared step as web checkout: Shopper's PaymentProcessingService
        // for every driver, answered with the driver's neutral payment action.
        $action = null;
        $paymentError = null;

        try {
            ['result' => $result, 'action' => $action] = resolve(InitiateOrderPayment::class)->handle($order, $selected);

            if (! $result->success) {
                $paymentError = 'Pesanan dibuat, tetapi pembayaran belum bisa dibuat. Coba bayar ulang dari halaman pesanan.';
            }
        } catch (Throwable $e) {
            report($e);
            $paymentError = 'Pesanan dibuat, tetapi pembayaran belum bisa dibuat. Coba bayar ulang dari halaman pesanan.';
        }

        return response()->json([
            'data' => [
                'order_id' => $order->id,
                'number' => $order->number,
                'amount' => (int) $order->price_amount,
                'currency' => $order->currency_code,
                'payment_status' => $order->refresh()->payment_status->value,
                'payment_action' => $action,
                // Legacy key for app builds that predate payment_action.
                'payment' => PaymentActions::legacyInstructions($action),
                'payment_error' => $paymentError,
                'can_retry_payment' => $paymentError !== null && resolve(PaymentActions::class)->canRetry($order),
            ],
        ], 201);
    }

    /**
     * Payment methods the app can pay with: every available method except
     * confirm-first ones (embedded card forms the app cannot render).
     *
     * @return list<array<string, mixed>>
     */
    private function appPaymentMethods(int $countryId): array
    {
        if ($countryId < 1) {
            return [];
        }

        return array_values(array_filter(
            resolve(FetchPaymentMethods::class)->handle($countryId),
            static fn (array $method): bool => PaymentActions::confirmFirstDriver($method['driver'] ?? null) === null,
        ));
    }

    /**
     * @param  array<string, mixed>  $method
     */
    private function amountOutOfRange(array $method, int $amount): ?string
    {
        $min = $method['min_amount'] ?? null;
        $max = $method['max_amount'] ?? null;
        $title = (string) ($method['title'] ?? 'Metode ini');

        if (is_int($min) && $min > 0 && $amount < $min) {
            return "{$title} hanya untuk total minimal Rp".number_format($min, 0, ',', '.').'. Pilih metode lain.';
        }

        if (is_int($max) && $max > 0 && $amount > $max) {
            return "{$title} hanya untuk total maksimal Rp".number_format($max, 0, ',', '.').'. Pilih metode lain.';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $address
     * @return list<array<string, mixed>>
     */
    private function ratesForCart(Cart $cart, array $address): array
    {
        try {
            $plan = resolve(SuggestAllocation::class)->handle($cart, $address);
        } catch (Throwable) {
            return [];
        }

        if ($plan->shipments === []) {
            return [];
        }

        $lines = $plan->shipments[0]->lines;
        $packages = resolve(BuildShippingPackages::class)->handleFromLines($lines);

        $rates = resolve(FetchDeliveryRates::class)->handle(
            $address,
            $packages,
            $plan->shipments[0]->inventory_id,
            ShipmentItemValue::forCart($cart, $lines),
        );

        // Cheapest first; a stable sort keeps the provider order for ties.
        usort($rates, static fn (array $a, array $b): int => (int) ($a['amount'] ?? 0) <=> (int) ($b['amount'] ?? 0));

        return $rates;
    }

    /**
     * Validate, and mirror errors on neutral destination fields under their
     * legacy rajaongkir_* names so older app builds still highlight the field.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function validateWithLegacyErrorKeys(Request $request, array $rules): array
    {
        try {
            return $request->validate($rules);
        } catch (ValidationException $e) {
            $errors = $e->errors();

            foreach (AddressDestination::LEGACY_KEYS as $legacy => $neutral) {
                if (isset($errors[$neutral]) && ! isset($errors[$legacy])) {
                    $errors[$legacy] = $errors[$neutral];
                }
            }

            throw ValidationException::withMessages($errors);
        }
    }

    private function customer(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function defaultCountry(): ?CountryByZoneData
    {
        $countries = resolve(GetCountriesByZone::class)->handle();

        return $countries->firstWhere('countryCode', 'ID') ?? $countries->first();
    }
}
