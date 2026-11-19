<?php

declare(strict_types=1);

namespace App\Integrations\Biteship\Exceptions;

use RuntimeException;

/**
 * A Biteship API call that did not succeed: transport error, HTTP error or a
 * `success: false` envelope. Carries Biteship's numeric error `code`
 * (e.g. 40002060 "reference id already used") and its `details` object.
 */
final class BiteshipApiException extends RuntimeException
{
    /** Order API: the reference_id was used by an earlier order (details.order_id). */
    public const REFERENCE_ID_USED = 40002060;

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        string $message,
        public readonly int $httpStatus = 0,
        public readonly ?int $biteshipCode = null,
        public readonly array $details = [],
        public readonly array $body = [],
    ) {
        parent::__construct($message);
    }

    /**
     * Plan quota / wallet answers (Maps 42200000, overage 40200000, HTTP 402 / 429).
     */
    public function isQuota(): bool
    {
        return in_array($this->httpStatus, [402, 429], true)
            || in_array($this->biteshipCode, [42200000, 40200000], true);
    }

    public function isAuthentication(): bool
    {
        return in_array($this->httpStatus, [401, 403], true);
    }
}
