<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Product\AddProductReviewAction;
use App\Actions\Product\BuildVariantOptions;
use App\Actions\Product\CustomerPurchasedProduct;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Collection;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\CustomerCatalogPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Shopper\Core\Models\Contracts\Stockable;
use Shopper\Core\Models\Currency;
use Shopper\Core\Models\Price;

final class CatalogController extends Controller
{
    public function __construct(
        private readonly CustomerCatalogPresenter $presenter,
    ) {}

    public function home(): JsonResponse
    {
        $currency = current_currency();

        $featured = Product::query()
            ->select('id', 'name', 'slug', 'brand_id')
            ->with(['media', 'brand'])
            ->withCurrentPrices()
            ->where('featured', true)
            ->scopes('publish')
            ->limit(10)
            ->get();

        $promo = Product::query()
            ->select('id', 'name', 'slug', 'brand_id')
            ->with(['media', 'brand'])
            ->withCurrentPrices()
            ->scopes('publish')
            ->where(fn (Builder $q) => $this->whereDiscounted($q, $currency))
            ->latest()
            ->limit(10)
            ->get();

        $featuredCollections = Collection::query()
            ->scopes('published')
            ->has('products')
            ->withCount('products')
            ->with('media')
            ->orderByRaw("CASE slug
                    WHEN 'sales-promotions' THEN 0
                    WHEN 'featured-products' THEN 1
                    WHEN 'new-arrivals' THEN 2
                    WHEN 'best-sellers' THEN 3
                    WHEN 'premium' THEN 4
                    ELSE 10
                END")
            ->orderByDesc('products_count')
            ->limit(6)
            ->get()
            ->map(fn (Collection $collection): array => [
                'id' => $collection->id,
                'name' => $collection->name,
                'slug' => $collection->slug,
                'thumbnail' => $collection->thumbnail ?? null,
                'products_count' => (int) $collection->products_count,
            ]);

        $categories = Category::query()
            ->scopes('enabled')
            ->whereNull('parent_id')
            ->with('media')
            ->orderBy('position')
            ->limit(8)
            ->get()
            ->map(fn (Category $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'thumbnail' => $category->thumbnail ?? null,
            ]);

        $brands = Brand::query()
            ->scopes('enabled')
            ->whereHas('products', fn ($q) => $q->scopes('publish'))
            ->with('media')
            ->withCount(['products' => fn ($q) => $q->scopes('publish')])
            ->orderBy('position')
            ->limit(12)
            ->get()
            ->map(fn (Brand $brand): array => [
                'id' => $brand->id,
                'name' => $brand->name,
                'slug' => $brand->slug,
                'thumbnail' => $brand->thumbnail ?? null,
                'products_count' => (int) $brand->products_count,
            ]);

        // Keep legacy keys for backward compat, add parity keys matching Web HomeController props
        return response()->json([
            'data' => [
                'featured_products' => $this->presenter->products($featured),
                'promo_products' => $this->presenter->products($promo),
                'featured_collections' => $featuredCollections->values()->all(),
                // legacy aliases
                'collections' => $featuredCollections->values()->all(),
                'categories' => $categories->values()->all(),
                'brands' => $brands->values()->all(),
                'currency' => $currency,
            ],
        ]);
    }

    public function products(Request $request): JsonResponse
    {
        $query = Product::query()
            ->scopes('publish')
            ->with(['media', 'brand'])
            ->withCurrentPrices();

        $search = trim((string) $request->string('search', ''));
        if ($search !== '') {
            // Explicit ESCAPE: SQLite has no default LIKE escape character.
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);
            $query->whereRaw("name like ? escape '!'", ["%{$escaped}%"]);
        }

        if ($categoryId = $request->integer('category')) {
            $query->whereHas('categories', fn ($q) => $q->where('id', $categoryId));
        }

        if ($brandId = $request->integer('brand')) {
            $query->where('brand_id', $brandId);
        }

        if ($request->boolean('featured')) {
            $query->where('featured', true);
        }

        if ($request->boolean('promo')) {
            $currency = current_currency();
            $query->where(fn (Builder $q) => $this->whereDiscounted($q, $currency));
        }

        $sort = (string) $request->string('sort', 'latest');

        $query = match ($sort) {
            'name' => $query->orderBy('name'),
            'price_asc' => $this->orderByPrice($query, 'asc')->orderBy('name'),
            'price_desc' => $this->orderByPrice($query, 'desc')->orderBy('name'),
            default => $query->latest(),
        };

        $page = $query->paginate(20);

        return response()->json([
            'data' => $this->presenter->products($page->getCollection()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function product(string $slug): JsonResponse
    {
        $product = Product::query()
            ->scopes('publish')
            ->with(['media', 'brand', 'variants.media'])
            ->withCurrentPrices()
            ->where('slug', $slug)
            ->firstOrFail();

        $payload = $this->presenter->product($product);
        $payload['description'] = $product->description;
        $payload['variants'] = $product->variants->map(static function ($variant): array {
            return [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'name' => $variant->name,
                'price' => $variant->prices->first()?->amount,
                'compare_price' => $variant->prices->first()?->compare_amount,
                'stock' => self::availableStock($variant),
            ];
        })->values()->all();
        // Null = stock is not tracked (unlimited); the app caps quantity with it.
        $payload['stock'] = $product->variants->isEmpty() ? self::availableStock($product) : null;

        try {
            $payload['options'] = resolve(BuildVariantOptions::class)->handle($product);
        } catch (\Throwable) {
            $payload['options'] = null;
        }

        $payload['reviews'] = $this->reviewsPayload($product);

        $related = $product->relatedProducts()
            ->scopes('publish')
            ->with(['media', 'brand'])
            ->withCurrentPrices()
            ->get();
        $payload['related_products'] = $this->presenter->products($related);

        return response()->json(['data' => $payload]);
    }

    public function search(Request $request): JsonResponse
    {
        $request->validate([
            // Short queries return an empty page below instead of a 422.
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $query = trim((string) $request->string('q', ''));
        if (mb_strlen($query) < 2) {
            return response()->json([
                'data' => [],
                'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 0],
            ]);
        }

        $request->merge(['search' => $query]);

        return $this->products($request);
    }

    public function category(Request $request, string $slug): JsonResponse
    {
        $category = Category::query()->scopes('enabled')->where('slug', $slug)->firstOrFail();
        $request->merge(['category' => $category->id]);

        $response = $this->products($request);
        $payload = $response->getData(true);
        $payload['category'] = [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
        ];

        return response()->json($payload);
    }

    public function collection(Request $request, string $slug): JsonResponse
    {
        $collection = Collection::query()
            ->scopes('published')
            ->where('slug', $slug)
            ->firstOrFail();

        $sort = (string) $request->string('sort', 'latest');
        $query = $collection->products()
            ->scopes('publish')
            ->with(['media', 'brand'])
            ->withCurrentPrices();
        $query = $sort === 'name' ? $query->orderBy('name') : $query->latest();
        $page = $query->paginate(20);

        return response()->json([
            'data' => [
                'collection' => [
                    'id' => $collection->id,
                    'name' => $collection->name,
                    'slug' => $collection->slug,
                    'thumbnail' => $collection->thumbnail ?? null,
                ],
                'products' => $this->presenter->products($page->getCollection()),
            ],
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function storeReview(Request $request, string $slug): JsonResponse
    {
        $product = Product::query()->scopes('publish')->where('slug', $slug)->firstOrFail();
        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'title' => ['nullable', 'string', 'max:120'],
            'content' => ['nullable', 'string', 'max:2000'],
            'is_recommended' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();
        $purchased = resolve(CustomerPurchasedProduct::class);
        if (! $purchased->handle($user, $product)) {
            return response()->json([
                'message' => 'Hanya pembeli yang sudah menerima pesanan yang dapat menulis ulasan.',
            ], 422);
        }
        if ($purchased->alreadyReviewed($user, $product)) {
            return response()->json(['message' => 'Kamu sudah menulis ulasan untuk produk ini.'], 422);
        }

        resolve(AddProductReviewAction::class)->execute($product, [
            'rating' => (int) $data['rating'],
            'title' => $data['title'] ?? null,
            'content' => $data['content'] ?? null,
            'is_recommended' => (bool) ($data['is_recommended'] ?? true),
        ], $user);

        return response()->json([
            'message' => 'Ulasan terkirim dan menunggu moderasi.',
        ], 201);
    }

    private static function availableStock(object $stockable): ?int
    {
        if (! $stockable instanceof Stockable || ! $stockable->tracksInventory()) {
            return null;
        }

        return max(0, $stockable->getStock());
    }

    /**
     * Discounted at product level, or (for variant products, which carry no
     * product-level price) on any variant — matching what the presenter shows.
     *
     * @param  Builder<Product>  $query
     */
    private function whereDiscounted(Builder $query, string $currency): void
    {
        $discounted = static fn ($q) => $q
            ->whereRelation('currency', 'code', $currency)
            ->whereNotNull('compare_amount')
            ->whereColumn('compare_amount', '>', 'amount');

        $query->whereHas('prices', $discounted)
            ->orWhereHas('variants', static fn ($variants) => $variants->whereHas('prices', $discounted));
    }

    /**
     * Sort by the price customers see: the product price, or the cheapest
     * variant when the product only has variant prices. Unpriced rows go last.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    private function orderByPrice(Builder $query, string $direction): Builder
    {
        $currencyId = Currency::query()->where('code', current_currency())->value('id');
        $products = shopper_table('products');

        $productPrice = Price::query()
            ->select('amount')
            ->whereColumn('priceable_id', "{$products}.id")
            ->where('priceable_type', (new Product)->getMorphClass())
            ->when($currencyId, fn ($q) => $q->where('currency_id', $currencyId))
            ->limit(1)
            ->toBase();

        $variantPrice = Price::query()
            ->selectRaw('min(amount)')
            ->where('priceable_type', (new ProductVariant)->getMorphClass())
            ->whereIn('priceable_id', ProductVariant::query()->select('id')->whereColumn('product_id', "{$products}.id"))
            ->when($currencyId, fn ($q) => $q->where('currency_id', $currencyId))
            ->toBase();

        $price = "coalesce(({$productPrice->toSql()}), ({$variantPrice->toSql()}))";
        $bindings = [...$productPrice->getBindings(), ...$variantPrice->getBindings()];

        return $query
            ->orderByRaw("{$price} is null", $bindings)
            ->orderByRaw("{$price} {$direction}", $bindings);
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewsPayload(Product $product): array
    {
        $approvedQuery = $product->ratings()->where('approved', true);
        $items = (clone $approvedQuery)
            ->with('author')
            ->orderByDesc('rating')
            ->latest()
            ->limit(20)
            ->get()
            ->map(static fn ($review): array => [
                'id' => $review->id,
                'rating' => (int) $review->rating,
                'title' => $review->title,
                'content' => $review->content,
                'is_recommended' => (bool) $review->is_recommended,
                'created_at' => $review->created_at?->toIso8601String(),
                'author_name' => trim(implode(' ', array_filter([
                    $review->author?->first_name ?? null,
                    $review->author?->last_name ?? null,
                ]))) ?: 'Pembeli',
            ])
            ->values()
            ->all();

        return [
            'average' => round((float) (clone $approvedQuery)->avg('rating'), 1),
            'count' => (clone $approvedQuery)->count(),
            'items' => $items,
        ];
    }

    public function categories(): JsonResponse
    {
        $categories = Category::query()
            ->scopes('enabled')
            ->whereNull('parent_id')
            ->with('media')
            ->orderBy('position')
            ->get()
            ->map(fn (Category $c): array => [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
                'thumbnail' => $c->thumbnail ?? null,
            ]);

        $brands = Brand::query()
            ->scopes('enabled')
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        return response()->json([
            'data' => [
                'categories' => $categories->values()->all(),
                'brands' => $brands,
            ],
        ]);
    }

    public function featured(): JsonResponse
    {
        $products = Product::query()
            ->select('id', 'name', 'slug', 'brand_id')
            ->with(['media', 'brand'])
            ->withCurrentPrices()
            ->where('featured', true)
            ->scopes('publish')
            ->limit(10)
            ->get();

        return response()->json([
            'data' => $this->presenter->products($products),
        ]);
    }

    public function promo(): JsonResponse
    {
        $currency = current_currency();

        $products = Product::query()
            ->select('id', 'name', 'slug', 'brand_id')
            ->with(['media', 'brand'])
            ->withCurrentPrices()
            ->scopes('publish')
            ->where(fn (Builder $q) => $this->whereDiscounted($q, $currency))
            ->latest()
            ->limit(10)
            ->get();

        return response()->json([
            'data' => $this->presenter->products($products),
        ]);
    }

    public function brands(): JsonResponse
    {
        $brands = Brand::query()
            ->scopes('enabled')
            ->whereHas('products', fn ($q) => $q->scopes('publish'))
            ->with('media')
            ->withCount(['products' => fn ($q) => $q->scopes('publish')])
            ->orderBy('position')
            ->limit(12)
            ->get()
            ->map(fn (Brand $b): array => [
                'id' => $b->id,
                'name' => $b->name,
                'slug' => $b->slug,
                'thumbnail' => $b->thumbnail ?? null,
                'products_count' => (int) $b->products_count,
            ]);

        return response()->json([
            'data' => $brands->values()->all(),
        ]);
    }

    public function collections(): JsonResponse
    {
        $collections = Collection::query()
            ->scopes('published')
            ->has('products')
            ->withCount('products')
            ->with('media')
            ->orderByRaw("CASE slug
                    WHEN 'sales-promotions' THEN 0
                    WHEN 'featured-products' THEN 1
                    WHEN 'new-arrivals' THEN 2
                    WHEN 'best-sellers' THEN 3
                    WHEN 'premium' THEN 4
                    ELSE 10
                END")
            ->orderByDesc('products_count')
            ->limit(6)
            ->get()
            ->map(fn (Collection $collection): array => [
                'id' => $collection->id,
                'name' => $collection->name,
                'slug' => $collection->slug,
                'thumbnail' => $collection->thumbnail ?? null,
                'products_count' => (int) $collection->products_count,
            ]);

        return response()->json([
            'data' => $collections->values()->all(),
        ]);
    }
}
