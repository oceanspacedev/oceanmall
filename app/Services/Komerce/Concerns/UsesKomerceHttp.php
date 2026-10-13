<?php

declare(strict_types=1);

namespace App\Services\Komerce\Concerns;

use App\Exceptions\KomerceNotConfiguredException;
use App\Support\KomerceCapabilities;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

trait UsesKomerceHttp
{
    protected function paymentHttp(): PendingRequest
    {
        $this->ensureServiceEnabled(komerce_payment_enabled());

        return Http::baseUrl($this->baseUrl('komerce.payment_base_url'))
            ->withResponseMiddleware(resolve(KomerceCapabilities::class)->responseObserver('payment'))
            ->connectTimeout($this->connectTimeout())
            ->timeout($this->timeout())
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                'x-api-key' => $this->apiKey('komerce.payment_api_key'),
            ]);
    }

    protected function qrislyHttp(): PendingRequest
    {
        return $this->qrislyBaseHttp()
            ->asJson();
    }

    /**
     * QRISLY upload-qris is multipart/form-data. Do not send asJson().
     * Upload only needs the QRISLY product key (qris_id is the upload result).
     */
    protected function qrislyMultipartHttp(): PendingRequest
    {
        return $this->qrislyBaseHttp();
    }

    private function qrislyBaseHttp(): PendingRequest
    {
        $this->ensureServiceEnabled(komerce_service_enabled('komerce.qrisly_api_key'));

        return Http::baseUrl($this->baseUrl('komerce.qrisly_base_url'))
            ->withResponseMiddleware(resolve(KomerceCapabilities::class)->responseObserver('qrisly'))
            ->connectTimeout($this->connectTimeout())
            ->timeout($this->timeout())
            ->acceptJson()
            ->withHeaders([
                'X-API-Key' => $this->apiKey('komerce.qrisly_api_key'),
            ]);
    }

    protected function shippingCostHttp(): PendingRequest
    {
        $this->ensureServiceEnabled(komerce_shipping_cost_enabled());

        return Http::baseUrl($this->baseUrl('komerce.rajaongkir.cost_base_url'))
            ->withResponseMiddleware(resolve(KomerceCapabilities::class)->responseObserver('shipping_cost'))
            ->connectTimeout($this->connectTimeout())
            ->timeout($this->timeout())
            ->retry(2, 300, $this->retryOnConnectionFailure(), throw: true)
            ->acceptJson()
            ->asForm()
            ->withHeaders([
                'key' => $this->apiKey('komerce.shipping_cost_api_key'),
            ]);
    }

    protected function deliveryHttp(): PendingRequest
    {
        $this->ensureServiceEnabled(komerce_shipping_delivery_enabled());

        return Http::baseUrl($this->baseUrl('komerce.rajaongkir.delivery_base_url'))
            ->withResponseMiddleware(resolve(KomerceCapabilities::class)->responseObserver('shipping_delivery'))
            ->connectTimeout($this->connectTimeout())
            ->timeout($this->timeout())
            ->retry(2, 300, $this->retryOnConnectionFailure(), throw: true)
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                'x-api-key' => $this->apiKey('komerce.shipping_delivery_api_key'),
            ]);
    }

    private function ensureServiceEnabled(bool $enabled): void
    {
        if (! $enabled) {
            throw KomerceNotConfiguredException::make();
        }
    }

    private function apiKey(string $configKey): string
    {
        $apiKey = trim((string) config($configKey, ''));

        if ($apiKey === '') {
            throw KomerceNotConfiguredException::make();
        }

        return $apiKey;
    }

    private function baseUrl(string $key): string
    {
        return rtrim((string) config($key, ''), '/');
    }

    /**
     * RajaOngkir hosts intermittently refuse/stall TCP connects; retry only
     * those (never HTTP 4xx/5xx responses, which are provider decisions).
     */
    protected function retryOnConnectionFailure(): Closure
    {
        return static fn (Throwable $exception): bool => $exception instanceof ConnectionException;
    }

    protected function timeout(): int
    {
        return max(1, (int) config('komerce.timeout', 30));
    }

    protected function connectTimeout(): int
    {
        return min(5, $this->timeout());
    }
}
