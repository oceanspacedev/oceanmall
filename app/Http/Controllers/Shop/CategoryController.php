<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class CategoryController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('shop/categories', [
            'categories' => Category::query()
                ->scopes('enabled')
                ->whereNull('parent_id')
                ->with('media')
                ->withCount(['products' => fn ($q) => $q->scopes('publish')])
                ->orderBy('position')
                ->get(),
        ]);
    }

    public function show(Request $request, Category $category): Response
    {
        abort_unless((bool) $category->is_enabled, 404);

        $sort = (string) $request->string('sort', 'latest');
        $allowedSorts = ['latest', 'name', 'price_asc', 'price_desc'];
        if (! in_array($sort, $allowedSorts, true)) {
            $sort = 'latest';
        }

        $query = Product::query()
            ->scopes('publish')
            ->whereHas('categories', fn ($q) => $q->where('id', $category->id))
            ->with(['media', 'brand'])
            ->withCurrentPrices();

        $query = Product::applyCatalogSort($query, $sort);

        return Inertia::render('shop/category', [
            'category' => $category->load('media'),
            'products' => $query->paginate(12)->withQueryString(),
            'filters' => ['sort' => $sort],
        ]);
    }
}
