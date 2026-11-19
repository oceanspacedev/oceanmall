<?php

declare(strict_types=1);

namespace App\Integrations\Midtrans\Actions;

use App\Integrations\Midtrans\Exceptions\MidtransApiException;
use App\Integrations\Midtrans\Services\MidtransClient;
use App\Integrations\Midtrans\Support\MidtransTransactionStatus;

/**
 * Make one Midtrans attempt unpayable before the order is cancelled or a new
 * attempt replaces it:
 *
 * - pending transaction (VA issued, QR shown...)  -> POST /v2/{id}/expire
 * - card under fraud review / authorized          -> POST /v2/{id}/cancel
 * - already paid / closed                         -> nothing, the status is returned
 * - no transaction yet (Snap page opened, no method picked): the Core API
 *   has nothing to close; the Snap token itself lapses at its expiry.
 *
 * Returns the attempt's status after the call. Throws MidtransApiException
 * when Midtrans cannot be reached or the attempt is still payable, so the
 * caller does not cancel / replace on a guess.
 */
final readonly class CloseMidtransAttempt
{
    public function __construct(private MidtransClient $client) {}

    public function handle(string $midtransOrderId): MidtransTransactionStatus
    {
        $status = MidtransTransactionStatus::fromResponse($this->client->status($midtransOrderId));

        if (! in_array($status->state, [MidtransTransactionStatus::PENDING, MidtransTransactionStatus::UNKNOWN], true)) {
            return $status;
        }

        $response = in_array($status->transactionStatus, ['capture', 'authorize'], true)
            ? $this->client->cancel($midtransOrderId)
            : $this->client->expire($midtransOrderId);

        // Success answers carry the new transaction_status (cancel: "200" +
        // cancel; expire: "407" + expire).
        if (is_string($response['transaction_status'] ?? null)) {
            $closed = MidtransTransactionStatus::fromResponse($response);

            if (! in_array($closed->state, [MidtransTransactionStatus::PENDING, MidtransTransactionStatus::UNKNOWN], true)) {
                return $closed;
            }
        }

        // Refused (e.g. 412: paid or closed meanwhile): read the truth again.
        $after = MidtransTransactionStatus::fromResponse($this->client->status($midtransOrderId));

        if (in_array($after->state, [MidtransTransactionStatus::PENDING, MidtransTransactionStatus::UNKNOWN], true)) {
            throw new MidtransApiException(
                "Midtrans attempt {$midtransOrderId} is still payable and could not be closed.",
                0,
                (string) ($response['status_code'] ?? ''),
            );
        }

        return $after;
    }
}
