<?php

declare(strict_types=1);

namespace App;

final class CheckoutSession
{
    public const string KEY = 'checkout';

    public const string SHIPPING_ADDRESS = 'checkout.shipping_address';

    public const string BILLING_ADDRESS = 'checkout.billing_address';

    public const string SAME_AS_SHIPPING = 'checkout.same_as_shipping';

    public const string SHIPPING_OPTION = 'checkout.shipping_option';

    public const string PAYMENT = 'checkout.payment';

    public const string ALLOCATION_PLAN = 'checkout.allocation_plan';

    public const string SHIPPING_OPTIONS_BY_SHIPMENT = 'checkout.shipping_options_by_shipment';

    /**
     * A payment the customer prepared before the order exists (confirm-first
     * drivers) plus its legacy keys. It was prepared for one total: whatever
     * changes the total (address, delivery option, zone, cart) forgets it.
     *
     * @var list<string>
     */
    public const array CONFIRM_PAYMENT_KEYS = ['confirm_payment', 'stripe_payment', 'stripe_intent_id'];
}
