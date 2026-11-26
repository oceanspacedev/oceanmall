<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Models\OrderNote;
use App\Models\OrderPaymentProof;
use App\Models\User;
use App\Support\OrderPaymentType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Order;

final class UploadPaymentProof
{
    public function canUpload(Order $order): bool
    {
        if (! OrderPaymentType::isManualBankTransfer($order)) {
            return false;
        }

        if ($order->payment_status !== PaymentStatus::Pending) {
            return false;
        }

        return ! in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Completed, OrderStatus::Archived], true);
    }

    public function handle(
        Order $order,
        User $customer,
        UploadedFile $file,
        ?string $senderName = null,
        ?string $senderBank = null,
        ?int $transferAmount = null,
        ?string $notes = null,
    ): OrderPaymentProof {
        if ((int) $order->customer_id !== (int) $customer->id) {
            abort(403);
        }

        if (! $this->canUpload($order)) {
            throw ValidationException::withMessages([
                'proof' => __('Pesanan ini tidak memerlukan atau tidak dapat menerima unggahan bukti transfer.'),
            ]);
        }

        $path = $file->store('payment-proofs/'.$order->id, 'local');

        return DB::transaction(static function () use ($order, $customer, $file, $path, $senderName, $senderBank, $transferAmount, $notes): OrderPaymentProof {
            $proof = OrderPaymentProof::query()->create([
                'order_id' => $order->id,
                'user_id' => $customer->id,
                'disk' => 'local',
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType() ?: $file->getClientMimeType() ?: 'application/octet-stream',
                'size' => (int) $file->getSize(),
                'sender_name' => $senderName !== null ? trim($senderName) : null,
                'sender_bank' => $senderBank !== null ? trim($senderBank) : null,
                'transfer_amount' => $transferAmount,
                'transferred_at' => now(),
                'notes' => $notes !== null ? trim($notes) : null,
                'status' => OrderPaymentProof::STATUS_PENDING,
            ]);

            OrderNote::query()->create([
                'order_id' => $order->id,
                'user_id' => $customer->id,
                'type' => OrderNote::TYPE_AUDIT,
                'body' => sprintf(
                    'Pelanggan mengunggah bukti transfer #%d (%s).',
                    $proof->id,
                    $senderBank ? trim($senderBank).' — '.trim((string) $senderName) : 'Menunggu verifikasi Finance',
                ),
                'metadata' => ['payment_proof_id' => $proof->id],
            ]);

            return $proof;
        });
    }
}
