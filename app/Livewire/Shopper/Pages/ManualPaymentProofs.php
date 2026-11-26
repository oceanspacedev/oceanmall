<?php

declare(strict_types=1);

namespace App\Livewire\Shopper\Pages;

use App\Actions\Cpanel\VerifyPaymentProof;
use App\Models\OrderPaymentProof;
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
use Throwable;

#[Layout('shopper::components.layouts.app')]
final class ManualPaymentProofs extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public string $statusFilter = 'pending';

    public function updatedStatusFilter(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                OrderPaymentProof::query()
                    ->with(['order', 'uploader'])
                    ->when($this->statusFilter !== 'all', fn ($q) => $q->where('status', $this->statusFilter))
                    ->latest('id')
            )
            ->columns([
                TextColumn::make('order.number')
                    ->label('No. Pesanan')
                    ->formatStateUsing(static fn (?string $state): string => $state ? '#'.$state : '-')
                    ->weight('semibold')
                    ->searchable()
                    ->url(static fn (OrderPaymentProof $record): ?string => $record->order
                        ? route('shopper.orders.detail', $record->order)
                        : null),
                TextColumn::make('sender_name')
                    ->label('Pengirim & Bank')
                    ->state(static fn (OrderPaymentProof $record): string => trim(($record->sender_bank ?: 'Bank').' · '.($record->sender_name ?: ($record->uploader?->full_name ?? '-'))))
                    ->description(static fn (OrderPaymentProof $record): string => (string) ($record->uploader?->email ?? ''))
                    ->searchable(['sender_name', 'sender_bank']),
                TextColumn::make('transfer_amount')
                    ->label('Nominal Transfer')
                    ->formatStateUsing(static fn (mixed $state, OrderPaymentProof $record): string => $state
                        ? shopper_money_format((int) $state, (string) ($record->order?->currency_code ?? 'IDR'))
                        : '-')
                    ->alignRight(),
                TextColumn::make('original_name')
                    ->label('Berkas Bukti')
                    ->limit(28)
                    ->color('primary')
                    ->url(static fn (OrderPaymentProof $record): ?string => $record->order
                        ? route('shopper.orders.payment-proofs.show', ['order' => $record->order->id, 'proof' => $record->id])
                        : null, shouldOpenInNewTab: true),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => match ($state) {
                        OrderPaymentProof::STATUS_PENDING => 'Menunggu',
                        OrderPaymentProof::STATUS_APPROVED => 'Disetujui',
                        OrderPaymentProof::STATUS_REJECTED => 'Ditolak',
                        default => strtoupper($state),
                    })
                    ->color(static fn (string $state): string => match ($state) {
                        OrderPaymentProof::STATUS_PENDING => 'warning',
                        OrderPaymentProof::STATUS_APPROVED => 'success',
                        OrderPaymentProof::STATUS_REJECTED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')
                    ->label('Diunggah')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('viewFile')
                    ->label('Lihat Bukti')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->visible(static fn (OrderPaymentProof $record): bool => $record->order !== null)
                    ->url(static fn (OrderPaymentProof $record): string => route('shopper.orders.payment-proofs.show', [
                        'order' => $record->order->id,
                        'proof' => $record->id,
                    ]), shouldOpenInNewTab: true),
                Action::make('approveProof')
                    ->label('Setujui')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (OrderPaymentProof $record): bool => $record->status === OrderPaymentProof::STATUS_PENDING
                        && ($this->actor()->isAdmin() || Gate::allows('orders-mark-paid')))
                    ->modalHeading('Setujui Bukti Transfer & Tandai Lunas')
                    ->modalDescription(static fn (OrderPaymentProof $record): string => 'Konfirmasi dana masuk untuk pesanan #'.($record->order?->number ?? '-').'.')
                    ->schema([
                        TextInput::make('verified_amount')
                            ->label('Nominal Diverifikasi')
                            ->numeric()
                            ->required()
                            ->default(static fn (OrderPaymentProof $record): int => (int) ($record->transfer_amount ?: ($record->order?->price_amount ?? 0))),
                        TextInput::make('bank_reference')
                            ->label('Referensi Mutasi Bank (Opsional)')
                            ->maxLength(120),
                    ])
                    ->action(function (OrderPaymentProof $record, array $data): void {
                        $actor = $this->actor();
                        abort_unless($actor->isAdmin() || Gate::allows('orders-mark-paid'), 403);

                        try {
                            resolve(VerifyPaymentProof::class)->approve(
                                proof: $record,
                                actor: $actor,
                                verifiedAmount: isset($data['verified_amount']) ? (int) $data['verified_amount'] : null,
                                bankReference: filled($data['bank_reference'] ?? null) ? trim((string) $data['bank_reference']) : null,
                            );
                            Notification::make()->title('Bukti bayar disetujui & order lunas.')->success()->send();
                        } catch (Throwable $e) {
                            Notification::make()->title('Gagal menyetujui bukti bayar.')->body($e->getMessage())->danger()->send();
                        }
                    }),
                Action::make('rejectProof')
                    ->label('Tolak')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (OrderPaymentProof $record): bool => $record->status === OrderPaymentProof::STATUS_PENDING
                        && ($this->actor()->isAdmin() || Gate::allows('orders-mark-paid')))
                    ->modalHeading('Tolak Bukti Transfer')
                    ->schema([
                        Textarea::make('reason')
                            ->label('Alasan Penolakan')
                            ->rows(2)
                            ->required()
                            ->default('Mutasi belum ditemukan / nominal tidak sesuai.'),
                    ])
                    ->action(function (OrderPaymentProof $record, array $data): void {
                        $actor = $this->actor();
                        abort_unless($actor->isAdmin() || Gate::allows('orders-mark-paid'), 403);

                        resolve(VerifyPaymentProof::class)->reject(
                            $record,
                            $actor,
                            trim((string) ($data['reason'] ?? 'Ditolak saat verifikasi mutasi.')),
                        );
                        Notification::make()->title('Bukti bayar ditolak.')->warning()->send();
                    }),
            ])
            ->emptyStateHeading('Belum ada bukti transfer manual')
            ->emptyStateDescription('Bukti transfer yang diunggah pelanggan pada metode transfer manual akan muncul di sini.');
    }

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

        $counts = OrderPaymentProof::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $tabs = [
            [
                'key' => 'pending',
                'label' => 'Menunggu',
                'badge' => (int) ($counts[OrderPaymentProof::STATUS_PENDING] ?? 0),
                'badgeColor' => 'warning',
            ],
            [
                'key' => 'approved',
                'label' => 'Disetujui',
                'badge' => (int) ($counts[OrderPaymentProof::STATUS_APPROVED] ?? 0),
                'badgeColor' => 'success',
            ],
            [
                'key' => 'rejected',
                'label' => 'Ditolak',
                'badge' => (int) ($counts[OrderPaymentProof::STATUS_REJECTED] ?? 0),
                'badgeColor' => 'danger',
            ],
            [
                'key' => 'all',
                'label' => 'Semua',
                'badge' => (int) $counts->sum(),
                'badgeColor' => 'gray',
            ],
        ];

        return view('shopper.livewire.pages.manual-payment-proofs', [
            'tabs' => $tabs,
        ])->title('Verifikasi Transfer Bank Manual');
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
