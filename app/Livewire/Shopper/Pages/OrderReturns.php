<?php

declare(strict_types=1);

namespace App\Livewire\Shopper\Pages;

use App\Actions\Cpanel\ProcessReturnRequest;
use App\Models\OrderReturnRequest;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;
use Shopper\Core\Models\Inventory;

#[Layout('shopper::components.layouts.app')]
final class OrderReturns extends Component
{
    use WithPagination;

    public string $statusFilter = 'requested';

    public function approve(int $id): void
    {
        $actor = $this->actor();
        abort_unless($actor->isAdmin() || Gate::allows('orders-refund'), 403);

        $row = OrderReturnRequest::query()->findOrFail($id);
        resolve(ProcessReturnRequest::class)->approve($row, $actor);
        Notification::make()->title('Retur disetujui.')->success()->send();
    }

    public function restock(int $id): void
    {
        $actor = $this->actor();
        abort_unless($actor->isAdmin() || Gate::allows('manage-order-fulfillment'), 403);

        $row = OrderReturnRequest::query()->findOrFail($id);
        $defaultInv = Inventory::query()->orderByDesc('is_default')->value('id');
        resolve(ProcessReturnRequest::class)->restock($row, $actor, $defaultInv ? (int) $defaultInv : null);
        Notification::make()->title('Stok retur dikembalikan.')->success()->send();
    }

    public function reject(int $id): void
    {
        $actor = $this->actor();
        abort_unless($actor->isAdmin() || Gate::allows('orders-refund'), 403);

        $row = OrderReturnRequest::query()->findOrFail($id);
        resolve(ProcessReturnRequest::class)->reject($row, $actor, 'Ditolak oleh CS.');
        Notification::make()->title('Retur ditolak.')->warning()->send();
    }

    public function render(): View
    {
        $actor = $this->actor();
        abort_unless($actor->isAdmin() || Gate::allows('browse_orders'), 403);

        $returns = OrderReturnRequest::query()
            ->with(['order', 'customer'])
            ->when($this->statusFilter !== 'all', fn ($q) => $q->where('status', $this->statusFilter))
            ->latest('id')
            ->paginate(20);

        return view('shopper.livewire.pages.order-returns', [
            'returns' => $returns,
        ]);
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
