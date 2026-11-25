<?php

declare(strict_types=1);

namespace App\Models;

use Shopper\Core\Models\Price as Model;

/**
 * A product / variant price as the storefront serialises it.
 *
 * Storefront pages hand Eloquent models to Inertia as they are, so every
 * serialised attribute is public: the merchant's cost price is hidden here,
 * once, for every page (App\Traits\HasProductPricing::prices()). Code that
 * needs it (the cpanel pricing form and table, reports) reads the attribute
 * itself, `$price->cost_amount`, which `$hidden` does not affect.
 */
final class Price extends Model
{
    /** @var list<string> */
    protected $hidden = ['cost_amount'];
}
