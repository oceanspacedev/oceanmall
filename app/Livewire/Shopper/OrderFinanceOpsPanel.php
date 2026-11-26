<?php

declare(strict_types=1);

namespace App\Livewire\Shopper;

use App\Actions\Cpanel\ProcessReturnRequest;
use App\Actions\Cpanel\VerifyPaymentProof;
use App\Actions\Orders\RecordOrderRefund;
use App\Models\OrderNote;
use App\Models\OrderPaymentProof;
use App\Models\OrderReturnRequest;
use App\Models\PaymentDispute;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Shopper\Core\Enum\OrderRefundStatus;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\OrderRefund;
use Throwable;

final class OrderFinanceOpsPanel extends Component
{
    #[Locked]
    public Order $order;

    public ?int $refundAmount = null;

    public string $refundMethod = 'manual_bank_transfer';

    public string $refundReason = '';

    public string $refundReference = '';

    public string $proofBankRef = '';

    public string $proofRejectReason = '';

    public function mount(Order $order): void
    {
        $this->order = $order;
        $this->refundAmount = max(0, (int) $order->price_amount - $this->refundedTotal());
    }

    #[On('order.updated')]
    public function refreshPanel(): void
    {
        $this->order->refresh();
        $this->refundAmount = max(0, (int) $this->order->price_amount - $this->refundedTotal());
    }

    public function approveProof(int $proofId): void
    {
        $actor = $this->actor();
        abort_unless($actor->isAdmin() || Gate::allows('orders-mark-paid'), 403);

        $proof = OrderPaymentProof::query()->where('order_id', $this->order->id)->findOrFail($proofId);

        try {
            resolve(VerifyPaymentProof::class)->approve(
                proof: $proof,
                actor: $actor,
                verifiedAmount: $proof->transfer_amount ?? (int) $this->order->price_amount,
                bankReference: $this->proofBankRef ?: null,
            );
            $this->order->refresh();
            $this->dispatch('order.updated');

            Notification::make()->title('Bukti transfer disetujui & order ditandai lunas.')->success()->send();
        } catch (Throwable $e) {
            Notification::make()->title('Gagal menyetujui bukti bayar.')->body($e->getMessage())->danger()->send();
        }
    }

    public function rejectProof(int $proofId): void
    {
        $actor = $this->actor();
        abort_unless($actor->isAdmin() || Gate::allows('orders-mark-paid'), 403);

        $proof = OrderPaymentProof::query()->where('order_id', $this->order->id)->findOrFail($proofId);
        resolve(VerifyPaymentProof::class)->reject($proof, $actor, $this->proofRejectReason);
        $this->proofRejectReason = '';
        $this->dispatch('order.updated');

        Notification::make()->title('Bukti transfer ditolak.')->warning()->send();
    }

    public function recordRefund(): void
    {
        $actor = $this->actor();
        abort_unless($actor->isAdmin() || Gate::allows('orders-refund'), 403);

        $validated = $this->validate([
            'refundAmount' => ['required', 'integer', 'min:1'],
            'refundMethod' => ['required', 'string', 'in:manual_bank_transfer,gateway'],
            'refundReason' => ['required', 'string', 'min:3', 'max:500'],
            'refundReference' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            resolve(RecordOrderRefund::class)->handle(
                order: $this->order,
                amount: (int) $validated['refundAmount'],
                reason: (string) $validated['refundReason'],
                actor: $actor,
                method: (string) $validated['refundMethod'],
                reference: $validated['refundReference'] ?: null,
            );
            $this->refundReason = '';
            $this->refundReference = '';
            $this->refreshPanel();
            $this->dispatch('order.updated');

            Notification::make()->title('Refund berhasil dicatat ke buku besar.')->success()->send();
        } catch (Throwable $e) {
            Notification::make()->title('Gagal memproses refund.')->body($e->getMessage())->danger()->send();
        }
    }

    public function approveReturn(int $returnId): void
    {
        $actor = $this->actor();
        abort_unless($actor->isAdmin() || Gate::allows('orders-refund'), 403);

        $returnRequest = OrderReturnRequest::query()->where('order_id', $this->order->id)->findOrFail($returnId);
        resolve(ProcessReturnRequest::class)->approve($returnRequest, $actor);
        $this->dispatch('order.updated');
        Notification::make()->title('Pengajuan retur disetujui.')->success()->send();
    }

    public function restockReturn(int $returnId): void
    {
        $actor = $this->actor();
        abort_unless($actor->isAdmin() || Gate::allows('manage-order-fulfillment'), 403);

        $returnRequest = OrderReturnRequest::query()->where('order_id', $this->order->id)->findOrFail($returnId);
        $defaultInv = Inventory::query()->orderByDesc('is_default')->value('id');
        resolve(ProcessReturnRequest::class)->restock($returnRequest, $actor, $defaultInv ? (int) $defaultInv : null);
        $this->dispatch('order.updated');
        Notification::make()->title('Stok retur berhasil dikembalikan ke gudang.')->success()->send();
    }

    public function rejectReturn(int $returnId): void
    {
        $actor = $this->actor();
        abort_unless($actor->isAdmin() || Gate::allows('orders-refund'), 403);

        $returnRequest = OrderReturnRequest::query()->where('order_id', $this->order->id)->findOrFail($returnId);
        resolve(ProcessReturnRequest::class)->reject($returnRequest, $actor, 'Ditolak oleh tim CS.');
        $this->dispatch('order.updated');
        Notification::make()->title('Pengajuan retur ditolak.')->warning()->send();
    }

    public function resolveDisputeRefund(int $disputeId): void
    {
        $actor = $this->actor();
        abort_unless($actor->isAdmin() || Gate::allows('orders-refund'), 403);

        $dispute = PaymentDispute::query()->where('order_id', $this->order->id)->findOrFail($disputeId);

        if ($dispute->status !== PaymentDispute::STATUS_OPEN) {
            return;
        }

        $amount = (int) ($dispute->amount ?? $this->order->price_amount);
        $refund = resolve(RecordOrderRefund::class)->handle(
            order: $this->order,
            amount: max(1, $amount),
            reason: 'Penyelesaian sengketa '.$dispute->type.' (ref: '.$dispute->reference.')',
            actor: $actor,
            method: 'manual_bank_transfer',
            reference: 'dispute-'.$dispute->id,
            dispute: $dispute,
        );

        $dispute->forceFill([
            'status' => PaymentDispute::STATUS_RESOLVED_REFUNDED,
            'resolved_by' => $actor->id,
            'resolved_at' => now(),
            'order_refund_id' => $refund->id,
            'resolution_notes' => 'Diselesaikan dengan pencatatan refund.',
        ])->save();

        $this->dispatch('order.updated');
        Notification::make()->title('Sengketa diselesaikan & refund tercatat.')->success()->send();
    }

    public function dismissDispute(int $disputeId): void
    {
        $actor = $this->actor();
        abort_unless($actor->isAdmin() || Gate::allows('orders-refund'), 403);

        $dispute = PaymentDispute::query()->where('order_id', $this->order->id)->findOrFail($disputeId);
        $dispute->forceFill([
            'status' => PaymentDispute::STATUS_DISMISSED,
            'resolved_by' => $actor->id,
            'resolved_at' => now(),
            'resolution_notes' => 'Ditutup oleh staf.',
        ])->save();

        OrderNote::query()->create([
            'order_id' => $this->order->id,
            'user_id' => $actor->id,
            'type' => OrderNote::TYPE_AUDIT,
            'body' => sprintf('Sengketa #%d (%s) ditutup.', $dispute->id, $dispute->type),
        ]);

        $this->dispatch('order.updated');
        Notification::make()->title('Sengketa ditutup.')->success()->send();
    }

    public function render(): View
    {
        $actor = $this->actor();
        abort_unless($actor->isAdmin() || Gate::allows('read_orders') || Gate::allows('browse_orders'), 403);

        return view('shopper.livewire.order-finance-ops-panel', [
            'disputes' => PaymentDispute::query()->where('order_id', $this->order->id)->latest('id')->get(),
            'proofs' => OrderPaymentProof::query()->where('order_id', $this->order->id)->latest('id')->get(),
            'refunds' => OrderRefund::query()->where('order_id', $this->order->id)->latest('id')->get(),
            'returns' => OrderReturnRequest::query()->where('order_id', $this->order->id)->latest('id')->get(),
            'refundedTotal' => $this->refundedTotal(),
        ]);
    }

    private function refundedTotal(): int
    {
        return (int) OrderRefund::query()
            ->where('order_id', $this->order->id)
            ->whereIn('status', [OrderRefundStatus::Refunded->value, OrderRefundStatus::Partial_Refund->value])
            ->sum('amount');
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
