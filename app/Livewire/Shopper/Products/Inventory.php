<?php

declare(strict_types=1);

namespace App\Livewire\Shopper\Products;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Shopper\Core\Models\InventoryHistory;
use Mckenziearts\Icons\Untitledui\Enums\Untitledui;

class Inventory extends \Shopper\Livewire\Components\Products\Form\Inventory
{
    public function table(Table $table): Table
    {
        return $table
            ->query(
                InventoryHistory::with(['inventory', 'stockable', 'user'])
                    ->where('stockable_id', $this->product->id)
                    ->whereIn('stockable_type', array_unique([$this->product->getMorphClass(), 'product']))
                    ->latest()
            )
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('shopper::words.date'))
                    ->since()
                    ->sortable(),
                TextColumn::make('event')
                    ->label(__('shopper::words.event')),
                TextColumn::make('inventory.name')
                    ->label(__('shopper::pages/settings/menu.location')),
                TextColumn::make('user.full_name')
                    ->label('Oleh Staf')
                    ->placeholder('-'),
                TextColumn::make('description')
                    ->label('Alasan / Catatan')
                    ->limit(50)
                    ->placeholder('-'),
                TextColumn::make('adjustment')
                    ->label(__('shopper::words.adjustment'))
                    ->color(static fn (InventoryHistory $record): string => $record->old_quantity <= 0 ? 'danger' : 'success')
                    ->alignRight(),
                TextColumn::make('quantity')
                    ->label(__('shopper::pages/products.inventory.movement'))
                    ->color(static fn (InventoryHistory $record): string => $record->quantity <= 0 ? 'danger' : 'gray')
                    ->alignRight()
                    ->summarize([
                        Sum::make()
                            ->label(__('shopper::words.total'))
                            ->numeric(),
                    ]),
            ])
            ->headerActions([
                Action::make('stock')
                    ->authorize('edit_products')
                    ->label(__('shopper::forms.label.add_stock'))
                    ->icon(Untitledui::Package)
                    ->color('gray')
                    ->modalWidth(Width::Large)
                    ->schema([
                        Select::make('inventory')
                            ->label(__('shopper::pages/products.inventory_name'))
                            ->relationship('inventory', 'name')
                            ->native(false)
                            ->required(),
                        TextInput::make('quantity')
                            ->label(__('shopper::forms.label.quantity'))
                            ->placeholder('-10 or -5 or 50, etc')
                            ->numeric()
                            ->required(),
                        Textarea::make('reason')
                            ->label('Alasan Penyesuaian Stok')
                            ->placeholder('Contoh: Stock opname, barang masuk PO, barang rusak')
                            ->rows(2)
                            ->maxLength(255)
                            ->default('Penyesuaian stok manual'),
                    ])
                    ->action(function (array $data): void {
                        $inventoryId = (int) $data['inventory'];
                        $quantity = (int) $data['quantity'];
                        $reason = trim((string) ($data['reason'] ?? ''));

                        if ($quantity === 0) {
                            Notification::make()->title('Jumlah perubahan stok tidak boleh nol.')->warning()->send();

                            return;
                        }

                        if ($reason === '') {
                            Notification::make()->title('Alasan penyesuaian stok wajib diisi.')->danger()->send();

                            return;
                        }

                        $currentStock = $this->product->stockInventory($inventoryId);

                        if (! ($this->product->allow_backorder ?? false) && ($currentStock + $quantity) < 0) {
                            Notification::make()
                                ->title('Stok tidak boleh menjadi negatif.')
                                ->body("Stok saat ini di gudang ini hanya {$currentStock}.")
                                ->danger()
                                ->send();

                            return;
                        }

                        $actorId = Auth::id();

                        if ($quantity > 0) {
                            $this->product->mutateStock(
                                inventoryId: $inventoryId,
                                quantity: $quantity,
                                oldQuantity: $quantity,
                                event: __('shopper::pages/products.inventory.add'),
                                description: $reason,
                                userId: $actorId,
                            );
                        } else {
                            $this->product->decreaseStock(
                                inventoryId: $inventoryId,
                                quantity: abs($quantity),
                                oldQuantity: -1 * abs($quantity),
                                event: __('shopper::pages/products.inventory.remove'),
                                description: $reason,
                                userId: $actorId,
                            );
                        }

                        Notification::make()
                            ->title(__('shopper::notifications.products.inventory.updated'))
                            ->success()
                            ->send();

                        $this->dispatch('inventory.updated');
                    }),
            ]);
    }
}
