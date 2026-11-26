<?php

declare(strict_types=1);

namespace App\Livewire\Shopper\Pages;

use App\Actions\Orders\RecordOrderRefund;
use App\Models\PaymentDispute;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;
use Shopper\Core\Models\Order;

#[Layout('shopper::components.layouts.app')]
final class PaymentDisputes extends Component
{
    use WithPagination;

    public string $statusFilter = 'open';

    public function resolveWithRefund(int $disputeId): void
    {
        $actor = $this->actor();
        abort_unless($actor->isAdmin() || Gate::allows('orders-refund'), 403);

        $dispute = PaymentDispute::query()->with('order')->findOrFail($disputeId);

        if ($dispute->order instanceof Order && $dispute->status === PaymentDispute::STATUS_OPEN) {
            $refund = resolve(RecordOrderRefund::class)->handle(
                order: $dispute->order,
                amount: max(1, (int) ($dispute->amount ?? $dispute->order->price_amount)),
                reason: 'Penyelesaian sengketa '.$dispute->type.' ('.$dispute->reference.')',
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
                'resolution_notes' => 'Diselesaikan lewat Antrean Sengketa.',
            ])->save();

            Notification::make()->title('Sengketa diselesaikan & refund tercatat.')->success()->send();
        }
    }

    public function dismiss(int $disputeId): void
    {
        $actor = $this->actor();
        abort_unless($actor->isAdmin() || Gate::allows('orders-refund'), 403);

        PaymentDispute::query()->whereKey($disputeId)->update([
            'status' => PaymentDispute::STATUS_DISMISSED,
            'resolved_by' => $actor->id,
            'resolved_at' => now(),
            'resolution_notes' => 'Ditutup oleh staf.',
        ]);

        Notification::make()->title('Sengketa ditutup.')->success()->send();
    }

    public function render(): View
    {
        $actor = $this->actor();
        abort_unless($actor->isAdmin() || Gate::allows('browse_orders'), 403);

        $disputes = PaymentDispute::query()
            ->with(['order', 'resolver'])
            ->when($this->statusFilter !== 'all', fn ($q) => $q->where('status', $this->statusFilter))
            ->latest('id')
            ->paginate(20);

        return view('shopper.livewire.pages.payment-disputes', [
            'disputes' => $disputes,
        ]);
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
