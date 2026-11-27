<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Shipping\AddressDestination;
use App\Shipping\ShippingCapabilities;
use App\Support\ProviderQuotaRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
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

            if ($this->isQuotaError($e)) {
                $retryAtWib = $this->quotaRetryAtWib();

                return response()->json([
                    'message' => $retryAtWib !== null
                        ? "Batas kuota pencarian destinasi harian sudah tercapai. Silakan coba lagi setelah {$retryAtWib} WIB."
                        : 'Batas kuota pencarian destinasi harian sudah tercapai. Silakan coba lagi nanti.',
                    'data' => [],
                    'retry_at_wib' => $retryAtWib,
                ], 429);
            }

            return response()->json([
                'message' => __('Unable to search destinations right now.'),
                'data' => [],
            ], 502);
        }

        return response()->json(['data' => AddressDestination::searchRows($results)]);
    }

    private function isQuotaError(Throwable $e): bool
    {
        return ($e instanceof RequestException && $e->response->status() === 429)
            || preg_match('/(429|quota|kuota|limit|too many|exhausted)/i', $e->getMessage()) === 1;
    }

    private function quotaRetryAtWib(): ?string
    {
        foreach (resolve(ProviderQuotaRegistry::class)->all() as $quota) {
            if (($quota['exhausted'] ?? false) !== true) {
                continue;
            }

            $rawUntil = $quota['exhausted_until'] ?? null;

            if (is_string($rawUntil) && $rawUntil !== '') {
                try {
                    return CarbonImmutable::parse($rawUntil)->setTimezone('Asia/Jakarta')->format('d M Y H:i');
                } catch (Throwable) {
                    // Try any remaining quota entries or fall back to null.
                }
            }
        }

        return null;
    }
}
