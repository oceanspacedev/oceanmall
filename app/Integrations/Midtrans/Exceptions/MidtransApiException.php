<?php

declare(strict_types=1);

namespace App\Integrations\Midtrans\Exceptions;

use RuntimeException;

/**
 * Midtrans refused a request or answered something unusable. `httpStatus`
 * is the HTTP status (0 = connection failure), `statusCode` the status_code
 * inside a Core API body (Midtrans also reports errors with HTTP 200).
 */
final class MidtransApiException extends RuntimeException
{
    /**
     * @param  list<string>  $errorMessages
     */
    public function __construct(
        string $message,
        public readonly int $httpStatus = 0,
        public readonly ?string $statusCode = null,
        public readonly array $errorMessages = [],
    ) {
        parent::__construct($message);
    }

    /**
     * Snap refuses a reused transaction_details.order_id ("has already been
     * taken" / "sudah digunakan").
     */
    public function orderIdTaken(): bool
    {
        $text = strtolower(implode(' ', [$this->getMessage(), ...$this->errorMessages]));

        return str_contains($text, 'already been taken')
            || str_contains($text, 'already taken')
            || str_contains($text, 'sudah digunakan');
    }
}
