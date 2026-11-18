<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Shipping\AddressBookDestinations;
use App\Shipping\AddressDestination;
use App\Shipping\ShippingCapabilities;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class DestinationController extends Controller
{
    public function __invoke(Request $request, ShippingCapabilities $capabilities, AddressBookDestinations $addressBook): JsonResponse
    {
        // The app only searches when shipping_capabilities.destination_search
        // is true. Builds released before shipping_capabilities always make
        // the customer pick a row here (and take its zip_code as the postal
        // code), so without a destination driver they get free-text rows: the
        // regions the store's address book knows for the typed area or postal
        // code, plus the typed text when it holds a postal code. Their ids are
        // never stored (AddressDestination::FREE_TEXT_PREFIX).
        $driver = $capabilities->destinationDriver();

        if ($driver === null) {
            return response()->json(['data' => $addressBook->search(
                (string) $request->query('q', ''),
                max(1, min(50, (int) $request->query('limit', 10))),
            )]);
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
                'message' => 'Pencarian destinasi sedang tidak tersedia.',
                'data' => [],
            ], 502);
        }

        return response()->json(['data' => AddressDestination::searchRows($results)]);
    }
}
