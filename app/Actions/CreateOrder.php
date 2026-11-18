<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\Warehouse\SuggestAllocation;
use App\CheckoutSession;
use App\DTO\AllocationPlan;
use App\DTO\ShipmentDraft;
use App\Models\OrderShipment;
use App\Shipping\AddressDestination;
use App\Shipping\Contracts\NormalizesSelectedRates;
use App\Shipping\ShippingCapabilities;
use App\Support\CheckoutAllocationContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Shopper\Cart\Actions\CreateOrderFromCartAction;
use Shopper\Cart\Models\Cart;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\CarrierOption;
use Shopper\Core\Models\Order;
use Shopper\Payment\Enum\TransactionStatus;
use Shopper\Payment\Enum\TransactionType;
use Shopper\Payment\Models\PaymentTransaction;
use Shopper\Shipping\Facades\Shipping;
use Throwable;

final class CreateOrder
{
    /**
     * @param  array<string, mixed>|null  $checkout
     */
    public function handle(?array $checkout = null, ?Cart $cart = null): Order
    {
        $checkout ??= session()->get(CheckoutSession::KEY);

        abort_unless(
            $checkout
            && data_get($checkout, 'shipping_option')
            && data_get($checkout, 'payment'),
            422,
            __('Checkout session is incomplete or expired.'),
        );

        $cart ??= cartSession();

        abort_if(
            Auth::check() && $cart->customer_id !== null && $cart->customer_id !== Auth::id(),
            403,
        );

        if (Auth::check() && $cart->customer_id === null) {
            $cart->update(['customer_id' => Auth::id()]);
        }

        $lock = Cache::lock('checkout.create-order.'.$cart->id, 10);

        abort_unless($lock->get(), 409, __('A checkout is already in progress.'));

        try {
            return DB::transaction(function () use ($cart, $checkout): Order {
                $allocationPlan = $this->resolveAllocationPlan($cart, $checkout);

                $context = resolve(CheckoutAllocationContext::class);
                $context->set($allocationPlan);

                try {
                    $order = resolve(CreateOrderFromCartAction::class)->execute($cart);
                } finally {
                    $context->clear();
                }

                $shipmentTotal = $this->storeShipments($order, $allocationPlan, $checkout);
                $shippingPrice = $shipmentTotal ?? (int) data_get($checkout, 'shipping_option.0.price', 0);

                $order->update([
                    'customer_id' => $order->customer_id ?? Auth::id() ?? $cart->customer_id,
                    'shipping_option_id' => $this->shippingOptionId($checkout),
                    'payment_method_id' => data_get($checkout, 'payment.0.id'),
                    'price_amount' => $order->price_amount + $shippingPrice,
                ]);

                $this->storeShippingAddressMetadata($order, $checkout);
                $this->storePaymentMetadata($order, $checkout);
                $this->storePaymentReference($order, $checkout);

                return $order;
            });
        } finally {
            $lock->release();
        }
    }

    private function shippingOptionId(mixed $checkout): ?int
    {
        $id = data_get($checkout, 'shipping_option.0.id');

        if (is_int($id) && CarrierOption::query()->whereKey($id)->exists()) {
            return $id;
        }

        if (is_string($id) && ctype_digit($id)) {
            $numericId = (int) $id;
            if (CarrierOption::query()->whereKey($numericId)->exists()) {
                return $numericId;
            }
        }

        return null;
    }

    private function storeShippingAddressMetadata(Order $order, mixed $checkout): void
    {
        $shippingAddress = (array) data_get($checkout, 'shipping_address', []);
        $metadataAddress = [];

        $countryId = data_get($shippingAddress, 'country_id');
        if (is_int($countryId) || (is_string($countryId) && ctype_digit($countryId))) {
            $metadataAddress['country_id'] = (int) $countryId;
        }

        // Neutral destination keys (legacy rajaongkir_* session data is read too).
        foreach (AddressDestination::fromInput($shippingAddress) as $field => $value) {
            $metadataAddress[$field] = $value;
        }

        // Persist full address fields so delivery jobs can populate receiver_name /
        // receiver_address even when the sh_order_addresses row has null columns.
        foreach ([
            'first_name',
            'last_name',
            'street_address',
            'street_address_plus',
            'postal_code',
            'city',
            'state',
            'phone_number',
            'latitude',
            'longitude',
        ] as $field) {
            $value = data_get($shippingAddress, $field);
            if (is_scalar($value) && trim((string) $value) !== '') {
                $metadataAddress[$field] = trim((string) $value);
            }
        }

        if ($metadataAddress === []) {
            return;
        }

        $metadata = $this->decodeMetadata($order->getAttribute('metadata'));
        $existingAddress = data_get($metadata, 'shipping_address');
        $metadata['shipping_address'] = array_merge(
            is_array($existingAddress) ? $existingAddress : [],
            $metadataAddress,
        );

        // Rows written before the neutral keys existed may carry legacy names.
        $metadata['shipping_address'] = AddressDestination::normalize($metadata['shipping_address']);

        // Only what checkout already holds: coordinates the customer sent become
        // the pin point. Geocoding is never done here (no external call inside
        // the order transaction); a driver that RequiresCoordinates resolves
        // them lazily in its fulfilment path (CreateRajaOngkirDeliveryForShipment).
        if (blank($metadata['shipping_address'][AddressDestination::PIN_POINT] ?? null)
            && is_numeric($metadata['shipping_address']['latitude'] ?? null)
            && is_numeric($metadata['shipping_address']['longitude'] ?? null)) {
            $metadata['shipping_address'][AddressDestination::PIN_POINT] = $metadata['shipping_address']['latitude']
                .','.$metadata['shipping_address']['longitude'];
        }

        $order->forceFill([
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
        ])->save();
    }

    /**
     * The customer's payment selection, under the neutral metadata.payment
     * bucket whatever the driver (drivers read their own channel from it).
     */
    private function storePaymentMetadata(Order $order, mixed $checkout): void
    {
        $selectedPayment = (array) data_get($checkout, 'payment.0', []);
        $channelCode = data_get($selectedPayment, 'channel_code');
        $paymentType = data_get($selectedPayment, 'payment_type');

        if (! $channelCode && ! $paymentType) {
            return;
        }

        $metadata = $this->decodeMetadata($order->getAttribute('metadata'));
        $paymentMeta = is_array($metadata['payment'] ?? null) ? $metadata['payment'] : [];
        $paymentMeta['driver'] = $this->paymentDriver($order, $checkout);

        if ($channelCode) {
            $paymentMeta['channel_code'] = (string) $channelCode;
        }
        if ($paymentType) {
            $paymentMeta['payment_type'] = (string) $paymentType;
        }

        $metadata['payment'] = $paymentMeta;

        $order->forceFill([
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeMetadata(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }

        if (! is_string($metadata) || trim($metadata) === '') {
            return [];
        }

        $decoded = json_decode($metadata, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function resolveAllocationPlan(Cart $cart, mixed $checkout): AllocationPlan
    {
        $storedPlan = data_get($checkout, 'allocation_plan')
            ?? data_get($checkout, 'allocation');

        if ($storedPlan instanceof AllocationPlan) {
            return $storedPlan;
        }

        if (is_array($storedPlan)) {
            return $this->allocationPlanFromArray($storedPlan);
        }

        return resolve(SuggestAllocation::class)->handle(
            $cart,
            (array) data_get($checkout, 'shipping_address', []),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function allocationPlanFromArray(array $payload): AllocationPlan
    {
        $shipments = data_get($payload, 'shipments', $payload);

        return new AllocationPlan(
            collect($shipments)
                ->map(fn (mixed $shipment): ShipmentDraft => $this->shipmentDraftFromArray((array) $shipment))
                ->values()
                ->all(),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function shipmentDraftFromArray(array $payload): ShipmentDraft
    {
        return new ShipmentDraft(
            (int) data_get($payload, 'inventory_id'),
            collect(data_get($payload, 'lines', []))
                ->map(static fn (mixed $line): array => [
                    'purchasable_type' => (string) data_get($line, 'purchasable_type'),
                    'purchasable_id' => (int) data_get($line, 'purchasable_id'),
                    'qty' => (int) (data_get($line, 'qty') ?? data_get($line, 'quantity', 0)),
                ])
                ->values()
                ->all(),
        );
    }

    private function storeShipments(Order $order, AllocationPlan $allocationPlan, mixed $checkout): ?int
    {
        if ($allocationPlan->shipments === []) {
            return null;
        }

        $shipmentCount = count($allocationPlan->shipments);
        $total = 0;

        foreach ($allocationPlan->shipments as $shipmentDraft) {
            $rate = $this->rateForShipment($shipmentDraft, $checkout, $shipmentCount);
            $cost = $this->rateCost($rate);
            $total += $cost;
            $rate = $this->normalizeRate($rate, $cost);

            $carrierCode = $this->rateString($rate, 'carrier_code');
            $serviceCode = $this->rateString($rate, 'service_code')
                ?? $this->rateString($rate, 'id');

            $shipment = OrderShipment::query()->create([
                'order_id' => $order->id,
                'inventory_id' => $shipmentDraft->inventory_id,
                // Shopper shipping driver that quoted the selected rate.
                'driver' => $this->rateString($rate, 'driver'),
                'carrier_id' => $this->carrierId($rate),
                'carrier_code' => $carrierCode,
                'carrier_name' => $this->rateString($rate, 'shipping_name')
                    ?? $this->rateString($rate, 'carrier_name')
                    ?? $this->rateString($rate, 'name')
                    ?? ($carrierCode !== null ? strtoupper($carrierCode) : null),
                'service_code' => $serviceCode,
                'service_name' => $this->rateString($rate, 'service_name')
                    ?? $serviceCode,
                'cost' => $cost,
                'currency_code' => $this->rateString($rate, 'currency_code')
                    ?? $this->rateString($rate, 'currency')
                    ?? $order->currency_code,
                'status' => 'pending',
                'metadata' => $rate === [] ? null : ['rate' => $rate],
            ]);

            foreach ($shipmentDraft->lines as $line) {
                $shipment->lines()->create([
                    'purchasable_type' => $line['purchasable_type'],
                    'purchasable_id' => $line['purchasable_id'],
                    'qty' => $line['qty'],
                ]);
            }
        }

        return $total;
    }

    /**
     * @return array<string, mixed>
     */
    private function rateForShipment(ShipmentDraft $shipmentDraft, mixed $checkout, int $shipmentCount): array
    {
        $ratesByShipment = data_get($checkout, 'shipping_options_by_shipment', []);

        if (is_array($ratesByShipment)) {
            foreach ([
                $shipmentDraft->inventory_id,
                (string) $shipmentDraft->inventory_id,
            ] as $key) {
                if (array_key_exists($key, $ratesByShipment) && is_array($ratesByShipment[$key])) {
                    return $ratesByShipment[$key];
                }
            }

            foreach ($ratesByShipment as $rate) {
                if (
                    is_array($rate)
                    && (int) data_get($rate, 'inventory_id') === $shipmentDraft->inventory_id
                ) {
                    return $rate;
                }
            }
        }

        $globalRate = data_get($checkout, 'shipping_option.0');

        return $shipmentCount === 1 && is_array($globalRate) ? $globalRate : [];
    }

    /**
     * @param  array<string, mixed>  $rate
     */
    private function rateCost(array $rate): int
    {
        return (int) (data_get($rate, 'amount')
            ?? data_get($rate, 'price')
            ?? data_get($rate, 'cost')
            ?? 0);
    }

    /**
     * Let the driver that quoted the rate rewrite it into the shape its own
     * fulfilment needs (NormalizesSelectedRates). A rate without a driver
     * (checkout sessions older than driver tracking) goes to the single
     * configured fulfilling driver, as before.
     *
     * @param  array<string, mixed>  $rate
     * @return array<string, mixed>
     */
    private function normalizeRate(array $rate, int $cost): array
    {
        if ($rate === []) {
            return $rate;
        }

        $code = $this->rateString($rate, 'driver');
        $capabilities = resolve(ShippingCapabilities::class);

        try {
            $driver = $code !== null
                ? Shipping::driver($code)
                : $capabilities->fulfillerFor(new OrderShipment);
        } catch (Throwable) {
            $driver = null;
        }

        return $driver instanceof NormalizesSelectedRates
            ? $driver->normalizeSelectedRate($rate, $cost)
            : $rate;
    }

    /**
     * The Shopper carrier the selected rate belongs to, when it still exists.
     *
     * @param  array<string, mixed>  $rate
     */
    private function carrierId(array $rate): ?int
    {
        $id = data_get($rate, 'carrier_id');

        return is_numeric($id) && Carrier::query()->whereKey((int) $id)->exists() ? (int) $id : null;
    }

    /**
     * @param  array<string, mixed>  $rate
     */
    private function rateString(array $rate, string $key): ?string
    {
        $value = data_get($rate, $key);

        return is_scalar($value) && $value !== '' ? (string) $value : null;
    }

    /**
     * A payment reference created before the order (checkout sessions that
     * carry one) is recorded as the Initiate transaction of the order's own
     * payment driver.
     */
    private function storePaymentReference(Order $order, mixed $checkout): void
    {
        $reference = data_get($checkout, 'payment_reference')
            ?? data_get($checkout, 'payment.0.payment_reference')
            ?? data_get($checkout, 'payment.0.payment_id');

        if (! is_string($reference) || trim($reference) === '') {
            return;
        }

        PaymentTransaction::query()->updateOrCreate(
            [
                'order_id' => $order->id,
                'reference' => trim($reference),
            ],
            [
                'payment_method_id' => $order->payment_method_id,
                'driver' => $this->paymentDriver($order, $checkout),
                'type' => TransactionType::Initiate,
                'amount' => (int) $order->price_amount,
                'currency_code' => $order->currency_code,
                'status' => TransactionStatus::Pending,
                'metadata' => ['payment_reference' => trim($reference)],
            ],
        );
    }

    private function paymentDriver(Order $order, mixed $checkout): string
    {
        $order->loadMissing('paymentMethod');
        $driver = $order->paymentMethod?->driver ?? data_get($checkout, 'payment.0.driver');

        return is_string($driver) && trim($driver) !== '' ? trim($driver) : 'manual';
    }
}
