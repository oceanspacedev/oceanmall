<?php

declare(strict_types=1);

namespace App\Integrations\Midtrans\Exceptions;

use App\Integrations\Midtrans\Midtrans;
use RuntimeException;

/**
 * Thrown before any HTTP call when the server key is missing or does not fit
 * the chosen host (Midtrans::configurationError()).
 */
final class MidtransNotConfiguredException extends RuntimeException
{
    public static function make(): self
    {
        return new self('Midtrans is not configured: '.(Midtrans::configurationError() ?? 'unknown reason.'));
    }
}
