<?php

declare(strict_types=1);

namespace App\Livewire\Shopper\Pages;

use App\Actions\Orders\RecordOrderRefund;
use App\Models\PaymentDispute;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
use Shopper\Core\Models\Order;

#[Layout('shopper::components.layouts.app')]
final class PaymentDisputes extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public string $statusFilter = 'open';

    public function updatedStatusFilter(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                PaymentDispute::query()
                    ->with(['order', 'resolver'])
                    ->when($this->statusFilter !== 'all', fn ($q) => $q->where('status', $this->statusFilter))
                    ->latest('id')
            )
            ->columns([
                TextColumn::make('order.number')
                    ->label('No. Pesanan')
                    ->formatStateUsing(static fn (?string $state): string => $state ? '#'.$state : '-')
                    ->weight('semibold')
                    ->searchable()
                    ->url(static fn (PaymentDispute $record): ?string => $record->order
                        ? route('shopper.orders.detail', $record->order)
                        : null),
                TextColumn::make('type')
                    ->label('Jenis Sengketa')
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => match ($state) {
                        PaymentDispute::TYPE_PAID_AFTER_CANCELLATION => 'Bayar Setelah Batal',
                        PaymentDispute::TYPE_AMOUNT_MISMATCH => 'Selisih Nominal',
                        PaymentDispute::TYPE_DOUBLE_PAYMENT => 'Pembayaran Ganda',
                        default => strtoupper(str_replace('_', ' ', $state)),
                    })
                    ->color(static fn (string $state): string => match ($state) {
                        PaymentDispute::TYPE_PAID_AFTER_CANCELLATION => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('reference')
                    ->label('Driver / Referensi')
                    ->state(static fn (PaymentDispute $record): string => $record->driver.' / '.$record->reference)
                    ->description(static fn (PaymentDispute $record): string => (string) ($record->reason ?? ''))
                    ->searchable(['driver', 'reference']),
                TextColumn::make('amount')
                    ->label('Nominal')
                    ->formatStateUsing(static fn (mixed $state, PaymentDispute $record): string => shopper_money_format((int) ($state ?? 0), (string) $record->currency_code))
                    ->alignRight(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => match ($state) {
                        PaymentDispute::STATUS_OPEN => 'Terbuka',
                        PaymentDispute::STATUS_RESOLVED_REFUNDED => 'Selesai (Refund)',
                        PaymentDispute::STATUS_DISMISSED => 'Ditutup',
                        default => strtoupper($state),
                    })
                    ->color(static fn (string $state): string => match ($state) {
                        PaymentDispute::STATUS_OPEN => 'danger',
                        PaymentDispute::STATUS_RESOLVED_REFUNDED => 'success',
                        PaymentDispute::STATUS_DISMISSED => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')
                    ->label('Terdeteksi')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('resolveRefundAction')
                    ->label('Catat Refund')
                    ->icon('heroicon-o-receipt-refund')
                    ->color('danger')
                    ->visible(fn (PaymentDispute $record): bool => $record->status === PaymentDispute::STATUS_OPEN
                        && $record->order instanceof Order
                        && ($this->actor()->isAdmin() || Gate::allows('orders-refund')))
                    ->modalHeading('Selesaikan Sengketa dengan Refund')
                    ->schema([
                        TextInput::make('reference')
                            ->label('Referensi Transfer Refund')
                            ->default(static fn (PaymentDispute $record): string => 'dispute-'.$record->id)
                            ->maxLength(120),
                        Textarea::make('notes')
                            ->label('Catatan Penyelesaian')
                            ->rows(2)
                            ->default('Diselesaikan lewat Antrean Sengketa.'),
                    ])
                    ->action(function (PaymentDispute $record, array $data): void {
                        $actor = $this->actor();
                        abort_unless($actor->isAdmin() || Gate::allows('orders-refund'), 403);

                        if (! $record->order instanceof Order || $record->status !== PaymentDispute::STATUS_OPEN) {
                            return;
                        }

                        $refund = resolve(RecordOrderRefund::class)->handle(
                            order: $record->order,
                            amount: max(1, (int) ($record->amount ?? $record->order->price_amount)),
                            reason: 'Penyelesaian sengketa '.$record->type.' ('.$record->reference.')',
                            actor: $actor,
                            method: 'manual_bank_transfer',
                            reference: filled($data['reference'] ?? null) ? trim((string) $data['reference']) : ('dispute-'.$record->id),
                            dispute: $record,
                        );

                        $record->forceFill([
                            'status' => PaymentDispute::STATUS_RESOLVED_REFUNDED,
                            'resolved_by' => $actor->id,
                            'resolved_at' => now(),
                            'order_refund_id' => $refund->id,
                            'resolution_notes' => trim((string) ($data['notes'] ?? 'Diselesaikan lewat Antrean Sengketa.')),
                        ])->save();

                        Notification::make()->title('Sengketa diselesaikan & refund tercatat.')->success()->send();
                    }),
                Action::make('dismissAction')
                    ->label('Tutup')
                    ->icon('heroicon-o-check')
                    ->color('gray')
                    ->visible(fn (PaymentDispute $record): bool => $record->status === PaymentDispute::STATUS_OPEN
                        && ($this->actor()->isAdmin() || Gate::allows('orders-refund')))
                    ->modalHeading('Tutup Sengketa Tanpa Refund')
                    ->schema([
                        Textarea::make('notes')
                            ->label('Alasan Penutupan')
                            ->rows(2)
                            ->required()
                            ->default('Ditutup oleh staf setelah rekonsiliasi.'),
                    ])
                    ->action(function (PaymentDispute $record, array $data): void {
                        $actor = $this->actor();
                        abort_unless($actor->isAdmin() || Gate::allows('orders-refund'), 403);

                        $record->forceFill([
                            'status' => PaymentDispute::STATUS_DISMISSED,
                            'resolved_by' => $actor->id,
                            'resolved_at' => now(),
                            'resolution_notes' => trim((string) ($data['notes'] ?? 'Ditutup oleh staf.')),
                        ])->save();

                        Notification::make()->title('Sengketa ditutup.')->success()->send();
                    }),
            ])
            ->emptyStateHeading('Tidak ada sengketa pembayaran')
            ->emptyStateDescription('Sengketa selisih nominal, pembayaran ganda, atau pembayaran setelah batal akan tampil di sini.');
    }

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

        $counts = PaymentDispute::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $tabs = [
            ['key' => 'open', 'label' => 'Terbuka', 'badge' => (int) ($counts[PaymentDispute::STATUS_OPEN] ?? 0), 'badgeColor' => 'danger'],
            ['key' => 'resolved_refunded', 'label' => 'Selesai (Refund)', 'badge' => (int) ($counts[PaymentDispute::STATUS_RESOLVED_REFUNDED] ?? 0), 'badgeColor' => 'success'],
            ['key' => 'dismissed', 'label' => 'Ditutup', 'badge' => (int) ($counts[PaymentDispute::STATUS_DISMISSED] ?? 0), 'badgeColor' => 'gray'],
            ['key' => 'all', 'label' => 'Semua', 'badge' => (int) $counts->sum(), 'badgeColor' => 'gray'],
        ];

        return view('shopper.livewire.pages.payment-disputes', [
            'tabs' => $tabs,
        ])->title('Antrean Sengketa Pembayaran');
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
