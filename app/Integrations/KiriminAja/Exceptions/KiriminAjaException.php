<?php

declare(strict_types=1);

namespace App\Integrations\KiriminAja\Exceptions;

use RuntimeException;

/**
 * A KiriminAja API call failed: transport error, HTTP error, or an answer
 * with `status: false`. The message is staff-facing (stored as the shipment's
 * fulfillment_error, shown in cpanel).
 */
final class KiriminAjaException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $response
     */
    public function __construct(string $message, public readonly array $response = [], public readonly int $httpStatus = 0)
    {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self('KiriminAja is not configured: set KIRIMINAJA_API_KEY (and KIRIMINAJA_BASE_URL in production).');
    }

    public function isRateLimited(): bool
    {
        return $this->httpStatus === 429;
    }
}
