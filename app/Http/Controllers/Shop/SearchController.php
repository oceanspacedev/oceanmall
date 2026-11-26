<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class SearchController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $query = trim((string) $request->string('q', ''));
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($query));

        $products = mb_strlen($query) < 2
            ? null
            : Product::query()
                ->scopes('publish')
                ->whereRaw("lower(name) like ? escape '!'", ["%{$escaped}%"])
                ->with(['media', 'brand'])
                ->withCurrentPrices()
                ->paginate(12)
                ->withQueryString();

        return Inertia::render('shop/search', [
            'query' => $query,
            'products' => $products,
        ]);
    }
}
