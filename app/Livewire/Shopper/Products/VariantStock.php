<?php

declare(strict_types=1);

namespace App\Livewire\Shopper\Products;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Shopper\Core\Models\InventoryHistory;
use Mckenziearts\Icons\Untitledui\Enums\Untitledui;

class VariantStock extends \Shopper\Livewire\Components\Products\VariantStock
{
    public function stockAction(): Action
    {
        return Action::make('stock')
            ->authorize('edit_product_variants')
            ->label(__('shopper::forms.actions.update'))
            ->color('gray')
            ->icon(Untitledui::Package)
            ->modalHeading(__('shopper::pages/products.modals.variants.title'))
            ->modalWidth(Width::Large)
            ->schema([
                Select::make('inventory')
                    ->label(__('shopper::pages/products.inventory_name'))
                    ->options($this->inventories->pluck('name', 'id'))
                    ->native(false)
                    ->required(),
                TextInput::make('quantity')
                    ->label(__('shopper::forms.label.quantity'))
                    ->placeholder('-10 or -5 or 50, etc')
                    ->numeric()
                    ->required(),
                Textarea::make('reason')
                    ->label('Alasan Penyesuaian Stok')
                    ->placeholder('Contoh: Stock opname varian, retur layak jual, rusak')
                    ->rows(2)
                    ->maxLength(255)
                    ->default('Penyesuaian stok varian'),
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

                $currentStock = $this->variant->stockInventory($inventoryId);

                if (! ($this->variant->allow_backorder ?? false) && ($currentStock + $quantity) < 0) {
                    Notification::make()
                        ->title('Stok varian tidak boleh negatif.')
                        ->body("Stok saat ini di gudang ini hanya {$currentStock}.")
                        ->danger()
                        ->send();

                    return;
                }

                $actorId = Auth::id();

                if ($quantity > 0) {
                    $this->variant->mutateStock(
                        inventoryId: $inventoryId,
                        quantity: $quantity,
                        oldQuantity: $quantity,
                        event: __('shopper::pages/products.inventory.add'),
                        description: $reason,
                        userId: $actorId,
                    );
                } else {
                    $this->variant->decreaseStock(
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
            });
    }

    /**
     * @return Collection<int, InventoryHistory>
     */
    #[Computed]
    public function histories(): Collection
    {
        return InventoryHistory::query()
            ->with(['inventory', 'user'])
            ->where('stockable_id', $this->variant->id)
            ->whereIn('stockable_type', array_unique([
                $this->variant->getMorphClass(),
                (string) config('shopper.models.variant'),
                'variant',
            ]))
            ->latest('id')
            ->limit(15)
            ->get();
    }

    public function render(): View
    {
        return view('livewire.shopper.products.variant-stock');
    }
}
