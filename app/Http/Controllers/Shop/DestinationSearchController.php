<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Shipping\AddressDestination;
use App\Shipping\ShippingCapabilities;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class DestinationSearchController extends Controller
{
    public function __invoke(Request $request, ShippingCapabilities $capabilities): JsonResponse
    {
        // Whichever configured Shopper shipping driver ResolvesDestinations
        // answers (it caches its own results); none configured = nothing to search.
        $driver = $capabilities->destinationDriver();

        if ($driver === null) {
            return response()->json(['data' => []]);
        }

        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        try {
            $results = $driver->searchDestinations((string) $validated['q'], (int) ($validated['limit'] ?? 10));
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => __('Unable to search destinations right now.'),
                'data' => [],
            ], 502);
        }

        return response()->json(['data' => AddressDestination::searchRows($results)]);
    }
}
