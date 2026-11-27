<?php

declare(strict_types=1);

namespace App\Integrations\Komerce\Services;

use App\Integrations\Komerce\Services\Concerns\UsesKomerceHttp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class PaymentClient
{
    use UsesKomerceHttp;

    private const METHODS_ENDPOINT = '/api/v1/user/methods';

    private const CREATE_ENDPOINT = '/api/v1/user/payment/create';

    private const STATUS_ENDPOINT = '/api/v1/user/payment/status';

    private const CANCEL_ENDPOINT = '/api/v1/user/payment/cancel';

    private const METHODS_CACHE_KEY = 'komerce:payment-methods';

    private const METHODS_UNAVAILABLE_CACHE_KEY = 'komerce:payment-methods:unavailable';

    /**
     * Official payment-method catalog. Cached for one hour per provider guidance.
     * Failures are remembered briefly so checkout does not wait on a dead catalog.
     *
     * @return array<string, mixed>
     */
    public function listMethods(): array
    {
        $cached = Cache::get(self::METHODS_CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        if (Cache::has(self::METHODS_UNAVAILABLE_CACHE_KEY)) {
            throw new RuntimeException('Komerce payment methods catalog is temporarily unavailable.');
        }

        try {
            $response = $this->paymentHttp()
                ->connectTimeout(2)
                ->timeout(min(5, max(1, $this->timeout())))
                ->get(self::METHODS_ENDPOINT)
                ->throw()
                ->json();

            if (! is_array($response)) {
                throw new RuntimeException('Komerce payment methods catalog returned an invalid response.');
            }

            if ($this->isErrorEnvelope($response)) {
                throw new RuntimeException($this->envelopeMessage($response, 'Komerce payment methods catalog returned an error response.'));
            }

            if (! array_key_exists('data', $response) || ! is_array($response['data'])) {
                throw new RuntimeException('Komerce payment methods catalog returned an invalid data payload.');
            }
        } catch (Throwable $e) {
            Cache::put(self::METHODS_UNAVAILABLE_CACHE_KEY, true, now()->addSeconds(45));
            Log::warning('Komerce payment methods catalog unavailable.', [
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }

        Cache::put(self::METHODS_CACHE_KEY, $response, now()->addHour());

        return $response;
    }

    /**
     * Create a virtual-account payment.
     *
     * Expected payload keys follow Komerce Payment docs:
     * channel_code, order_id, amount, customer{name,email,phone}, and optional
     * items, expiry_duration, callback_url, callback_API_KEY.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createVirtualAccount(array $payload): array
    {
        return $this->createPayment([
            ...$payload,
            'payment_type' => 'bank_transfer',
        ]);
    }

    /**
     * Create a QRIS payment.
     *
     * Expected payload keys follow Komerce Payment docs:
     * order_id, amount, customer{name,email,phone}, and optional items,
     * expiry_duration, callback_url, callback_API_KEY.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createQris(array $payload): array
    {
        unset($payload['channel_code']);

        return $this->createPayment([
            ...$payload,
            'payment_type' => 'qris',
        ]);
    }

    /**
     * Fetch payment status for a Komerce payment ID/reference.
     *
     * @return array<string, mixed>
     */
    public function getStatus(string $reference, bool $allowUnentitled = false): array
    {
        $reference = trim($reference);

        if ($reference === '') {
            throw new InvalidArgumentException('Komerce payment reference is required.');
        }

        // Official contract permits at most one status request every three
        // seconds for each payment_id. Reuse the same response inside that
        // window instead of sending a second provider request.
        $response = Cache::remember(
            'komerce:payment-status:'.hash('sha256', $reference),
            now()->addSeconds(3),
            function () use ($reference, $allowUnentitled): array {
                $json = $this->paymentHttp($allowUnentitled)
                    ->get(self::STATUS_ENDPOINT.'/'.rawurlencode($reference))
                    ->throw()
                    ->json();

                if (! is_array($json)) {
                    throw new RuntimeException('Komerce payment status returned an invalid response.');
                }

                if ($this->isErrorEnvelope($json)) {
                    throw new RuntimeException($this->envelopeMessage($json, 'Komerce payment status returned an error response.'));
                }

                if (! array_key_exists('data', $json) || ! is_array($json['data'])) {
                    throw new RuntimeException('Komerce payment status returned an invalid data payload.');
                }

                return $json;
            },
        );

        return $response;
    }

    /**
     * Cancel a pending Komerce payment.
     *
     * @return array<string, mixed>
     */
    public function cancel(string $paymentId, string $reason = 'Order payment expired'): array
    {
        $response = $this->paymentHttp()
            ->post(self::CANCEL_ENDPOINT, [
                'payment_id' => $paymentId,
                'reason' => $reason,
            ])
            ->throw()
            ->json();

        if (! is_array($response)) {
            throw new RuntimeException('Komerce payment cancel returned an invalid response.');
        }

        if ($this->isErrorEnvelope($response)) {
            throw new RuntimeException($this->envelopeMessage($response, 'Komerce payment cancel returned an error response.'));
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function createPayment(array $payload): array
    {
        $response = $this->paymentHttp()
            ->post(self::CREATE_ENDPOINT, $payload)
            ->throw()
            ->json();

        return is_array($response) ? $response : [];
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function isErrorEnvelope(array $response): bool
    {
        if (($response['success'] ?? null) === false) {
            return true;
        }

        $status = strtolower(trim((string) (data_get($response, 'meta.status') ?? '')));
        if ($status !== '' && ! in_array($status, ['success', 'succeeded', 'ok'], true)) {
            return true;
        }

        $code = data_get($response, 'meta.code');

        return is_numeric($code) && ((int) $code < 200 || (int) $code >= 300);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function envelopeMessage(array $response, string $fallback): string
    {
        foreach (['meta.message', 'message'] as $path) {
            $value = data_get($response, $path);

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return $fallback;
    }
}
