<?php

declare(strict_types=1);

namespace App\Integrations\Xendit\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Xendit answered with an error (4xx/5xx), an unexpected body, or could not
 * be reached. Carries Xendit's `error_code` (e.g. INVOICE_NOT_FOUND_ERROR)
 * when there is one.
 */
final class XenditApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?string $errorCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
