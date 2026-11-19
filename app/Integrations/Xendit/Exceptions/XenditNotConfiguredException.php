<?php

declare(strict_types=1);

namespace App\Integrations\Xendit\Exceptions;

use App\Integrations\Xendit\Xendit;
use RuntimeException;

/**
 * No request is sent to Xendit without a usable secret key (empty, malformed,
 * or a development key in production): see Xendit::refusal().
 */
final class XenditNotConfiguredException extends RuntimeException
{
    public static function make(): self
    {
        return new self('Xendit is not configured: '.(Xendit::refusal() ?? 'unknown reason.'));
    }
}
