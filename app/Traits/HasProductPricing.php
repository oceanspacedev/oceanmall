<?php

declare(strict_types=1);

namespace App\Traits;

use App\DTO\PriceData;
use App\Models\Price as PriceModel;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Shopper\Core\Helpers\Price;

trait HasProductPricing
{
    /**
     * Prices as App\Models\Price, which never serialises the merchant's
     * cost price: storefront pages pass these models to the browser as-is.
     *
     * @return MorphMany<PriceModel, $this>
     */
    public function prices(): MorphMany
    {
        return $this->morphMany(PriceModel::class, 'priceable');
    }

    public function getFormattedPrice(): ?PriceData
    {
        $currencyCode = current_currency();

        if (! $this->relationLoaded('prices')) {
            $this->load(['prices' => fn ($q) => $q->whereRelation('currency', 'code', $currencyCode)]);
        }

        $price = $this->prices->first();

        return $price
            ? new PriceData(
                amount: Price::from($price->amount, $currencyCode),
                compare: $price->compare_amount ? Price::from($price->compare_amount, $currencyCode) : null,
                percentage: $price->compare_amount > 0
                    ? round((($price->compare_amount - $price->amount) / $price->compare_amount) * 100)
                    : null
            )
            : null;
    }
}
