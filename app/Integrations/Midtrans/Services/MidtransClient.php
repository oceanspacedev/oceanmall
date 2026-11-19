<?php

declare(strict_types=1);

namespace App\Integrations\Midtrans\Services;

use App\Integrations\Midtrans\Exceptions\MidtransApiException;
use App\Integrations\Midtrans\Exceptions\MidtransNotConfiguredException;
use App\Integrations\Midtrans\Midtrans;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Thin HTTP client for the two Midtrans APIs the module uses:
 *
 * - Snap   POST {snap host}/snap/v1/transactions       -> {token, redirect_url}
 * - Core   GET  {api host}/v2/{order_id}/status
 *          POST {api host}/v2/{order_id}/cancel | /expire
 *
 * Authentication is HTTP Basic with the server key as username and an empty
 * password. Hosts come from Midtrans::snapBaseUrl() / apiBaseUrl(); nothing
 * is sent while Midtrans::configured() is false.
 *
 * Core API answers business errors with HTTP 200 and a status_code in the
 * body (e.g. "404" Transaction doesn't exist): those bodies are returned as
 * they are and interpreted by the caller. Transport failures and non-JSON /
 * 5xx answers throw MidtransApiException, i.e. "could not verify".
 */
final class MidtransClient
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{token: string, redirect_url: string}
     */
    public function createSnapTransaction(array $payload): array
    {
        try {
            $response = $this->http(Midtrans::snapBaseUrl())->post('/snap/v1/transactions', $payload);
        } catch (ConnectionException $e) {
            throw new MidtransApiException('Midtrans Snap is unreachable: '.$e->getMessage());
        }

        $body = $this->json($response);
        $token = is_string($body['token'] ?? null) ? trim($body['token']) : '';
        $redirectUrl = is_string($body['redirect_url'] ?? null) ? trim($body['redirect_url']) : '';

        if (! $response->successful() || $token === '' || $redirectUrl === '') {
            $messages = $this->errorMessages($body);

            throw new MidtransApiException(
                'Midtrans Snap refused the transaction: '.($messages !== [] ? implode('; ', $messages) : 'HTTP '.$response->status()),
                $response->status(),
                null,
                $messages,
            );
        }

        return ['token' => $token, 'redirect_url' => $redirectUrl];
    }

    /**
     * @return array<string, mixed> Core API status body (status_code "404" = no transaction yet)
     */
    public function status(string $orderId): array
    {
        $body = $this->core('get', $orderId, 'status', retry: true);

        // A real status answer carries a transaction_status; the only
        // business answer without one is 404 (no transaction yet). Anything
        // else (401 wrong key, 5xx in the body...) is "could not verify",
        // never "not paid": callers must not settle, expire or retry on it.
        $statusCode = trim((string) $body['status_code']);
        $transactionStatus = is_string($body['transaction_status'] ?? null) ? trim($body['transaction_status']) : '';

        if ($transactionStatus === '' && $statusCode !== '404') {
            throw new MidtransApiException(
                "Midtrans status lookup answered status_code {$statusCode} without a transaction status.",
                0,
                $statusCode,
                $this->errorMessages($body),
            );
        }

        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    public function cancel(string $orderId): array
    {
        return $this->core('post', $orderId, 'cancel');
    }

    /**
     * @return array<string, mixed>
     */
    public function expire(string $orderId): array
    {
        return $this->core('post', $orderId, 'expire');
    }

    /**
     * @return array<string, mixed>
     */
    private function core(string $method, string $orderId, string $action, bool $retry = false): array
    {
        $orderId = trim($orderId);

        if ($orderId === '') {
            throw new MidtransApiException('Midtrans order_id is required.');
        }

        $request = $this->http(Midtrans::apiBaseUrl());

        if ($retry) {
            $request = $request->retry(2, 300, static fn (Throwable $e): bool => $e instanceof ConnectionException, throw: false);
        }

        $path = '/v2/'.rawurlencode($orderId).'/'.$action;

        try {
            $response = $method === 'get' ? $request->get($path) : $request->post($path);
        } catch (ConnectionException $e) {
            throw new MidtransApiException('Midtrans Core API is unreachable: '.$e->getMessage());
        }

        $body = $this->json($response);

        // A body with a status_code is a Core API answer (also 4xx such as
        // 404 "Transaction doesn't exist" or 412 "cannot be updated").
        if (is_string($body['status_code'] ?? null) || is_int($body['status_code'] ?? null)) {
            if ($response->serverError()) {
                throw new MidtransApiException('Midtrans Core API error: HTTP '.$response->status(), $response->status(), (string) $body['status_code']);
            }

            return $body;
        }

        throw new MidtransApiException(
            'Midtrans Core API answered without a status_code (HTTP '.$response->status().').',
            $response->status(),
            null,
            $this->errorMessages($body),
        );
    }

    private function http(string $baseUrl): PendingRequest
    {
        if (! Midtrans::configured()) {
            throw MidtransNotConfiguredException::make();
        }

        $timeout = max(1, (int) config('midtrans.timeout', 30));

        return Http::baseUrl($baseUrl)
            ->withBasicAuth(Midtrans::serverKey(), '')
            ->connectTimeout(min(10, $timeout))
            ->timeout($timeout)
            ->acceptJson()
            ->asJson();
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        try {
            $decoded = $response->json();
        } catch (Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return list<string>
     */
    private function errorMessages(array $body): array
    {
        $messages = $body['error_messages'] ?? null;

        if (is_array($messages)) {
            return array_values(array_map('strval', array_filter($messages, 'is_scalar')));
        }

        $single = $body['status_message'] ?? $body['message'] ?? null;

        return is_string($single) && $single !== '' ? [$single] : [];
    }
}
