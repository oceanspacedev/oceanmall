<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shop;

use App\Actions\Checkout\AttachCartShippingAddress;
use App\Actions\Checkout\BuildShippingPackages;
use App\Actions\Checkout\FetchDeliveryRates;
use App\Actions\Checkout\FetchPaymentMethods;
use App\Actions\Checkout\InitiateOrderPayment;
use App\Actions\Checkout\PersistUserShippingAddress;
use App\Actions\Checkout\SelectedShipmentRates;
use App\Actions\CreateOrder;
use App\Actions\Notify\NotifyOrderCustomer;
use App\Actions\Shipping\DispatchShipmentFulfillment;
use App\Actions\Warehouse\SuggestAllocation;
use App\Actions\ZoneSessionManager;
use App\CheckoutSession;
use App\DTO\AllocationPlan;
use App\DTO\ShipmentDraft;
use App\Enums\OrderNotificationType;
use App\Http\Controllers\Controller;
use App\Payment\Contracts\ConfirmsBeforeOrder;
use App\Payment\Drivers\ConfirmFirstStripeDriver;
use App\Payment\PaymentActions;
use App\Payment\PaymentMethodCatalog;
use App\Shipping\AddressDestination;
use App\Shipping\SelectedRate;
use App\Shipping\ShippingCapabilities;
use App\Shipping\ShippingQuoteContext;
use App\Support\OrderPaymentType;
use App\Support\ShipmentItemValue;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Shopper\Cart\CartManager;
use Shopper\Cart\CartSessionManager;
use Shopper\Cart\Models\Cart;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Events\Orders\OrderPaid;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Shopper\Payment\Contracts\PaymentDriver;
use Shopper\Payment\Models\PaymentTransaction;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class CheckoutController extends Controller
{
    /** Session key holding the payment action of the order just placed. */
    public const PAYMENT_ACTION_SESSION = 'payment_action';

    /**
     * Session key of the payment prepared in an embedded form before the
     * order exists (drivers implementing ConfirmsBeforeOrder):
     * {driver, reference, data}.
     */
    public const CONFIRM_PAYMENT_SESSION = 'confirm_payment';

    /**
     * Session keys of a prepared confirm-first payment, including the ones
     * written by releases before ConfirmsBeforeOrder.
     *
     * @var list<string>
     */
    public const CONFIRM_PAYMENT_KEYS = [self::CONFIRM_PAYMENT_SESSION, 'stripe_payment', 'stripe_intent_id'];

    public function index(Request $request): RedirectResponse|Response
    {
        $cart = resolve(CartSessionManager::class)->current();

        if (! $cart || $cart->lines->isEmpty()) {
            return redirect()->route('shop.cart');
        }

        $cart->load(['lines.purchasable.media']);
        $context = resolve(CartManager::class)->calculate($cart);

        $checkoutCartId = session()->get('checkout_cart_id');

        if ($checkoutCartId !== null && $checkoutCartId !== $cart->id) {
            session()->forget([CheckoutSession::KEY, ...self::CONFIRM_PAYMENT_KEYS, 'checkout_cart_id']);
        }

        session()->put('checkout_cart_id', $cart->id);

        $checkout = session()->get(CheckoutSession::KEY, []);
        $shippingAddress = data_get($checkout, 'shipping_address');
        $shippingOption = data_get($checkout, 'shipping_option.0');
        $payment = data_get($checkout, 'payment.0');

        $persistAddress = resolve(PersistUserShippingAddress::class);

        // Returning customers: reuse default saved address (incl. district)
        // so they land on shipping rates instead of retyping the form.
        if (! is_array($shippingAddress) || $shippingAddress === []) {
            $user = Auth::user();
            if ($user) {
                $defaultAddress = $user->addresses()
                    ->where('shipping_default', true)
                    ->orderByDesc('updated_at')
                    ->first()
                    ?? $user->addresses()->orderByDesc('updated_at')->first();

                if ($defaultAddress) {
                    $applied = $persistAddress->toCheckoutShippingAddress($defaultAddress);
                    if ($applied !== null) {
                        session()->put(CheckoutSession::SHIPPING_ADDRESS, $applied);
                        // The cart address drives the tax and the order address.
                        resolve(AttachCartShippingAddress::class)->handle($cart, $applied);
                        $cart->unsetRelation('addresses');
                        $context = resolve(CartManager::class)->calculate($cart);
                        $shippingAddress = $applied;
                        $checkout = session()->get(CheckoutSession::KEY, []);
                    }
                }
            }
        }

        $deliveryOptions = [];
        $allocation = null;
        $deliveryOptionsByShipment = [];

        if ($shippingAddress) {
            resolve(ShippingQuoteContext::class)->restoreFailure(null);

            $packages = resolve(BuildShippingPackages::class)->handle();

            [$allocation, $deliveryOptionsByShipment] = $this->resolveAllocationAndRates(
                $cart,
                $shippingAddress,
                $packages,
                $checkout,
            );

            if (count($deliveryOptionsByShipment) === 1) {
                $deliveryOptions = reset($deliveryOptionsByShipment) ?: [];
            } elseif ($allocation !== null && count($deliveryOptionsByShipment) === 0) {
                $deliveryOptions = resolve(FetchDeliveryRates::class)->handle($shippingAddress, $packages);
            }

            // The plan is recomputed on every render: when it no longer
            // matches the picked rates (the cart now splits or merges, ships
            // from another warehouse, or a package's lines changed), the
            // customer picks the courier again.
            $plan = session()->get(CheckoutSession::ALLOCATION_PLAN);

            if ($shippingOption && $allocation !== null && $plan instanceof AllocationPlan
                && ! resolve(SelectedShipmentRates::class)->coverPlan($plan, $checkout)) {
                session()->forget([CheckoutSession::SHIPPING_OPTION, CheckoutSession::SHIPPING_OPTIONS_BY_SHIPMENT, ...self::CONFIRM_PAYMENT_KEYS]);
                $checkout = session()->get(CheckoutSession::KEY, []);
                $shippingOption = null;
            }
        }

        $paymentOptions = [];
        $countryId = data_get($shippingAddress, 'country_id')
            ?? ZoneSessionManager::ensureSession()?->countryId;

        if ($countryId && is_array($shippingAddress) && ! data_get($shippingAddress, 'country_id')) {
            $shippingAddress['country_id'] = $countryId;
            session()->put(CheckoutSession::SHIPPING_ADDRESS, $shippingAddress);
        }

        if ($countryId) {
            // confirm_in_page: the method is paid in an embedded form before
            // the order exists (paymentAction kind 'client_secret').
            $paymentOptions = array_map(
                static fn (array $option): array => [
                    ...$option,
                    'confirm_in_page' => self::confirmFirstDriver($option['driver'] ?? null) !== null,
                ],
                resolve(FetchPaymentMethods::class)->handle((int) $countryId),
            );
        }

        $maxStep = match (true) {
            (bool) $shippingOption => 3,
            (bool) $shippingAddress => 2,
            default => 1,
        };

        $requestedStep = (int) $request->integer('step', $maxStep);
        $step = min(max($requestedStep, 1), $maxStep);

        $prepared = session()->get(self::CONFIRM_PAYMENT_SESSION);

        $selectedByShipment = data_get($checkout, 'shipping_options_by_shipment', []);

        $user = Auth::user();
        $capabilities = resolve(ShippingCapabilities::class);
        $destinationDrivers = $capabilities->destinationDriverCode() !== null
            ? $capabilities->destinationDriverFamilyCodes()
            : null;

        return Inertia::render('shop/checkout', [
            'cart' => $cart,
            'cartContext' => $context,
            'savedAddresses' => $user
                ? $persistAddress->mapSavedAddressesForCheckout($user)
                : [],
            // Neutral keys for the form (sessions saved before the neutral keys
            // still carry rajaongkir_*), destination only in today's vocabulary.
            'shippingAddress' => is_array($shippingAddress)
                ? AddressDestination::forDriver($shippingAddress, $destinationDrivers)
                : $shippingAddress,
            'deliveryOptions' => $deliveryOptions,
            'selectedDeliveryOption' => is_array($shippingOption)
                ? SelectedRate::optionKeyOf(is_array($shippingOption[0] ?? null) ? $shippingOption[0] : $shippingOption)
                : null,
            'allocation' => $allocation,
            'deliveryOptionsByShipment' => $deliveryOptionsByShipment,
            'selectedRatesByShipment' => collect($selectedByShipment)->map(
                fn (mixed $rate): string => is_array($rate) ? (string) (SelectedRate::optionKeyOf($rate) ?? '') : (string) $rate
            )->all(),
            'paymentOptions' => $paymentOptions,
            'selectedPaymentMethod' => $payment['id'] ?? null,
            'step' => $step,
            // Neutral payment action for a confirm-in-page method prepared
            // before the order exists (ConfirmsBeforeOrder): the page renders
            // the embedded form registered for `driver`.
            'paymentAction' => $this->preparedPaymentAction($prepared),
            'shippingCapabilities' => resolve(ShippingCapabilities::class)->toInertia(),
            'shippingRatesHint' => $this->shippingRatesHint(
                is_array($shippingAddress) ? $shippingAddress : null,
                $allocation,
                $deliveryOptions,
                $deliveryOptionsByShipment,
            ),
            'couponCode' => $cart->coupon_code,
        ]);
    }

    /**
     * Actionable copy when step 2 has no courier options.
     *
     * @param  array<string, mixed>|null  $shippingAddress
     * @param  list<array<string, mixed>>|null  $allocation
     * @param  list<array<string, mixed>>  $deliveryOptions
     * @param  array<int|string, list<array<string, mixed>>>  $deliveryOptionsByShipment
     */
    private function shippingRatesHint(
        ?array $shippingAddress,
        ?array $allocation,
        array $deliveryOptions,
        array $deliveryOptionsByShipment,
    ): ?string {
        if ($shippingAddress === null) {
            return null;
        }

        $allPackagesHaveRates = $deliveryOptionsByShipment !== []
            ? ! collect($deliveryOptionsByShipment)->contains(
                static fn (mixed $opts): bool => ! is_array($opts) || $opts === []
            )
            : $deliveryOptions !== [];

        if ($allPackagesHaveRates) {
            return null;
        }

        $capabilities = resolve(ShippingCapabilities::class);
        $destinationDrivers = $capabilities->destinationDriverCode() !== null
            ? $capabilities->destinationDriverFamilyCodes()
            : null;

        if ($capabilities->requiresDestination()
            && AddressDestination::id($shippingAddress, $destinationDrivers) === null) {
            return __('Kecamatan tujuan pengiriman belum dipilih. Silakan kembali ke langkah Alamat dan pilih kecamatan dari hasil pencarian otomatis.');
        }

        if ($allocation === null || $allocation === []) {
            return __('Stok produk di keranjang tidak dapat dipenuhi oleh lokasi gudang toko saat ini.');
        }

        $failure = resolve(ShippingQuoteContext::class)->lastFailure();

        // Shoppers get neutral copy; the configuration hint goes to the log,
        // once per address and cause per hour (every step-2/3 render and
        // status poll lands here, so a zone without CarrierOptions would
        // otherwise flood the log).
        $context = [
            'country_id' => $shippingAddress['country_id'] ?? null,
            'provider_failure' => $failure === null ? null : [
                'driver' => $failure->driver,
                'reason' => $failure->reason,
                'message' => $failure->message,
            ],
            'configured_drivers' => $capabilities->drivers()->keys()->all(),
            'hint' => 'Enable a carrier with a CarrierOption for this zone in /cpanel (Settings > Zones), configure a shipping provider, or set shopper.shipping.fallback_to_zone_options.',
        ];
        $fingerprint = sha1(json_encode([
            $context['country_id'],
            $shippingAddress['postal_code'] ?? null,
            $shippingAddress['city'] ?? null,
            AddressDestination::id($shippingAddress),
            $failure?->driver,
            $failure?->reason,
            $context['configured_drivers'],
        ]) ?: '');

        if (Cache::add('checkout:no-shipping-option:'.$fingerprint, true, now()->addHour())) {
            Log::notice('Checkout found no shipping option for the address.', $context);
        }

        if ($failure?->isQuota()) {
            return __('Batas cek ongkir harian toko ini sudah tercapai. Silakan coba lagi setelah :time WIB atau hubungi kami untuk menyelesaikan pesanan.', [
                'time' => $failure->retryAfter?->copy()->setTimezone('Asia/Jakarta')->format('d M Y H:i') ?? '00:00',
            ]);
        }

        if ($failure !== null) {
            return __('Gagal mengambil tarif ongkir (gangguan koneksi ke layanan pengiriman). Silakan muat ulang halaman atau coba lagi beberapa saat.');
        }

        return __('Belum ada opsi pengiriman untuk alamat ini. Silakan periksa kembali alamat pengiriman atau hubungi kami.');
    }

    public function saveShippingAddress(Request $request): RedirectResponse
    {
        $capabilities = resolve(ShippingCapabilities::class);
        $destinationRule = $capabilities->requiresDestination()
            ? ['required', 'string', 'max:50']
            : ['nullable', 'string', 'max:50'];

        // Neutral fields; legacy rajaongkir_* names are still accepted and
        // numeric ids from JSON/Inertia are coerced before string rules.
        $request->merge(AddressDestination::fromRequest($request->all(), $capabilities->destinationDriverCode()));

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'street_address' => ['required', 'string', 'max:255'],
            'street_address_plus' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['required', 'string', 'max:20'],
            'city' => ['required', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:20'],
            ...AddressDestination::rules($destinationRule),
        ]);

        $data = AddressDestination::normalize($data, $capabilities->destinationDriverCode());

        $zone = ZoneSessionManager::ensureSession();
        $data['country_id'] = $zone?->countryId;

        if (! $data['country_id']) {
            return back()->withErrors([
                'city' => 'Wilayah pengiriman belum tersedia. Muat ulang halaman lalu coba lagi.',
            ]);
        }

        // A delivery option is quoted for one destination: after the address
        // changes the customer picks it again (as the API checkout state
        // does), so the order is never charged the previous address's rate.
        session()->forget([
            CheckoutSession::SHIPPING_OPTION,
            CheckoutSession::SHIPPING_OPTIONS_BY_SHIPMENT,
            CheckoutSession::ALLOCATION_PLAN,
            CheckoutSession::PAYMENT,
            ...self::CONFIRM_PAYMENT_KEYS,
        ]);

        session()->put(CheckoutSession::SHIPPING_ADDRESS, $data);

        if ($cart = resolve(CartSessionManager::class)->current()) {
            resolve(AttachCartShippingAddress::class)->handle($cart, $data);
        }

        if ($user = Auth::user()) {
            $saved = resolve(PersistUserShippingAddress::class)->handle($user, $data);
            $data['saved_address_id'] = $saved->id;
            session()->put(CheckoutSession::SHIPPING_ADDRESS, $data);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Alamat pengiriman disimpan.',
        ]);

        return redirect()->route('shop.checkout.index');
    }

    public function saveShippingOption(Request $request): RedirectResponse
    {
        $shippingAddress = session()->get(CheckoutSession::SHIPPING_ADDRESS);

        if (! $shippingAddress) {
            return redirect()->route('shop.checkout.index');
        }

        $cart = resolve(CartSessionManager::class)->current();
        if (! $cart) {
            return redirect()->route('shop.cart');
        }

        if ($request->has('rates') && is_array($request->input('rates'))) {
            return $this->saveShippingOptionsByShipment($request, $shippingAddress);
        }

        $data = $request->validate([
            'service_code' => ['required'],
        ]);

        $allocationPlan = session()->get(CheckoutSession::ALLOCATION_PLAN);

        // A cart split over several warehouses needs a rate per package
        // (the `rates` map): one service_code would leave the others unpaid.
        if ($allocationPlan instanceof AllocationPlan && count($allocationPlan->shipments) > 1) {
            return back()->withErrors(['service_code' => __('Please select a delivery option for all packages.')]);
        }

        $singleShipment = ($allocationPlan instanceof AllocationPlan && count($allocationPlan->shipments) === 1)
            ? $allocationPlan->shipments[0]
            : null;

        $buildPackages = resolve(BuildShippingPackages::class);
        $packages = $singleShipment !== null
            ? $buildPackages->handleFromLines($singleShipment->lines)
            : $buildPackages->handle();

        $originInventoryId = $singleShipment?->inventory_id;
        $itemValue = $singleShipment !== null
            ? ShipmentItemValue::forCart($cart, $singleShipment->lines)
            : (int) resolve(CartManager::class)->calculate($cart)->total;
        $deliveryOptions = resolve(FetchDeliveryRates::class)->handle($shippingAddress, $packages, $originInventoryId, $itemValue);

        $selected = SelectedRate::pick($deliveryOptions, $data['service_code']);

        if (! $selected) {
            return back()->withErrors(['service_code' => __('Selected option is no longer available.')]);
        }

        // A payment prepared for the previous delivery price is void too.
        session()->forget([CheckoutSession::SHIPPING_OPTION, CheckoutSession::SHIPPING_OPTIONS_BY_SHIPMENT, ...self::CONFIRM_PAYMENT_KEYS]);
        // Generic fields plus whatever details the quoting driver attached,
        // and the package it was quoted for (a re-plan of it voids the rate).
        session()->push(CheckoutSession::SHIPPING_OPTION, $singleShipment !== null
            ? resolve(SelectedShipmentRates::class)->stamp(SelectedRate::fromQuote($selected), $singleShipment)
            : SelectedRate::fromQuote($selected));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Opsi pengiriman dipilih.',
        ]);

        return redirect()->route('shop.checkout.index');
    }

    /**
     * Save per-shipment rates (multi-package path).
     *
     * @param  array<string, mixed>  $shippingAddress
     */
    private function saveShippingOptionsByShipment(Request $request, array $shippingAddress): RedirectResponse
    {
        $data = $request->validate([
            'rates' => ['required', 'array'],
            'rates.*' => ['required', 'string'],
        ]);

        $cart = resolve(CartSessionManager::class)->current();

        if (! $cart) {
            return redirect()->route('shop.cart');
        }

        $buildPackages = resolve(BuildShippingPackages::class);
        $fetchRates = resolve(FetchDeliveryRates::class);

        $allocationPlan = session()->get(CheckoutSession::ALLOCATION_PLAN);

        if (! $allocationPlan instanceof AllocationPlan) {
            try {
                $allocationPlan = resolve(SuggestAllocation::class)->handle($cart, $shippingAddress);
                session()->put(CheckoutSession::ALLOCATION_PLAN, $allocationPlan);
            } catch (Throwable) {
                return back()->withErrors(['rates' => __('Unable to determine shipment allocation.')]);
            }
        }

        $selectedRates = [];
        $totalPrice = 0;

        foreach ($allocationPlan->shipments as $shipmentDraft) {
            $inventoryId = $shipmentDraft->inventory_id;
            $serviceCode = $data['rates'][$inventoryId] ?? $data['rates'][(string) $inventoryId] ?? null;

            if (! $serviceCode) {
                return back()->withErrors(['rates' => __('Please select a delivery option for all packages.')]);
            }

            $shipmentPackages = $buildPackages->handleFromLines($shipmentDraft->lines);
            $options = $fetchRates->handle($shippingAddress, $shipmentPackages, $inventoryId, ShipmentItemValue::forCart($cart, $shipmentDraft->lines));
            $selected = SelectedRate::pick($options, $serviceCode);

            if (! $selected) {
                return back()->withErrors(['rates' => __('Selected option is no longer available.')]);
            }

            // Tied to the package it was quoted for (a re-plan of it voids the rate).
            $selectedRates[$inventoryId] = resolve(SelectedShipmentRates::class)
                ->stamp(SelectedRate::fromQuote($selected), $shipmentDraft);

            $totalPrice += (int) $selected['amount'];
        }

        session()->put(CheckoutSession::SHIPPING_OPTIONS_BY_SHIPMENT, $selectedRates);

        session()->forget([CheckoutSession::SHIPPING_OPTION, ...self::CONFIRM_PAYMENT_KEYS]);
        session()->push(CheckoutSession::SHIPPING_OPTION, [
            'id' => 'split-shipment',
            'name' => 'Split shipment',
            'price' => $totalPrice,
            'service_code' => 'split-shipment',
            'carrier_code' => 'multi',
            'currency' => 'IDR',
            'estimated_days' => null,
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Opsi pengiriman dipilih.',
        ]);

        return redirect()->route('shop.checkout.index');
    }

    /**
     * Save the payment method selection. For a confirm-first driver
     * (ConfirmsBeforeOrder) also prepare its payment for the cart total: the
     * order is created only once the provider confirms it (confirmReturn).
     * Every other driver creates the order in placeOrder.
     */
    public function preparePayment(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'payment_method_id' => ['required', 'integer'],
        ]);

        [$selectedMethod, $error] = $this->resolveSelectedMethod((int) $data['payment_method_id']);

        if ($error) {
            return $error;
        }

        session()->forget(CheckoutSession::PAYMENT);
        session()->push(CheckoutSession::PAYMENT, $selectedMethod);

        $code = (string) ($selectedMethod['driver'] ?? '');
        $driver = self::confirmFirstDriver($code);

        if ($driver === null) {
            session()->forget(self::CONFIRM_PAYMENT_KEYS);

            return redirect()->route('shop.checkout.index', ['step' => 3]);
        }

        $cart = resolve(CartSessionManager::class)->current();

        if (! $cart || $cart->lines->isEmpty()) {
            return redirect()->route('shop.cart');
        }

        $checkout = session()->get(CheckoutSession::KEY, []);
        $shippingOption = data_get($checkout, 'shipping_option.0');

        if (! is_array($shippingOption)) {
            return back()->withErrors(['shipping' => __('Please select a delivery option first.')]);
        }

        $plan = session()->get(CheckoutSession::ALLOCATION_PLAN);
        if ($plan instanceof AllocationPlan && ! resolve(SelectedShipmentRates::class)->coverPlan($plan, is_array($checkout) ? $checkout : [])) {
            return back()->withErrors(['shipping' => __('Please select a delivery option for all packages.')]);
        }

        $context = resolve(CartManager::class)->calculate($cart);
        $shippingPrice = (int) data_get($shippingOption, 'price', 0);
        $amount = (int) $context->total + $shippingPrice;
        $currency = (string) $cart->currency_code;

        if ($message = PaymentMethodCatalog::amountOutOfRange($selectedMethod, $amount)) {
            return back()->withErrors(['payment' => $message]);
        }

        try {
            $prepared = $driver->prepareBeforeOrder($amount, $currency, [
                'cart_id' => $cart->id,
                'customer_id' => Auth::id() ?? 0,
            ]);
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors(['payment' => __('Unable to prepare payment. Please try again.')]);
        }

        session()->forget(self::CONFIRM_PAYMENT_KEYS);
        session()->put(self::CONFIRM_PAYMENT_SESSION, [
            'driver' => $code,
            'reference' => $prepared['reference'],
            'amount' => $amount,
            'currency' => $currency,
            'data' => $prepared['data'],
        ]);

        return redirect()->route('shop.checkout.index', ['step' => 3]);
    }

    /**
     * Every driver but a confirm-first one: create the order, then initiate its payment
     * through Shopper's PaymentProcessingService (InitiateOrderPayment). The
     * driver's neutral payment action decides what the customer sees next:
     * instructions / QR on the success page, or a hosted payment page.
     * Confirm-first drivers (ConfirmsBeforeOrder) use confirmReturn.
     */
    public function placeOrder(Request $request): HttpResponse
    {
        $data = $request->validate([
            'payment_method_id' => ['required', 'integer'],
        ]);

        [$selectedMethod, $error] = $this->resolveSelectedMethod((int) $data['payment_method_id']);

        if ($error) {
            return $error;
        }

        if (self::confirmFirstDriver($selectedMethod['driver'] ?? null) !== null) {
            return back()->withErrors(['payment' => __('Gunakan formulir pembayaran di halaman ini untuk menyelesaikan pesanan.')]);
        }

        // Refuse a method the gateway would refuse for this total BEFORE the
        // order exists (QRIS above its maximum, a VA below its minimum), as
        // the app's checkout does.
        // Only for a real checkout: an empty cart or an expired session is
        // answered by CreateOrder with its own message, not "minimum amount".
        $cart = resolve(CartSessionManager::class)->current();
        $checkoutState = session()->get(CheckoutSession::KEY);
        $shippingOption = data_get($checkoutState, 'shipping_option.0');

        if ($cart !== null && $cart->lines->isNotEmpty() && is_array($shippingOption)) {
            $isCodMethod = strtolower(trim((string) ($selectedMethod['slug'] ?? ''))) === 'cod'
                || strtolower(trim((string) ($selectedMethod['payment_type'] ?? ''))) === 'cod';

            if ($isCodMethod && ! OrderPaymentType::shippingRatesSupportCod($checkoutState)) {
                return back()->withErrors(['payment' => __('Kurir yang dipilih tidak mendukung Cash on Delivery (COD). Pilih kurir atau metode pembayaran lain.')]);
            }

            $payable = (int) resolve(CartManager::class)->calculate($cart)->total
                + (int) data_get($shippingOption, 'price', 0);

            if ($message = PaymentMethodCatalog::amountOutOfRange($selectedMethod, $payable)) {
                return back()->withErrors(['payment' => $message]);
            }
        }

        session()->forget(CheckoutSession::PAYMENT);
        session()->push(CheckoutSession::PAYMENT, $selectedMethod);

        $order = null;

        try {
            $order = resolve(CreateOrder::class)->handle();

            // A notification that cannot be stored or mailed never stops the
            // checkout: the cart is still cleared and the payment initiated.
            // A manual / COD order has nothing to pay online: it is "placed",
            // not "awaiting payment".
            try {
                $notificationType = ($selectedMethod['driver'] ?? 'manual') === 'manual'
                    ? OrderNotificationType::OrderPlaced
                    : OrderNotificationType::AwaitingPayment;
                resolve(NotifyOrderCustomer::class)->handle($order, $notificationType);
            } catch (Throwable $e) {
                report($e);
            }

            // The order owns the reserved stock from here on: the customer
            // pays (or retries) on the order, never by re-ordering.
            session()->forget(CheckoutSession::KEY);
            resolve(CartSessionManager::class)->forget();

            ['result' => $result, 'action' => $action] = resolve(InitiateOrderPayment::class)->handle($order, $selectedMethod);

            if (OrderPaymentType::isCod($order)) {
                resolve(DispatchShipmentFulfillment::class)->handle($order);
            }
        } catch (HttpExceptionInterface $e) {
            // CreateOrder refuses with a customer-facing 422 (last unit sold
            // meanwhile, a package without a delivery option): show that
            // message instead of the generic error. Nothing was created.
            if ($order === null) {
                return back()->withErrors([
                    'order' => $e->getMessage() !== '' ? $e->getMessage() : __('An error occurred while placing your order. Please try again.'),
                ]);
            }

            report($e);

            return redirect()
                ->route('account.orders.show', ['order' => $order->id])
                ->with('error', __('Your order was placed but payment setup failed. You can retry payment from this order page.'));
        } catch (Throwable $e) {
            report($e);

            if ($order !== null) {
                return redirect()
                    ->route('account.orders.show', ['order' => $order->id])
                    ->with('error', __('Your order was placed but payment setup failed. You can retry payment from this order page.'));
            }

            return back()->withErrors(['order' => __('An error occurred while placing your order. Please try again.')]);
        }

        if (! $result->success) {
            return redirect()
                ->route('shop.checkout.success', ['order' => $order->id])
                ->with('error', $result->message ?? __('Payment initiation failed.'));
        }

        if (($action['kind'] ?? null) === 'redirect' && is_string($action['data']['url'] ?? null)) {
            // Place order is an Inertia XHR: an external 302 would be followed
            // by the XHR itself (CORS-blocked). Inertia::location answers 409 +
            // X-Inertia-Location so the client does a full-page visit; plain
            // requests still get a 302.
            return Inertia::location($action['data']['url']);
        }

        session()->put(self::PAYMENT_ACTION_SESSION, $action);

        return redirect()->route('shop.checkout.success', ['order' => $order->id]);
    }

    /**
     * The provider sends the customer back here after confirming a payment
     * prepared before the order existed (ConfirmsBeforeOrder): verify it, then
     * create the order.
     */
    public function confirmReturn(Request $request, string $driver): RedirectResponse
    {
        $prepared = session()->get(self::CONFIRM_PAYMENT_SESSION);
        $confirmer = self::confirmFirstDriver($driver, servicing: true);
        $reference = $confirmer?->returnReference($request);

        if ($confirmer === null
            || $reference === null
            || ! is_array($prepared)
            || ($prepared['driver'] ?? null) !== $driver
            || ($prepared['reference'] ?? null) !== $reference) {
            return redirect()->route('shop.checkout.index')
                ->withErrors(['payment' => __('Invalid payment session.')]);
        }

        $lock = Cache::lock('checkout.confirm-return.'.$driver.'.'.$reference, 15);

        try {
            $lock->block(10);
        } catch (LockTimeoutException) {
            return redirect()->route('shop.checkout.index')
                ->withErrors(['payment' => __('Your payment is still being processed. Please wait a moment.')]);
        }

        try {
            return $this->finalizeConfirmedPayment($confirmer, $driver, $reference, (string) $request->query('redirect_status', ''));
        } finally {
            $lock->release();
        }
    }

    /**
     * Return URL of releases before ConfirmsBeforeOrder (PaymentIntents
     * confirmed while the deploy happened still come back here).
     */
    public function stripeReturn(Request $request): RedirectResponse
    {
        return $this->confirmReturn($request, 'stripe');
    }

    /**
     * Old /checkout/payment/{number} links (the removed Stripe payment page,
     * in emails and browser history): the order page shows the order's
     * current payment action, like that page did once its session expired.
     */
    public function legacyPaymentPage(string $number): RedirectResponse
    {
        $order = Order::query()
            ->where('number', $number)
            ->where('customer_id', Auth::id())
            ->firstOrFail();

        return redirect()->route('shop.checkout.success', ['order' => $order->id]);
    }

    private function finalizeConfirmedPayment(ConfirmsBeforeOrder $confirmer, string $driver, string $reference, string $redirectStatus): RedirectResponse
    {
        $existingTransaction = PaymentTransaction::query()
            ->where('reference', $reference)
            ->where('driver', $driver)
            ->first();

        if ($existingTransaction) {
            session()->forget([...self::CONFIRM_PAYMENT_KEYS, CheckoutSession::KEY, 'checkout_cart_id']);

            return redirect()->route('shop.checkout.success', ['order' => $existingTransaction->order_id]);
        }

        $prepared = session()->get(self::CONFIRM_PAYMENT_SESSION);
        $expectedAmount = is_numeric($prepared['amount'] ?? null) ? (int) $prepared['amount'] : null;
        $expectedCurrency = is_string($prepared['currency'] ?? null) && trim($prepared['currency']) !== ''
            ? strtoupper(trim($prepared['currency']))
            : null;

        $status = $confirmer->confirmedStatus($reference, $expectedAmount, $expectedCurrency);

        if (! in_array($status, [PaymentStatus::Paid, PaymentStatus::Authorized, PaymentStatus::Pending], true)) {
            session()->forget(self::CONFIRM_PAYMENT_KEYS);

            return redirect()->route('shop.checkout.index', ['step' => 3])
                ->withErrors(['payment' => __('Payment was not completed. Please try again.').' ('.($redirectStatus ?: 'unknown').')']);
        }

        $cart = resolve(CartSessionManager::class)->current();
        if ($cart && ! $cart->lines->isEmpty() && $expectedAmount !== null) {
            $shippingPrice = (int) data_get(session()->get(CheckoutSession::KEY), 'shipping_option.0.price', 0);
            $liveTotal = (int) resolve(CartManager::class)->calculate($cart)->total + $shippingPrice;

            if ($liveTotal !== $expectedAmount || ($expectedCurrency !== null && strtoupper((string) $cart->currency_code) !== $expectedCurrency)) {
                $reversed = $this->attemptPaymentReversal($confirmer, $reference, $status, $expectedAmount, $expectedCurrency);
                session()->forget(self::CONFIRM_PAYMENT_KEYS);

                report(new RuntimeException(sprintf(
                    'Confirm-first payment %s (%s) refused: cart total changed from %d %s to %d %s (reversed: %s).',
                    $reference,
                    $driver,
                    $expectedAmount,
                    $expectedCurrency ?? 'IDR',
                    $liveTotal,
                    (string) $cart->currency_code,
                    $reversed ? 'yes' : 'no',
                )));

                return redirect()->route('shop.checkout.index', ['step' => 3])
                    ->withErrors(['payment' => __('Total keranjang berubah setelah pembayaran disiapkan. Silakan ulangi konfirmasi pembayaran.')]);
            }
        }

        try {
            // A paid confirmation fires Shopper's OrderPaid (listeners run
            // after commit: customer notification, provider shipments).
            $order = DB::transaction(function () use ($confirmer, $reference, $status, $expectedAmount): Order {
                $order = resolve(CreateOrder::class)->handle();

                if ($expectedAmount !== null && (int) $order->price_amount !== $expectedAmount) {
                    throw new RuntimeException(sprintf(
                        'Order total %d no longer matches confirmed payment amount %d.',
                        (int) $order->price_amount,
                        $expectedAmount,
                    ));
                }

                $confirmer->attachOrder($order, $reference, $status);
                $order->update(['payment_status' => $status]);

                if ($status === PaymentStatus::Paid) {
                    event(new OrderPaid($order->refresh()));
                }

                return $order;
            });
        } catch (Throwable $e) {
            $reversed = $this->attemptPaymentReversal($confirmer, $reference, $status, $expectedAmount, $expectedCurrency);
            session()->forget(self::CONFIRM_PAYMENT_KEYS);

            report(new RuntimeException(sprintf(
                'Order creation failed after confirmed %s payment %s (status=%s, amount=%s %s, auto_reversed=%s): %s',
                $driver,
                $reference,
                $status->value,
                $expectedAmount !== null ? (string) $expectedAmount : 'unknown',
                $expectedCurrency ?? 'unknown',
                $reversed ? 'yes' : 'no',
                $e->getMessage(),
            ), 0, $e));

            return redirect()->route('shop.cart')->withErrors(['order' => __('Order creation failed after payment.')]);
        }

        session()->forget([...self::CONFIRM_PAYMENT_KEYS, CheckoutSession::KEY, 'checkout_cart_id']);
        resolve(CartSessionManager::class)->forget();

        return redirect()->route('shop.checkout.success', ['order' => $order->id]);
    }

    private function attemptPaymentReversal(
        ConfirmsBeforeOrder $confirmer,
        string $reference,
        PaymentStatus $status,
        ?int $expectedAmount,
        ?string $expectedCurrency,
    ): bool {
        if (! $confirmer instanceof PaymentDriver) {
            return false;
        }

        try {
            if ($status === PaymentStatus::Authorized) {
                return $confirmer->cancelPayment($reference)->success;
            }

            if ($status === PaymentStatus::Paid && $confirmer->supportsRefunds() && $expectedAmount !== null && $expectedAmount > 0) {
                $refundAmount = $confirmer instanceof ConfirmFirstStripeDriver && $expectedCurrency !== null
                    ? ConfirmFirstStripeDriver::toStripeAmount($expectedAmount, $expectedCurrency)
                    : $expectedAmount;

                return $confirmer->refundPayment($reference, $refundAmount, 'requested_by_customer')->success;
            }
        } catch (Throwable $e) {
            report($e);
        }

        return false;
    }

    /**
     * The confirm-first payment driver for a code, when it is ready.
     */
    public static function confirmFirstDriver(?string $code, bool $servicing = false): ?ConfirmsBeforeOrder
    {
        return PaymentActions::confirmFirstDriver($code, $servicing);
    }

    /**
     * @return array{driver: string, kind: string, data: array<string, mixed>}|null
     */
    private function preparedPaymentAction(mixed $prepared): ?array
    {
        if (! is_array($prepared) || ! is_string($prepared['driver'] ?? null) || ! is_array($prepared['data'] ?? null)) {
            return null;
        }

        if (self::confirmFirstDriver($prepared['driver']) === null) {
            return null;
        }

        return [
            'driver' => $prepared['driver'],
            'kind' => 'client_secret',
            'data' => [
                ...$prepared['data'],
                'return_url' => route('shop.checkout.confirm-return', ['driver' => $prepared['driver']]),
            ],
        ];
    }

    /**
     * Run SuggestAllocation, store plan in session, return [allocationArray, deliveryOptionsByShipment].
     *
     * @param  array<string, mixed>  $shippingAddress
     * @param  array<int, mixed>  $packages
     * @param  array<string, mixed>  $checkout
     * @return array{0: array<string, mixed>|null, 1: array<int|string, array<int, array<string, mixed>>>}
     */
    private function resolveAllocationAndRates(
        Cart $cart,
        array $shippingAddress,
        array $packages,
        array $checkout,
    ): array {
        try {
            $plan = resolve(SuggestAllocation::class)->handle($cart, $shippingAddress);
        } catch (Throwable) {
            session()->forget(CheckoutSession::ALLOCATION_PLAN);

            return [null, []];
        }

        session()->put(CheckoutSession::ALLOCATION_PLAN, $plan);

        $allocationArray = array_map(
            static fn (ShipmentDraft $draft): array => [
                'inventory_id' => $draft->inventory_id,
                'lines' => $draft->lines,
            ],
            $plan->shipments,
        );

        $inventoryIds = array_column($allocationArray, 'inventory_id');
        $inventories = Inventory::query()
            ->whereIn('id', $inventoryIds)
            ->get()
            ->keyBy('id');

        foreach ($allocationArray as &$draft) {
            $inv = $inventories->get($draft['inventory_id']);
            $draft['inventory_name'] = $inv?->getAttribute('name') ?? (string) $draft['inventory_id'];
        }
        unset($draft);

        $fetchRates = resolve(FetchDeliveryRates::class);
        $buildPackages = resolve(BuildShippingPackages::class);
        $deliveryOptionsByShipment = [];

        foreach ($plan->shipments as $shipmentDraft) {
            $shipmentPackages = $buildPackages->handleFromLines($shipmentDraft->lines);
            $rates = $fetchRates->handle($shippingAddress, $shipmentPackages, $shipmentDraft->inventory_id, ShipmentItemValue::forCart($cart, $shipmentDraft->lines));
            $deliveryOptionsByShipment[$shipmentDraft->inventory_id] = $rates;
        }

        return [$allocationArray, $deliveryOptionsByShipment];
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: RedirectResponse|null}
     */
    private function resolveSelectedMethod(int $paymentMethodId): array
    {
        $shippingAddress = session()->get(CheckoutSession::SHIPPING_ADDRESS);
        $countryId = data_get($shippingAddress, 'country_id')
            ?? ZoneSessionManager::ensureSession()?->countryId;

        if (! $countryId) {
            return [null, redirect()->route('shop.checkout.index')];
        }

        $paymentOptions = resolve(FetchPaymentMethods::class)->handle((int) $countryId);
        $selected = collect($paymentOptions)
            ->first(fn (array $method): bool => $method['id'] === $paymentMethodId);

        if (! $selected) {
            return [null, back()->withErrors(['payment_method_id' => __('Selected payment method is no longer available.')])];
        }

        return [$selected, null];
    }
}
