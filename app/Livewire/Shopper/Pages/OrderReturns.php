<?php

declare(strict_types=1);

namespace App\Livewire\Shopper\Pages;

use App\Actions\Cpanel\ProcessReturnRequest;
use App\Models\OrderReturnRequest;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Shopper\Core\Models\Inventory;

#[Layout('shopper::components.layouts.app')]
final class OrderReturns extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public string $statusFilter = 'requested';

    public function updatedStatusFilter(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                OrderReturnRequest::query()
                    ->with(['order', 'customer'])
                    ->when($this->statusFilter !== 'all', fn ($q) => $q->where('status', $this->statusFilter))
                    ->latest('id')
            )
            ->columns([
                TextColumn::make('id')
                    ->label('RMA')
                    ->formatStateUsing(static fn (int $state): string => '#RMA-'.$state)
                    ->weight('semibold')
                    ->sortable(),
                TextColumn::make('order.number')
                    ->label('No. Pesanan')
                    ->formatStateUsing(static fn (?string $state): string => $state ? '#'.$state : '-')
                    ->searchable()
                    ->url(static fn (OrderReturnRequest $record): ?string => $record->order
                        ? route('shopper.orders.detail', $record->order)
                        : null),
                TextColumn::make('customer.full_name')
                    ->label('Pelanggan')
                    ->placeholder('-')
                    ->description(static fn (OrderReturnRequest $record): string => (string) ($record->customer?->email ?? '')),
                TextColumn::make('reason')
                    ->label('Alasan Retur')
                    ->description(static fn (OrderReturnRequest $record): string => (string) ($record->description ?? ''))
                    ->wrap(),
                TextColumn::make('items')
                    ->label('Barang Diretur')
                    ->state(static fn (OrderReturnRequest $record): string => collect((array) $record->items)
                        ->map(static fn (array $item): string => ($item['name'] ?? 'Item').' × '.($item['qty'] ?? 1))
                        ->implode(', '))
                    ->wrap(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => match ($state) {
                        OrderReturnRequest::STATUS_REQUESTED => 'Baru',
                        OrderReturnRequest::STATUS_APPROVED => 'Disetujui',
                        OrderReturnRequest::STATUS_RESTOCKED => 'Sudah Restock',
                        OrderReturnRequest::STATUS_REFUNDED => 'Selesai Refund',
                        OrderReturnRequest::STATUS_REJECTED => 'Ditolak',
                        default => strtoupper($state),
                    })
                    ->color(static fn (string $state): string => match ($state) {
                        OrderReturnRequest::STATUS_REQUESTED => 'warning',
                        OrderReturnRequest::STATUS_APPROVED => 'info',
                        OrderReturnRequest::STATUS_RESTOCKED, OrderReturnRequest::STATUS_REFUNDED => 'success',
                        OrderReturnRequest::STATUS_REJECTED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')
                    ->label('Diajukan')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('approveReturn')
                    ->label('Setujui')
                    ->icon('heroicon-o-check')
                    ->color('primary')
                    ->visible(fn (OrderReturnRequest $record): bool => $record->status === OrderReturnRequest::STATUS_REQUESTED
                        && ($this->actor()->isAdmin() || Gate::allows('orders-refund')))
                    ->modalHeading('Setujui Permintaan Retur (RMA)')
                    ->schema([
                        Textarea::make('notes')
                            ->label('Catatan / Instruksi Pengiriman Balik')
                            ->rows(2),
                    ])
                    ->action(function (OrderReturnRequest $record, array $data): void {
                        $actor = $this->actor();
                        abort_unless($actor->isAdmin() || Gate::allows('orders-refund'), 403);

                        resolve(ProcessReturnRequest::class)->approve(
                            $record,
                            $actor,
                            filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
                        );
                        Notification::make()->title('Retur disetujui.')->success()->send();
                    }),
                Action::make('restockReturn')
                    ->label('Terima & Restock')
                    ->icon('heroicon-o-archive-box-arrow-down')
                    ->color('success')
                    ->visible(fn (OrderReturnRequest $record): bool => $record->status === OrderReturnRequest::STATUS_APPROVED
                        && ! $record->restocked_at
                        && ($this->actor()->isAdmin() || Gate::allows('manage-order-fulfillment')))
                    ->modalHeading('Konfirmasi Barang Diterima & Kembalikan ke Stok')
                    ->schema([
                        Select::make('inventory_id')
                            ->label('Gudang Penerima')
                            ->options(static fn (): array => Inventory::query()->orderByDesc('is_default')->pluck('name', 'id')->all())
                            ->default(static fn (): ?int => Inventory::query()->orderByDesc('is_default')->value('id'))
                            ->required(),
                    ])
                    ->action(function (OrderReturnRequest $record, array $data): void {
                        $actor = $this->actor();
                        abort_unless($actor->isAdmin() || Gate::allows('manage-order-fulfillment'), 403);

                        $inventoryId = isset($data['inventory_id']) ? (int) $data['inventory_id'] : null;
                        resolve(ProcessReturnRequest::class)->restock($record, $actor, $inventoryId);
                        Notification::make()->title('Stok retur dikembalikan.')->success()->send();
                    }),
                Action::make('rejectReturn')
                    ->label('Tolak')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn (OrderReturnRequest $record): bool => $record->status === OrderReturnRequest::STATUS_REQUESTED
                        && ($this->actor()->isAdmin() || Gate::allows('orders-refund')))
                    ->modalHeading('Tolak Permintaan Retur')
                    ->schema([
                        Textarea::make('notes')
                            ->label('Alasan Penolakan')
                            ->rows(2)
                            ->required()
                            ->default('Ditolak oleh CS.'),
                    ])
                    ->action(function (OrderReturnRequest $record, array $data): void {
                        $actor = $this->actor();
                        abort_unless($actor->isAdmin() || Gate::allows('orders-refund'), 403);

                        resolve(ProcessReturnRequest::class)->reject(
                            $record,
                            $actor,
                            trim((string) ($data['notes'] ?? 'Ditolak oleh CS.')),
                        );
                        Notification::make()->title('Retur ditolak.')->warning()->send();
                    }),
            ])
            ->emptyStateHeading('Belum ada pengajuan retur (RMA)')
            ->emptyStateDescription('Pengajuan retur barang dari pelanggan akan muncul di sini untuk ditinjau CS & gudang.');
    }

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

        $counts = OrderReturnRequest::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $tabs = [
            ['key' => 'requested', 'label' => 'Baru', 'badge' => (int) ($counts[OrderReturnRequest::STATUS_REQUESTED] ?? 0), 'badgeColor' => 'warning'],
            ['key' => 'approved', 'label' => 'Disetujui', 'badge' => (int) ($counts[OrderReturnRequest::STATUS_APPROVED] ?? 0), 'badgeColor' => 'info'],
            ['key' => 'restocked', 'label' => 'Sudah Restock', 'badge' => (int) ($counts[OrderReturnRequest::STATUS_RESTOCKED] ?? 0), 'badgeColor' => 'success'],
            ['key' => 'refunded', 'label' => 'Selesai Refund', 'badge' => (int) ($counts[OrderReturnRequest::STATUS_REFUNDED] ?? 0), 'badgeColor' => 'success'],
            ['key' => 'rejected', 'label' => 'Ditolak', 'badge' => (int) ($counts[OrderReturnRequest::STATUS_REJECTED] ?? 0), 'badgeColor' => 'danger'],
            ['key' => 'all', 'label' => 'Semua', 'badge' => (int) $counts->sum(), 'badgeColor' => 'gray'],
        ];

        return view('shopper.livewire.pages.order-returns', [
            'tabs' => $tabs,
        ])->title('Daftar Retur & RMA');
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
