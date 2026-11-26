<?php

declare(strict_types=1);

namespace App\Livewire\Shopper\Pages;

use Filament\Actions\BulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class OrderIndex extends \Shopper\Livewire\Pages\Order\Index
{
    public function table(Table $table): Table
    {
        $configured = parent::table($table);

        return $configured
            ->pushColumns([
                TextColumn::make('invoice_number')
                    ->label('No. Invoice')
                    ->placeholder('-')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('paid_at')
                    ->label('Dibayar')
                    ->dateTime('d M Y H:i')
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable()
                    ->toggledHiddenByDefault(),
            ])
            ->toolbarActions([
                BulkAction::make('print_pick_list')
                    ->label('Cetak Pick List')
                    ->authorize('print-shipment-label')
                    ->action(function (Collection $records): void {
                        $ids = $records->pluck('id')->implode(',');
                        $this->redirect(route('shopper.orders.warehouse.pick-list', ['orders' => $ids]));
                    }),
                BulkAction::make('print_bulk_labels')
                    ->label('Cetak Label Massal')
                    ->authorize('print-shipment-label')
                    ->action(function (Collection $records): void {
                        $ids = $records->pluck('id')->implode(',');
                        $this->redirect(route('shopper.orders.warehouse.bulk-labels', ['orders' => $ids]));
                    }),
            ]);
    }
}
