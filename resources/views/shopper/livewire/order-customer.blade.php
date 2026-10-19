{{--
    Rendered by App\Livewire\Shopper\OrderCustomer. Shopper's own view is included
    untouched with $shippingAddress = null, which hides its shipping block (the
    Komerce shipping panel already shows the destination) and the billing block
    nested inside it. The billing address is re-added below only when it differs
    from the shipping address.
--}}
<div>
    @include('shopper::livewire.components.orders.order-customer', ['shippingAddress' => null])

    @if ($separateBillingAddress)
        <div class="space-y-3 border-t border-gray-200 py-4 dark:border-white/10">
            <h3
                class="text-xs leading-4 font-medium tracking-wider text-gray-900 uppercase dark:text-white"
            >
                {{ __('shopper::pages/customers.addresses.billing') }}
            </h3>

            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ $separateBillingAddress->full_name }}
                <br />
                @if ($separateBillingAddress->company)
                    {{ $separateBillingAddress->company }}
                    <br />
                @endif

                {{ $separateBillingAddress->street_address }}
                <br />
                {{ $separateBillingAddress->postal_code }},
                {{ $separateBillingAddress->city }}
                <br />
                {{ $separateBillingAddress->country_name }}
                <br />
                @if ($separateBillingAddress->phone)
                    <span>
                        {{ $separateBillingAddress->phone }}
                    </span>
                @endif
            </p>
        </div>
    @endif
</div>
