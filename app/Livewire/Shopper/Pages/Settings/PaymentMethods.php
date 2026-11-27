<?php

declare(strict_types=1);

namespace App\Livewire\Shopper\Pages\Settings;

use App\Support\ActiveProvider;
use App\Support\OperatorSwitch;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Mckenziearts\Icons\Untitledui\Enums\Untitledui;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Core\Models\Setting;
use Shopper\Payment\Facades\Payment;
use Shopper\Payment\Services\PaymentProcessingService;
use Throwable;

class PaymentMethods extends \Shopper\Livewire\Pages\Settings\PaymentMethods
{
    public function exception(Throwable $e, callable $stopPropagation): void
    {
        if ($e instanceof \Exception) {
            parent::exception($e, $stopPropagation);
        }
    }

    public function switchProviderAction(): Action
    {
        $families = ActiveProvider::knownFamilies(OperatorSwitch::PAYMENT);
        $options = ['none' => 'Manual / Nonaktifkan Penyedia Online'];
        foreach ($families as $family) {
            if ($family === 'manual') {
                continue;
            }
            $options[$family] = Str::headline($family);
        }

        return Action::make('switchProvider')
            ->label('Ganti Penyedia Aktif')
            ->color('gray')
            ->authorize('access_setting')
            ->modalHeading('Pilih Penyedia Pembayaran Aktif')
            ->modalDescription('Hanya satu keluarga penyedia pembayaran yang aktif melayani checkout pelanggan pada satu waktu.')
            ->schema([
                Select::make('provider')
                    ->label('Penyedia Pembayaran')
                    ->options($options)
                    ->default(ActiveProvider::setting(OperatorSwitch::PAYMENT) ?? ActiveProvider::family(OperatorSwitch::PAYMENT) ?? 'none')
                    ->required(),
            ])
            ->action(function (array $data): void {
                $provider = strtolower(trim((string) ($data['provider'] ?? 'none')));
                Setting::query()->updateOrCreate(
                    ['key' => 'active_payment_provider'],
                    [
                        'display_name' => 'Active Payment Provider',
                        'value' => $provider === '' ? 'none' : $provider,
                        'locked' => true,
                    ],
                );
                Cache::forget('shopper-setting.active_payment_provider');

                Notification::make()
                    ->title('Penyedia pembayaran aktif diperbarui.')
                    ->success()
                    ->send();
            });
    }

    public function createPaymentAction(): Action
    {
        return parent::createPaymentAction()
            ->mutateFormDataUsing(static fn (array $data): array => self::encodeMetadataField($data));
    }

    public function table(Table $table): Table
    {
        $selectedFamily = ActiveProvider::family(OperatorSwitch::PAYMENT);
        $isDriverReady = static function (?string $code): bool {
            $code = trim((string) $code);

            if ($code === '' || $code === 'manual') {
                return true;
            }

            if (! OperatorSwitch::switchedOn(OperatorSwitch::PAYMENT, $code)) {
                return false;
            }

            try {
                return Payment::isConfigured($code);
            } catch (Throwable) {
                return false;
            }
        };

        return $table
            ->query(PaymentMethod::query()->latest())
            ->headerActions([
                $this->switchProviderAction(),
            ])
            ->columns([
                ImageColumn::make('logo')
                    ->label(__('shopper::forms.label.logo'))
                    ->circular()
                    ->getStateUsing(static fn (PaymentMethod $record): ?string => resolve(PaymentProcessingService::class)->getLogoUrl($record))
                    ->defaultImageUrl(shopper_fallback_url()),
                TextColumn::make('title')
                    ->label(__('shopper::forms.label.title'))
                    ->sortable()
                    ->searchable(),
                TextColumn::make('driver')
                    ->label(__('shopper::forms.label.driver'))
                    ->badge()
                    ->formatStateUsing(static function (?string $state): string {
                        if (! $state) {
                            return __('shopper::words.manual');
                        }

                        try {
                            return Payment::driver($state)->name();
                        } catch (Throwable) {
                            return $state;
                        }
                    })
                    ->color(static fn (?string $state): string => match (true) {
                        $state === null || $state === 'manual' => 'gray',
                        $isDriverReady($state) => 'success',
                        default => 'warning',
                    }),
                TextColumn::make('provider_status')
                    ->label('Status Penyedia')
                    ->badge()
                    ->state(static function (PaymentMethod $record) use ($isDriverReady): string {
                        $driver = $record->driver ?: 'manual';

                        if ($driver === 'manual') {
                            return 'Aktif';
                        }

                        if (! $isDriverReady($driver)) {
                            return 'Nonaktif';
                        }

                        return ActiveProvider::allows(OperatorSwitch::PAYMENT, $driver) ? 'Aktif' : 'Standby';
                    })
                    ->color(static fn (string $state): string => match ($state) {
                        'Aktif' => 'success',
                        'Standby' => 'warning',
                        default => 'gray',
                    }),
                ToggleColumn::make('is_enabled')
                    ->label(__('shopper::forms.label.status'))
                    ->beforeStateUpdated(fn (): mixed => $this->authorize('access_setting')),
            ])
            ->recordActions([
                EditAction::make('edit')
                    ->label(__('shopper::forms.actions.edit'))
                    ->icon(Untitledui::Edit03)
                    ->iconButton()
                    ->authorize('access_setting')
                    ->modalWidth(Width::ExtraLarge)
                    ->schema($this->getPaymentFormSchema())
                    ->mutateRecordDataUsing(static function (array $data): array {
                        if (is_string($data['metadata'] ?? null)) {
                            $decoded = json_decode($data['metadata'], true);
                            $data['metadata'] = is_array($decoded) ? $decoded : [];
                        } elseif (! is_array($data['metadata'] ?? null)) {
                            $data['metadata'] = [];
                        }

                        return $data;
                    })
                    ->mutateFormDataUsing(static fn (array $data): array => self::encodeMetadataField($data)),
                DeleteAction::make('delete')
                    ->label(__('shopper::forms.actions.delete'))
                    ->icon(Untitledui::Trash03)
                    ->authorize('access_setting')
                    ->iconButton(),
            ])
            ->emptyStateIcon(Untitledui::CreditCard02)
            ->emptyStateHeading($selectedFamily ? 'Penyedia aktif: '.Str::headline($selectedFamily) : __('shopper::pages/settings/payments.no_method'));
    }

    protected function getPaymentFormSchema(): array
    {
        return array_merge(parent::getPaymentFormSchema(), [
            KeyValue::make('metadata')
                ->label('Metadata Kanal Pembayaran')
                ->keyLabel('Kunci (mis. channel_code, payment_type)')
                ->valueLabel('Nilai')
                ->default([]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected static function encodeMetadataField(array $data): array
    {
        if (array_key_exists('metadata', $data) && is_array($data['metadata'])) {
            $filtered = array_filter(
                $data['metadata'],
                static fn (mixed $v, mixed $k): bool => trim((string) $k) !== '' && $v !== null && $v !== '',
                ARRAY_FILTER_USE_BOTH,
            );
            $data['metadata'] = $filtered !== [] ? json_encode($filtered, JSON_THROW_ON_ERROR) : null;
        }

        return $data;
    }
}
