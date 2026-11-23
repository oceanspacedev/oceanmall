<?php

declare(strict_types=1);

namespace App\Shipping;

use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * Was a failed provider HTTP request ever sent? Booking calls (store order,
 * pickup request) are not idempotent: they may be repeated only when the
 * request provably never left this server.
 *
 * Laravel reports every cURL transport error as a ConnectionException,
 * including a read timeout (cURL 28 "Operation timed out ... bytes received")
 * or an empty reply (cURL 52) AFTER the provider received the request. Only
 * a failure while resolving the host or opening the connection is "not sent".
 *
 * The cURL handler context decides when present: request_size (bytes of the
 * request written) and size_upload stay 0 until the request is sent, and
 * connect_time stays 0 until a connection is open. pretransfer_time is NOT a
 * signal: cURL fills it with the elapsed time on DNS / connect failures too.
 */
final class ProviderRequestFailure
{
    /** cURL: couldn't resolve proxy / host, couldn't connect. */
    private const CONNECT_PHASE_ERRNOS = [5, 6, 7];

    private const CURL_TIMEOUT = 28;

    public static function notSent(Throwable $exception): bool
    {
        if (! $exception instanceof ConnectionException) {
            return false;
        }

        $previous = $exception->getPrevious();
        $context = $previous instanceof ConnectException ? $previous->getHandlerContext() : [];

        // cURL wrote (part of) the request: the provider may have it.
        if (self::positive($context, 'request_size') || self::positive($context, 'size_upload')) {
            return false;
        }

        $message = $exception->getMessage();
        $errno = is_numeric($context['errno'] ?? null)
            ? (int) $context['errno']
            : (preg_match('/cURL error (\d+)/i', $message, $match) === 1 ? (int) $match[1] : null);

        if ($errno === null) {
            return false;
        }

        if (in_array($errno, self::CONNECT_PHASE_ERRNOS, true)) {
            return true;
        }

        if ($errno !== self::CURL_TIMEOUT) {
            return false;
        }

        // A timeout is "not sent" when cURL reports that no request byte was
        // written (connect or TLS handshake timeout); without request_size,
        // when no connection was opened; without any handler context, only
        // when the message says it happened while resolving or connecting,
        // never "Operation timed out" (reading).
        if (is_numeric($context['request_size'] ?? null)) {
            return true;
        }

        if (is_numeric($context['connect_time'] ?? null)) {
            return (float) $context['connect_time'] <= 0.0;
        }

        return preg_match('/(Connection|Resolving) timed out/i', $message) === 1;
    }

    /**
     * May the HTTP client repeat this failed request on its own? Reads (GET,
     * HEAD) on any transport error; writes only when they were never sent.
     */
    public static function retryable(Throwable $exception, ?string $method): bool
    {
        if (! $exception instanceof ConnectionException) {
            return false;
        }

        $method = strtoupper(trim((string) $method));

        if ($method === '') {
            $previous = $exception->getPrevious();
            $method = $previous instanceof ConnectException ? strtoupper($previous->getRequest()->getMethod()) : '';
        }

        return in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) || self::notSent($exception);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function positive(array $context, string $key): bool
    {
        return is_numeric($context[$key] ?? null) && (float) $context[$key] > 0;
    }
}
