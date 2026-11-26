<?php

declare(strict_types=1);

namespace App\Livewire\Shopper\Pages;

use App\Actions\Cpanel\VerifyPaymentProof;
use App\Models\OrderPaymentProof;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

#[Layout('shopper::components.layouts.app')]
final class ManualPaymentProofs extends Component
{
    use WithPagination;

    public string $statusFilter = 'pending';

    public function approve(int $proofId): void
    {
        $actor = $this->actor();
        abort_unless($actor->isAdmin() || Gate::allows('orders-mark-paid'), 403);

        $proof = OrderPaymentProof::query()->findOrFail($proofId);

        try {
            resolve(VerifyPaymentProof::class)->approve($proof, $actor);
            Notification::make()->title('Bukti bayar disetujui & order lunas.')->success()->send();
        } catch (Throwable $e) {
            Notification::make()->title('Gagal menyetujui bukti bayar.')->body($e->getMessage())->danger()->send();
        }
    }

    public function reject(int $proofId): void
    {
        $actor = $this->actor();
        abort_unless($actor->isAdmin() || Gate::allows('orders-mark-paid'), 403);

        $proof = OrderPaymentProof::query()->findOrFail($proofId);
        resolve(VerifyPaymentProof::class)->reject($proof, $actor, 'Ditolak saat verifikasi mutasi.');
        Notification::make()->title('Bukti bayar ditolak.')->warning()->send();
    }

    public function render(): View
    {
        $actor = $this->actor();
        abort_unless($actor->isAdmin() || Gate::allows('browse_orders'), 403);

        $proofs = OrderPaymentProof::query()
            ->with(['order', 'uploader'])
            ->when($this->statusFilter !== 'all', fn ($q) => $q->where('status', $this->statusFilter))
            ->latest('id')
            ->paginate(20);

        return view('shopper.livewire.pages.manual-payment-proofs', [
            'proofs' => $proofs,
        ]);
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
