<?php

declare(strict_types=1);

namespace App\Integrations\Biteship\Exceptions;

use RuntimeException;

/**
 * The Biteship client was asked to call the API while the module is not
 * configured (no key, or a key refused for this environment).
 */
final class BiteshipNotConfiguredException extends RuntimeException
{
    public static function make(?string $reason = null): self
    {
        return new self('Biteship is not configured'.($reason !== null ? ': '.$reason : ' (BITESHIP_API_KEY is empty).'));
    }
}
