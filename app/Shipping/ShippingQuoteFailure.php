<?php

declare(strict_types=1);

namespace App\Shipping;

use Carbon\CarbonInterface;

/**
 * Why a shipping driver could not quote (as opposed to legitimately having
 * no service for the route). Read by checkout to choose a neutral hint.
 */
final readonly class ShippingQuoteFailure
{
    /** The provider's daily / plan quota is used up until $retryAfter. */
    public const QUOTA = 'quota';

    /** Transport or provider error (timeout, 5xx, error envelope). */
    public const UNAVAILABLE = 'unavailable';

    public function __construct(
        public string $driver,
        public string $reason,
        public string $message = '',
        public ?CarbonInterface $retryAfter = null,
    ) {}

    public function isQuota(): bool
    {
        return $this->reason === self::QUOTA;
    }
}
