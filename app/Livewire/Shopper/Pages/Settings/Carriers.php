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
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\Setting;
use Mckenziearts\Icons\Untitledui\Enums\Untitledui;
use Shopper\Shipping\Facades\Shipping;
use Shopper\Shipping\Services\CarrierRateService;

class Carriers extends \Shopper\Livewire\Pages\Settings\Carriers
{
    public function exception(\Throwable $e, callable $stopPropagation): void
    {
        if ($e instanceof \Exception) {
            parent::exception($e, $stopPropagation);
        }
    }

    public function switchProviderAction(): Action
    {
        $families = ActiveProvider::knownFamilies(OperatorSwitch::SHIPPING);
        $options = ['none' => 'Manual / Zona Pengiriman Saja'];
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
            ->modalHeading('Pilih Penyedia Pengiriman Aktif')
            ->modalDescription('Hanya satu keluarga penyedia logistik yang aktif melayani tarif checkout pelanggan pada satu waktu.')
            ->schema([
                Select::make('provider')
                    ->label('Penyedia Pengiriman')
                    ->options($options)
                    ->default(ActiveProvider::setting(OperatorSwitch::SHIPPING) ?? ActiveProvider::family(OperatorSwitch::SHIPPING) ?? 'none')
                    ->required(),
            ])
            ->action(function (array $data): void {
                $provider = strtolower(trim((string) ($data['provider'] ?? 'none')));
                Setting::query()->updateOrCreate(
                    ['key' => 'active_shipping_provider'],
                    [
                        'display_name' => 'Active Shipping Provider',
                        'value' => $provider === '' ? 'none' : $provider,
                        'locked' => true,
                    ],
                );
                Cache::forget('shopper-setting.active_shipping_provider');

                Notification::make()
                    ->title('Penyedia pengiriman aktif diperbarui.')
                    ->success()
                    ->send();
            });
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Carrier::query()->latest())
            ->headerActions([
                $this->switchProviderAction(),
            ])
            ->columns([
                ImageColumn::make('logo')
                    ->label(__('shopper::forms.label.logo'))
                    ->circular()
                    ->getStateUsing(static fn (Carrier $record): ?string => resolve(CarrierRateService::class)->getLogoUrl($record))
                    ->defaultImageUrl(shopper_fallback_url()),
                TextColumn::make('name')
                    ->label(__('shopper::forms.label.name'))
                    ->sortable()
                    ->searchable(),
                TextColumn::make('driver')
                    ->label(__('shopper::forms.label.driver'))
                    ->badge()
                    ->formatStateUsing(static fn (?string $state): string => $state ? Shipping::driver($state)->name() : __('shopper::words.manual'))
                    ->color(static fn (?string $state, Carrier $record): string => match (true) {
                        $state === null || $state === 'manual' => 'gray',
                        resolve(CarrierRateService::class)->isDriverConfigured($record) => 'success',
                        default => 'warning',
                    }),
                TextColumn::make('provider_status')
                    ->label('Status Penyedia')
                    ->badge()
                    ->state(static function (Carrier $record): string {
                        $driver = $record->driver ?: 'manual';

                        return ActiveProvider::allows(OperatorSwitch::SHIPPING, $driver) ? 'Aktif' : 'Standby';
                    })
                    ->color(static fn (string $state): string => $state === 'Aktif' ? 'success' : 'warning'),
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
                    ->schema($this->getCarrierFormSchema())
                    ->mutateRecordDataUsing(static function (array $data): array {
                        if (is_string($data['metadata'] ?? null)) {
                            $decoded = json_decode($data['metadata'], true);
                            $data['metadata'] = is_array($decoded) ? $decoded : [];
                        } elseif (! is_array($data['metadata'] ?? null)) {
                            $data['metadata'] = [];
                        }

                        return $data;
                    }),
                DeleteAction::make('delete')
                    ->label(__('shopper::forms.actions.delete'))
                    ->icon(Untitledui::Trash03)
                    ->authorize('access_setting')
                    ->iconButton(),
            ])
            ->emptyStateIcon(Untitledui::Truck);
    }

    protected function getCarrierFormSchema(): array
    {
        return array_merge(parent::getCarrierFormSchema(), [
            KeyValue::make('metadata')
                ->label('Metadata Kurir')
                ->keyLabel('Kunci (mis. courier_code)')
                ->valueLabel('Nilai')
                ->default([]),
        ]);
    }
}
