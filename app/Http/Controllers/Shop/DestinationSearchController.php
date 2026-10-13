<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Services\Komerce\ShippingCostClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class DestinationSearchController extends Controller
{
    public function __invoke(Request $request, ShippingCostClient $client): JsonResponse
    {
        if (! komerce_shipping_cost_enabled()) {
            return response()->json(['data' => []]);
        }

        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = mb_strtolower(trim((string) $validated['q']));
        $limit = (int) ($validated['limit'] ?? 10);

        try {
            // Destination data is static and the free Collaborator plan allows
            // only 100 Shipping Cost requests/day, so remember each search.
            $results = Cache::remember(
                'rajaongkir:destinations:v1:'.sha1("{$query}|{$limit}"),
                now()->addDays(30),
                fn (): array => $client->searchDomestic($query, $limit),
            );
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => __('Unable to search destinations right now.'),
                'data' => [],
            ], 502);
        }

        return response()->json(['data' => $results]);
    }
}
