<?php

declare(strict_types=1);

namespace App\Integrations\KiriminAja\Services;

use App\Integrations\KiriminAja\Exceptions\KiriminAjaException;
use App\Integrations\KiriminAja\KiriminAja;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Thin HTTP client for the KiriminAja Mitra API (Bearer API key, JSON).
 *
 * Every answer is an envelope `{status: bool, text, method, ...}`. A
 * transport failure, an HTTP error without an envelope, or `status: false`
 * becomes a KiriminAjaException carrying the provider's `text`. Only TCP
 * connect failures are retried; HTTP answers are provider decisions.
 *
 * Endpoints (developer.kiriminaja.com, github.com/kiriminaja/docs and the
 * official PHP SDK github.com/kiriminaja/php):
 *
 *   GET  api/mitra/v6.1/addresses?search=      subdistrict search (postal code / names)
 *   POST api/mitra/v6.1/shipping_price         express tariff
 *   POST api/mitra/couriers                     active couriers
 *   POST api/mitra/v2/schedules                 pickup slots
 *   POST api/mitra/v6.1/request_pickup          create express order (config kiriminaja.pickup.endpoint)
 *   POST api/mitra/tracking                     tracking by order id or AWB
 *   POST api/mitra/v3/cancel_shipment           cancel by AWB
 *   POST api/mitra/set_callback                 callback URL (one per account)
 *   POST api/mitra/v3/set_whitelist_services    courier preference
 */
final class KiriminAjaClient
{
    /**
     * @return array<string, mixed>
     */
    public function searchAddresses(string $search): array
    {
        return $this->send('GET', 'api/mitra/v6.1/addresses', query: ['search' => $search]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function shippingPrice(array $payload): array
    {
        return $this->send('POST', 'api/mitra/v6.1/shipping_price', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function couriers(): array
    {
        return $this->send('POST', 'api/mitra/couriers');
    }

    /**
     * @return array<string, mixed>
     */
    public function schedules(): array
    {
        return $this->send('POST', 'api/mitra/v2/schedules');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function requestPickup(array $payload): array
    {
        $path = trim((string) config('kiriminaja.pickup.endpoint', ''), '/');

        return $this->send('POST', $path !== '' ? $path : 'api/mitra/v6.1/request_pickup', $payload, retry: false);
    }

    /**
     * Tracking by partner order id or AWB. A "not found" answer
     * (`status: false` with HTTP 2xx / 404) is returned as-is instead of
     * throwing, so callers can tell an unknown parcel from an outage. A
     * rejected key, a validation error or a server error still throws.
     *
     * @return array<string, mixed>
     */
    public function tracking(string $orderIdOrAwb): array
    {
        return $this->send('POST', 'api/mitra/tracking', ['order_id' => $orderIdOrAwb], allowFalse: true);
    }

    /**
     * The official SDK sends these as query parameters, the docs as a JSON
     * body: both are sent.
     *
     * @return array<string, mixed>
     */
    public function cancelShipment(string $awb, string $reason): array
    {
        $payload = ['awb' => $awb, 'reason' => $reason];

        return $this->send('POST', 'api/mitra/v3/cancel_shipment', $payload, $payload, retry: false);
    }

    /**
     * @return array<string, mixed>
     */
    public function setCallback(string $url): array
    {
        return $this->send('POST', 'api/mitra/set_callback', ['url' => $url]);
    }

    /**
     * @param  list<string>  $services
     * @return array<string, mixed>
     */
    public function setWhitelistServices(array $services): array
    {
        return $this->send('POST', 'api/mitra/v3/set_whitelist_services', ['services' => $services]);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @param  array<string, mixed>  $query
     * @param  bool  $retry  Retry TCP failures. Off for calls that create or
     *                       change something at KiriminAja: a timed-out
     *                       request may already have been applied.
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, ?array $payload = null, array $query = [], bool $allowFalse = false, bool $retry = true): array
    {
        $request = $this->http($retry);

        if ($query !== []) {
            $request = $request->withQueryParameters($query);
        }

        try {
            /** @var Response $response */
            $response = $method === 'GET'
                ? $request->get($path)
                : $request->post($path, $payload ?? []);
        } catch (ConnectionException $e) {
            throw new KiriminAjaException('KiriminAja is unreachable: '.$e->getMessage());
        } catch (Throwable $e) {
            throw new KiriminAjaException('KiriminAja request failed: '.$e->getMessage());
        }

        $json = $response->json();
        $body = is_array($json) ? $json : [];
        $text = self::text($body);

        if ($response->status() === 429) {
            throw new KiriminAjaException('KiriminAja rate limit reached (429): '.($text ?? 'too many requests'), $body, 429);
        }

        if (! array_key_exists('status', $body)) {
            throw new KiriminAjaException(sprintf(
                'KiriminAja %s answered HTTP %d without a status envelope%s',
                $path,
                $response->status(),
                $text !== null ? ': '.$text : '.',
            ), $body, $response->status());
        }

        // `status: false` is an answer about the parcel only on a 2xx / 404: a
        // rejected key (401 / 403) or a validation error must never read as
        // "unknown parcel" (a callback would then be acknowledged and lost).
        $answered = $response->successful() || $response->status() === 404;

        if ($body['status'] !== true && (! $allowFalse || ! $answered)) {
            throw new KiriminAjaException(sprintf(
                'KiriminAja %s failed: %s',
                $path,
                $text ?? 'unknown provider error',
            ), $body, $response->status());
        }

        if ($body['status'] === true && ! $response->successful()) {
            throw new KiriminAjaException(sprintf('KiriminAja %s answered HTTP %d: %s', $path, $response->status(), $text ?? 'error'), $body, $response->status());
        }

        return $body;
    }

    private function http(bool $retry): PendingRequest
    {
        if (! KiriminAja::configured()) {
            throw KiriminAjaException::notConfigured();
        }

        $timeout = max(1, (int) config('kiriminaja.timeout', 30));

        $request = Http::baseUrl(KiriminAja::baseUrl())
            ->withToken(KiriminAja::apiKey())
            ->acceptJson()
            ->asJson()
            ->connectTimeout(min(5, $timeout))
            ->timeout($timeout);

        return $retry
            ? $request->retry(2, 300, static fn (Throwable $e): bool => $e instanceof ConnectionException, throw: false)
            : $request;
    }

    /**
     * Provider message (`text`, or validation `errors` / `message`).
     *
     * @param  array<string, mixed>  $body
     */
    public static function text(array $body): ?string
    {
        foreach (['text', 'message'] as $key) {
            if (is_scalar($body[$key] ?? null) && trim((string) $body[$key]) !== '') {
                return trim((string) $body[$key]);
            }
        }

        if (is_array($body['errors'] ?? null)) {
            $messages = [];

            array_walk_recursive($body['errors'], static function (mixed $value) use (&$messages): void {
                if (is_scalar($value)) {
                    $messages[] = (string) $value;
                }
            });

            if ($messages !== []) {
                return implode(' ', $messages);
            }
        }

        return null;
    }
}
