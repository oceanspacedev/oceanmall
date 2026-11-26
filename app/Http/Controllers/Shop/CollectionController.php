<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\Product;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class CollectionController extends Controller
{
    public function show(Request $request, Collection $collection): Response
    {
        abort_unless(
            $collection->published_at !== null
                && $collection->published_at->lte(now()),
            404,
        );

        $sort = (string) $request->string('sort', 'latest');
        $allowedSorts = ['latest', 'name', 'price_asc', 'price_desc'];
        if (! in_array($sort, $allowedSorts, true)) {
            $sort = 'latest';
        }

        $query = $collection->products()
            ->scopes('publish')
            ->with(['media', 'brand'])
            ->withCurrentPrices();

        $query = Product::applyCatalogSort($query, $sort);

        return Inertia::render('shop/collection', [
            'collection' => $collection->load('media'),
            'products' => $query->paginate(12)->withQueryString(),
            'filters' => ['sort' => $sort],
        ]);
    }
}
