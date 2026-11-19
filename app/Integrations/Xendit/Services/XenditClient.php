<?php

declare(strict_types=1);

namespace App\Integrations\Xendit\Services;

use App\Integrations\Xendit\Exceptions\XenditApiException;
use App\Integrations\Xendit\Exceptions\XenditNotConfiguredException;
use App\Integrations\Xendit\Xendit;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Xendit Invoice API (https://api.xendit.co), Basic auth with the secret key
 * as username and an empty password.
 *
 *  - POST /v2/invoices                     create
 *  - GET  /v2/invoices/{id}                read (status PENDING / PAID / SETTLED / EXPIRED)
 *  - POST /invoices/{id}/expire!           expire a PENDING invoice (no /v2 prefix)
 *
 * Nothing is sent without a usable key (Xendit::configured()), so a
 * development key never reaches Xendit from a production store.
 */
final class XenditClient
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createInvoice(array $payload): array
    {
        $request = $this->http();

        return $this->invoice($this->send(static fn (): Response => $request->post('/v2/invoices', $payload)));
    }

    /**
     * @return array<string, mixed>
     */
    public function getInvoice(string $invoiceId): array
    {
        $request = $this->http();

        return $this->invoice($this->send(static fn (): Response => $request->get('/v2/invoices/'.rawurlencode($invoiceId))));
    }

    /**
     * @return array<string, mixed>
     */
    public function expireInvoice(string $invoiceId): array
    {
        $request = $this->http();

        return $this->invoice($this->send(static fn (): Response => $request->post('/invoices/'.rawurlencode($invoiceId).'/expire!')));
    }

    private function http(): PendingRequest
    {
        if (! Xendit::configured()) {
            throw XenditNotConfiguredException::make();
        }

        return Http::baseUrl((string) config('xendit.base_url', 'https://api.xendit.co'))
            ->withBasicAuth(Xendit::secretKey(), '')
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('xendit.timeout', 15));
    }

    /**
     * @param  callable(): Response  $call
     */
    private function send(callable $call): Response
    {
        try {
            $response = $call();
        } catch (ConnectionException $e) {
            throw new XenditApiException('Xendit could not be reached: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            $errorCode = $response->json('error_code');
            $message = $response->json('message');

            throw new XenditApiException(
                sprintf(
                    'Xendit request failed (%d%s): %s',
                    $response->status(),
                    is_string($errorCode) ? ' '.$errorCode : '',
                    is_string($message) ? $message : 'no message',
                ),
                $response->status(),
                is_string($errorCode) ? $errorCode : null,
            );
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function invoice(Response $response): array
    {
        $body = $response->json();

        if (! is_array($body) || ! is_string($body['id'] ?? null) || trim($body['id']) === '') {
            throw new XenditApiException('Xendit answered without an invoice id.', $response->status());
        }

        return $body;
    }
}
