<?php

declare(strict_types=1);

namespace App\Livewire\Shopper;

use App\Models\OrderNote;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Shopper\Core\Events\Orders\OrderNoteAdded;
use Shopper\Core\Models\Order;

final class OrderNotes extends Component
{
    #[Locked]
    public Order $order;

    public string $body = '';

    #[On('order.updated')]
    public function refreshNotes(): void
    {
        $this->order->refresh();
    }

    public function addNote(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User && ($user->isAdmin() || Gate::allows('edit_orders')), 403);

        $validated = $this->validate([
            'body' => ['required', 'string', 'min:2', 'max:2000'],
        ]);

        OrderNote::query()->create([
            'order_id' => $this->order->id,
            'user_id' => $user->id,
            'type' => OrderNote::TYPE_INTERNAL,
            'body' => trim($validated['body']),
        ]);

        if (blank($this->order->notes)) {
            $this->order->forceFill(['notes' => trim($validated['body'])])->save();
        }

        event(new OrderNoteAdded($this->order->refresh()));
        $this->body = '';

        Notification::make()
            ->title('Catatan internal order tersimpan.')
            ->success()
            ->send();
    }

    public function render(): View
    {
        $user = Auth::user();
        abort_unless($user !== null && ($user->isAdmin() || Gate::allows('read_orders') || Gate::allows('browse_orders')), 403);

        $entries = OrderNote::query()
            ->with('author')
            ->where('order_id', $this->order->id)
            ->latest('id')
            ->get();

        return view('shopper.livewire.order-notes', [
            'entries' => $entries,
            'canWrite' => $user->isAdmin() || Gate::allows('edit_orders'),
        ]);
    }
}
