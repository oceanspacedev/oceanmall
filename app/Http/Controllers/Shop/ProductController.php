<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shop;

use App\Actions\Product\BuildVariantOptions;
use App\Actions\Product\CustomerPurchasedProduct;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Product::query()
            ->scopes('publish')
            ->with(['media', 'brand'])
            ->withCurrentPrices();

        $search = (string) $request->string('search', '');

        if ($search !== '') {
            // Explicit ESCAPE (SQLite has no default one) and lower() on both
            // sides (LIKE is case-sensitive on PostgreSQL).
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search));
            $query->whereRaw("lower(name) like ? escape '!'", ["%{$escaped}%"]);
        }

        if ($categoryId = $request->integer('category')) {
            $query->whereHas('categories', fn ($q) => $q->where('id', $categoryId));
        }

        if ($brandId = $request->integer('brand')) {
            $query->where('brand_id', $brandId);
        }

        $featured = $request->boolean('featured');
        if ($featured) {
            $query->where('featured', true);
        }

        $promo = $request->boolean('promo');
        if ($promo) {
            $currency = current_currency();
            $discounted = static fn ($q) => $q
                ->whereRelation('currency', 'code', $currency)
                ->whereNotNull('compare_amount')
                ->whereColumn('compare_amount', '>', 'amount');

            $query->where(static fn ($q) => $q
                ->whereHas('prices', $discounted)
                ->orWhereHas('variants', static fn ($variants) => $variants->whereHas('prices', $discounted)));
        }

        $sort = (string) $request->string('sort', 'latest');
        $allowedSorts = ['latest', 'name', 'price_asc', 'price_desc'];
        if (! in_array($sort, $allowedSorts, true)) {
            $sort = 'latest';
        }

        $query = Product::applyCatalogSort($query, $sort);

        return Inertia::render('shop/index', [
            'products' => $query->paginate(20)->withQueryString(),
            'categories' => Category::query()
                ->scopes('enabled')
                ->whereNull('parent_id')
                ->orderBy('position')
                ->get(['id', 'name', 'slug']),
            'brands' => Brand::query()
                ->scopes('enabled')
                ->whereHas('products', fn ($q) => $q->scopes('publish'))
                ->orderBy('position')
                ->get(['id', 'name', 'slug']),
            'filters' => [
                'search' => $search,
                'category' => $categoryId ?: null,
                'brand' => $brandId ?: null,
                'featured' => $featured,
                'promo' => $promo,
                'sort' => $sort,
            ],
        ]);
    }

    public function show(Product $product): Response
    {
        abort_unless($product->isPublished(), 404);

        $currencyCode = current_currency();
        $priceConstraint = fn ($q) => $q->whereRelation('currency', 'code', $currencyCode);

        $product->load([
            'brand',
            'media',
            'prices' => $priceConstraint,
            // Published only: a draft related in cpanel is not public yet.
            'relatedProducts' => fn ($q) => $q->scopes('publish')->with(['brand', 'media'])->withCurrentPrices(),
            'variants.media',
            'variants.values.attribute',
            'variants.prices' => $priceConstraint,
        ]);

        $variantOptions = null;

        if ($product->variants->isNotEmpty()) {
            ProductVariant::loadCurrentStock($product->variants); // @phpstan-ignore argument.type
            $product->variants->each(fn (ProductVariant $variant) => $variant->append('stock'));
            $variantOptions = $product->canUseVariants()
                ? resolve(BuildVariantOptions::class)->handle($product)
                : null;
        } else {
            $product->setAttribute('real_stock', $product->getStock());
            $product->append('stock');
        }

        if (filled($product->description)) {
            $product->setAttribute(
                'description',
                str($product->description)->sanitizeHtml()->toString(),
            );
        }

        $approvedQuery = $product->ratings()->where('approved', true);
        $totalCount = (clone $approvedQuery)->count();
        $reviews = (clone $approvedQuery)
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
                'author_name' => self::reviewerName(
                    $review->author?->first_name ?? null,
                    $review->author?->last_name ?? null,
                ),
            ]);

        $breakdown = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
        if ($totalCount > 0) {
            $counts = (clone $approvedQuery)
                ->selectRaw('rating, COUNT(*) as aggregate')
                ->groupBy('rating')
                ->pluck('aggregate', 'rating');

            foreach ($counts as $rating => $count) {
                $key = (int) $rating;
                if (isset($breakdown[$key])) {
                    $breakdown[$key] = (int) $count;
                }
            }
        }

        $user = request()->user();
        $purchased = resolve(CustomerPurchasedProduct::class);
        $canReview = $user !== null
            && $purchased->handle($user, $product)
            && ! $purchased->alreadyReviewed($user, $product);

        return Inertia::render('shop/product', [
            'product' => $product,
            'variantOptions' => $variantOptions,
            'reviews' => [
                'items' => $reviews,
                'averageRating' => $totalCount > 0
                    ? round((float) (clone $approvedQuery)->avg('rating'), 1)
                    : 0.0,
                'totalCount' => $totalCount,
                'breakdown' => $breakdown,
            ],
            'canReview' => $canReview,
        ]);
    }

    private static function reviewerName(?string $firstName, ?string $lastName): string
    {
        $first = trim((string) $firstName);
        $initial = mb_strtoupper(mb_substr(trim((string) $lastName), 0, 1));
        $name = trim($first.($initial !== '' ? ' '.$initial.'.' : ''));

        return $name !== '' ? $name : 'Pembeli';
    }
}
