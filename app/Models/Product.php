<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\InteractsWithStorefrontMedia;
use App\Traits\HasProductPricing;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Shopper\Core\Models\Currency;
use Shopper\Core\Models\Price;
use Shopper\Models\Product as Model;

final class Product extends Model
{
    use HasProductPricing;
    use InteractsWithStorefrontMedia;

    /** @param  Builder<self>  $query */
    #[Scope]
    protected function withCurrentPrices(Builder $query): Builder
    {
        return $query->with([
            'prices' => fn ($q) => $q->whereRelation('currency', 'code', current_currency())->orderBy('amount', 'asc'),
            'variants.prices' => fn ($q) => $q->whereRelation('currency', 'code', current_currency()),
        ]);
    }

    /**
     * Portable catalog sorting (SQLite + PostgreSQL) across shop, category, and collection views.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>|Relation<TModel, \Illuminate\Database\Eloquent\Model, mixed>  $query
     * @return Builder<TModel>|Relation<TModel, \Illuminate\Database\Eloquent\Model, mixed>
     */
    public static function applyCatalogSort(Builder|Relation $query, string $sort): Builder|Relation
    {
        $productsTable = shopper_table('products');

        if ($sort === 'name') {
            return $query->orderBy("{$productsTable}.name");
        }

        if ($sort === 'price_asc' || $sort === 'price_desc') {
            $currencyId = Currency::query()
                ->where('code', current_currency())
                ->value('id');

            $productPrice = Price::query()
                ->select('amount')
                ->whereColumn('priceable_id', "{$productsTable}.id")
                ->where('priceable_type', (new self)->getMorphClass())
                ->when($currencyId, fn ($q) => $q->where('currency_id', $currencyId))
                ->limit(1)
                ->toBase();

            $variantPrice = Price::query()
                ->selectRaw('min(amount)')
                ->where('priceable_type', (new ProductVariant)->getMorphClass())
                ->whereIn('priceable_id', ProductVariant::query()->select('id')->whereColumn('product_id', "{$productsTable}.id"))
                ->when($currencyId, fn ($q) => $q->where('currency_id', $currencyId))
                ->toBase();

            $priceExpr = "coalesce(({$productPrice->toSql()}), ({$variantPrice->toSql()}))";
            $priceBindings = [...$productPrice->getBindings(), ...$variantPrice->getBindings()];
            $direction = $sort === 'price_asc' ? 'asc' : 'desc';

            return $query
                ->orderByRaw("{$priceExpr} is null", $priceBindings)
                ->orderByRaw("{$priceExpr} {$direction}", $priceBindings)
                ->orderBy("{$productsTable}.name");
        }

        return $query->latest("{$productsTable}.created_at");
    }
}
