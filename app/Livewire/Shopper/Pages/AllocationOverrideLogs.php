<?php

declare(strict_types=1);

namespace App\Livewire\Shopper\Pages;

use App\Models\AllocationOverrideLog;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Shopper\Core\Models\Order;

#[Layout('shopper::components.layouts.app')]
class AllocationOverrideLogs extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public function mount(): void
    {
        abort_unless(Gate::allows('override-allocation'), 403);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                AllocationOverrideLog::query()
                    ->with(['order', 'actor', 'fromInventory', 'toInventory'])
                    ->latest('id')
            )
            ->columns([
                TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
                TextColumn::make('order.number')
                    ->label('Pesanan')
                    ->formatStateUsing(static fn (?string $state): string => $state ? '#'.$state : '-')
                    ->weight('semibold')
                    ->searchable()
                    ->url(static fn (AllocationOverrideLog $record): ?string => $record->order instanceof Order
                        ? route('shopper.orders.detail', ['order' => $record->order])
                        : null),
                TextColumn::make('fromInventory.name')
                    ->label('Dari Gudang')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('toInventory.name')
                    ->label('Ke Gudang')
                    ->badge()
                    ->color('primary'),
                TextColumn::make('qty')
                    ->label('Qty')
                    ->state(static fn (AllocationOverrideLog $record): int => (int) data_get($record->payload, 'move.qty', 0))
                    ->numeric()
                    ->alignRight(),
                TextColumn::make('reason')
                    ->label('Alasan')
                    ->state(static fn (AllocationOverrideLog $record): string => (string) (data_get($record->payload, 'reason') ?: '-'))
                    ->limit(60),
                TextColumn::make('actor.full_name')
                    ->label('Oleh Staf')
                    ->placeholder('-'),
            ])
            ->emptyStateHeading('Belum ada riwayat override alokasi gudang')
            ->emptyStateDescription('Pemindahan alokasi stok gudang oleh staf akan tercatat secara kronologis di sini.');
    }

    public function render(): View
    {
        return view('livewire.shopper.pages.allocation-override-logs')
            ->title('Log Override Alokasi Gudang');
    }
}
