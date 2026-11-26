<?php

declare(strict_types=1);

namespace App\Actions\Cpanel;

use App\Actions\Orders\MarkOrderPaid;
use App\Models\OrderNote;
use App\Models\OrderPaymentProof;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Order;
use Shopper\Payment\Models\PaymentTransaction;

final readonly class VerifyPaymentProof
{
    public function __construct(private MarkOrderPaid $markOrderPaid) {}

    public function approve(
        OrderPaymentProof $proof,
        User $actor,
        ?int $verifiedAmount = null,
        ?string $bankReference = null,
        ?string $notes = null,
    ): OrderPaymentProof {
        $order = Order::query()->findOrFail($proof->order_id);

        if ($order->status === OrderStatus::Cancelled || $order->payment_status === PaymentStatus::Voided) {
            throw new RuntimeException('Pesanan sudah dibatalkan sehingga bukti bayar tidak dapat disetujui.');
        }

        $confirmedAmount = $verifiedAmount ?? $proof->transfer_amount ?? (int) $order->price_amount;
        $ref = trim((string) $bankReference) !== ''
            ? trim((string) $bankReference)
            : 'proof-'.$proof->id.'-'.$order->number;

        $outcome = $this->markOrderPaid->handle(
            order: $order,
            driver: 'manual',
            reference: $ref,
            confirmedAmount: $confirmedAmount,
            transactionMetadata: array_filter([
                'verified_by' => $actor->id,
                'proof_id' => $proof->id,
                'notes' => $notes,
            ], static fn (mixed $v): bool => $v !== null && $v !== ''),
        );

        if ($outcome === MarkOrderPaid::AMOUNT_MISMATCH) {
            throw new RuntimeException('Nominal transfer yang diverifikasi tidak cocok dengan total tagihan pesanan.');
        }

        $tx = PaymentTransaction::query()
            ->where('order_id', $order->id)
            ->where('driver', 'manual')
            ->where('reference', $ref)
            ->latest('id')
            ->first();

        return DB::transaction(static function () use ($proof, $actor, $notes, $ref, $tx, $confirmedAmount, $order): OrderPaymentProof {
            $proof->forceFill([
                'status' => OrderPaymentProof::STATUS_APPROVED,
                'verified_by' => $actor->id,
                'verified_at' => now(),
                'verification_notes' => $notes !== null ? trim($notes) : null,
                'payment_transaction_id' => $tx?->id,
            ])->save();

            OrderNote::query()->create([
                'order_id' => $order->id,
                'user_id' => $actor->id,
                'type' => OrderNote::TYPE_AUDIT,
                'body' => sprintf(
                    'Bukti transfer #%d disetujui oleh %s (%s, Ref: %s).',
                    $proof->id,
                    $actor->full_name ?: $actor->email,
                    shopper_money_format($confirmedAmount, (string) $order->currency_code),
                    $ref,
                ),
                'metadata' => ['proof_id' => $proof->id, 'reference' => $ref],
            ]);

            return $proof->refresh();
        });
    }

    public function reject(OrderPaymentProof $proof, User $actor, string $reason): OrderPaymentProof
    {
        $cleanReason = trim($reason) !== '' ? trim($reason) : 'Bukti transfer tidak valid / nominal tidak sesuai.';

        $proof->forceFill([
            'status' => OrderPaymentProof::STATUS_REJECTED,
            'verified_by' => $actor->id,
            'verified_at' => now(),
            'verification_notes' => $cleanReason,
        ])->save();

        OrderNote::query()->create([
            'order_id' => $proof->order_id,
            'user_id' => $actor->id,
            'type' => OrderNote::TYPE_AUDIT,
            'body' => sprintf('Bukti transfer #%d ditolak: %s', $proof->id, $cleanReason),
            'metadata' => ['proof_id' => $proof->id, 'reason' => $cleanReason],
        ]);

        return $proof->refresh();
    }
}
