<?php

declare(strict_types=1);

namespace App\Livewire\Shopper\Pages\Settings;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Cache;
use Shopper\Components\Section;
use Shopper\Components\Separator;
use Shopper\Core\Models\Setting;

class General extends \Shopper\Livewire\Pages\Settings\General
{
    public function mount(): void
    {
        parent::mount();

        $taxSettings = Setting::query()
            ->whereIn('key', ['is_pkp', 'company_npwp', 'pkp_name', 'pkp_address', 'invoice_prefix'])
            ->pluck('value', 'key')
            ->toArray();

        $this->data = array_merge($this->data ?? [], [
            'is_pkp' => (bool) ($taxSettings['is_pkp'] ?? false),
            'company_npwp' => $taxSettings['company_npwp'] ?? '',
            'pkp_name' => $taxSettings['pkp_name'] ?? '',
            'pkp_address' => $taxSettings['pkp_address'] ?? '',
            'invoice_prefix' => $taxSettings['invoice_prefix'] ?? 'INV',
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $schema = parent::form($schema);
        $components = $schema->getComponents();

        $components[] = Separator::make();
        $components[] = Section::make('Pajak PKP & Penomoran Invoice')
            ->aside()
            ->compact()
            ->description('Konfigurasi NPWP PKP, alamat faktur pajak, dan prefiks nomor invoice berurutan.')
            ->extraAttributes(['class' => 'sh-section-aside'])
            ->schema([
                Toggle::make('is_pkp')
                    ->label('Perusahaan Berstatus PKP (PPN 11%)')
                    ->helperText('Tampilkan NPWP dan rincian DPP + PPN 11% pada invoice & laporan pajak.'),
                TextInput::make('company_npwp')
                    ->label('NPWP Perusahaan / PKP')
                    ->placeholder('01.234.567.8-901.000')
                    ->maxLength(64),
                TextInput::make('pkp_name')
                    ->label('Nama Badan Usaha PKP')
                    ->maxLength(255),
                Textarea::make('pkp_address')
                    ->label('Alamat Terdaftar Faktur Pajak')
                    ->rows(2)
                    ->maxLength(500),
                TextInput::make('invoice_prefix')
                    ->label('Prefiks Nomor Invoice')
                    ->placeholder('INV')
                    ->default('INV')
                    ->maxLength(16),
            ]);

        return $schema->components($components);
    }

    public function store(): void
    {
        $this->authorize('access_setting');

        $state = $this->form->getState();
        $taxKeys = ['is_pkp', 'company_npwp', 'pkp_name', 'pkp_address', 'invoice_prefix'];
        $baseState = array_diff_key($state, array_flip($taxKeys));

        $this->saveSettings($baseState);

        foreach ($taxKeys as $key) {
            $value = $state[$key] ?? null;
            if ($key === 'is_pkp') {
                $value = $value ? 1 : 0;
            } elseif ($key === 'invoice_prefix') {
                $cleaned = strtoupper((string) preg_replace('/[^A-Za-z0-9\-]/', '', trim((string) $value)));
                $value = $cleaned !== '' ? $cleaned : 'INV';
            }

            Setting::query()->updateOrCreate(
                ['key' => $key],
                [
                    'display_name' => ucwords(str_replace('_', ' ', $key)),
                    'value' => $value,
                    'locked' => true,
                ],
            );
            Cache::forget("shopper-setting.{$key}");
        }

        parent::store();
    }
}
