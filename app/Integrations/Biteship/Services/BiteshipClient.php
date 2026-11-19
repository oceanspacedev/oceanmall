<?php

declare(strict_types=1);

namespace App\Integrations\Biteship\Services;

use App\Integrations\Biteship\Biteship;
use App\Integrations\Biteship\Exceptions\BiteshipApiException;
use App\Integrations\Biteship\Exceptions\BiteshipNotConfiguredException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin HTTP client for the Biteship API (https://biteship.com/id/docs/api).
 * Authentication is the API key in the `Authorization` header (Biteship
 * accepts it raw or as a Bearer token; the raw key is sent, as in its docs'
 * examples). Every answer is a
 * JSON object with `success`; anything else raises BiteshipApiException with
 * Biteship's numeric `code` and `details`.
 *
 * Only the endpoints the module uses:
 *
 *   GET  /v1/maps/areas                              area search (destination / origin ids)
 *   POST /v1/rates/couriers                          courier rates
 *   GET  /v1/couriers                                courier catalogue (carrier sync)
 *   POST /v1/orders                                  create order (pickup + waybill)
 *   GET  /v1/orders/{id}                             order detail (reconcile, webhook re-fetch)
 *   POST /v1/orders/{id}/cancel                      cancel order
 *   GET  /v1/trackings/{id}                          tracking of an API order
 *   GET  /v1/trackings/{waybill}/couriers/{courier}  public tracking of any waybill
 */
final class BiteshipClient
{
    /**
     * @return array<string, mixed>
     */
    public function searchAreas(string $input): array
    {
        return $this->get('/v1/maps/areas', [
            'countries' => 'ID',
            'input' => $input,
            'type' => 'single',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function rates(array $payload): array
    {
        return $this->post('/v1/rates/couriers', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function couriers(): array
    {
        return $this->get('/v1/couriers');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createOrder(array $payload): array
    {
        return $this->post('/v1/orders', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function order(string $orderId): array
    {
        return $this->get('/v1/orders/'.rawurlencode($orderId));
    }

    /**
     * @return array<string, mixed>
     */
    public function cancelOrder(string $orderId, string $reasonCode = 'others', ?string $reason = null): array
    {
        return $this->post('/v1/orders/'.rawurlencode($orderId).'/cancel', array_filter([
            'cancellation_reason_code' => $reasonCode,
            'cancellation_reason' => $reason,
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));
    }

    /**
     * @return array<string, mixed>
     */
    public function tracking(string $trackingId): array
    {
        return $this->get('/v1/trackings/'.rawurlencode($trackingId));
    }

    /**
     * @return array<string, mixed>
     */
    public function publicTracking(string $waybill, string $courierCode): array
    {
        return $this->get('/v1/trackings/'.rawurlencode($waybill).'/couriers/'.rawurlencode(strtolower($courierCode)));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        return $this->send(fn (PendingRequest $request): Response => $request->get($path, $query), 'GET '.$path);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        return $this->send(fn (PendingRequest $request): Response => $request->post($path, $payload), 'POST '.$path);
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @return array<string, mixed>
     */
    private function send(callable $call, string $operation): array
    {
        if (! Biteship::configured()) {
            throw BiteshipNotConfiguredException::make(Biteship::refusal());
        }

        try {
            $response = $call($this->request());
        } catch (ConnectionException $e) {
            throw new BiteshipApiException("Biteship {$operation} failed: {$e->getMessage()}");
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];

        if ($response->successful() && ($body['success'] ?? true) !== false && $body !== []) {
            return $body;
        }

        $code = $body['code'] ?? null;
        $error = $body['error'] ?? $body['message'] ?? null;
        $details = $body['details'] ?? [];

        throw new BiteshipApiException(
            sprintf(
                'Biteship %s failed (HTTP %d%s): %s',
                $operation,
                $response->status(),
                is_numeric($code) ? ', code '.(int) $code : '',
                is_scalar($error) && trim((string) $error) !== '' ? trim((string) $error) : 'no error message',
            ),
            $response->status(),
            is_numeric($code) ? (int) $code : null,
            is_array($details) ? $details : [],
            $body,
        );
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(Biteship::baseUrl())
            // The raw key, as in Biteship's own examples (`authorization: <key>`).
            ->withHeaders(['Authorization' => Biteship::apiKey()])
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('biteship.timeout', 30));
    }
}
