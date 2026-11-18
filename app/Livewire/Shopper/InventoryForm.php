<?php

declare(strict_types=1);

namespace App\Livewire\Shopper;

use App\Observers\InventoryObserver;
use App\Shipping\Contracts\ResolvesDestinations;
use App\Shipping\Contracts\ValidatesOriginIds;
use App\Shipping\InventoryOrigins;
use App\Shipping\ShippingCapabilities;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Shopper\Components\Section;
use Shopper\Components\Separator;
use Shopper\Livewire\Components\Settings\Locations\InventoryForm as ShopperInventoryForm;
use Shopper\Shipping\Contracts\ShippingDriver;

/**
 * Shopper location form plus the warehouse shipping origin: the coordinates
 * Shopper stores but does not expose (always), and one origin ID per
 * configured shipping driver that needs one (ShippingCapabilities::originDrivers():
 * ResolvesDestinations, RequiresOriginId or ValidatesOriginIds). Origins live in inventory_shipping_origins; the
 * form state `shipping_origins.{driver}` is saved by InventoryObserver.
 */
final class InventoryForm extends ShopperInventoryForm
{
    /** Origin id rules for drivers that do not implement ValidatesOriginIds. */
    public const DEFAULT_ORIGIN_RULES = ['nullable', 'string', 'max:100'];

    public function mount(): void
    {
        $this->form->fill([
            ...$this->inventory->toArray(),
            InventoryObserver::ATTRIBUTE => $this->inventory->exists
                ? resolve(InventoryOrigins::class)->allFor($this->inventory)
                : [],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $schema = parent::form($schema);

        $originFields = resolve(ShippingCapabilities::class)
            ->originDrivers()
            ->map(static fn (ShippingDriver $driver, string $code): TextInput => TextInput::make(InventoryObserver::ATTRIBUTE.'.'.$code)
                ->label(__('shopper::pages/settings/global.location.shipping_origin_id', ['provider' => $driver->name()]))
                ->helperText(__('shopper::pages/settings/global.location.shipping_origin_helper', ['provider' => $driver->name()]))
                // The driver knows its id format (numeric RajaOngkir ids,
                // string area ids...); any string otherwise.
                ->rules($driver instanceof ValidatesOriginIds
                    ? $driver->originIdRules()
                    : self::DEFAULT_ORIGIN_RULES))
            ->values()
            ->all();

        return $schema->components([
            ...$schema->getComponents(),
            Separator::make(),
            Section::make(__('shopper::pages/settings/global.location.shipping_origin'))
                ->aside()
                ->compact()
                ->description(__('shopper::pages/settings/global.location.shipping_origin_summary'))
                ->extraAttributes(['class' => 'sh-section-aside'])
                ->schema([
                    ...$originFields,
                    TextInput::make('latitude')
                        ->label(__('shopper::forms.label.latitude'))
                        ->helperText(__('shopper::pages/settings/global.location.latitude_helper'))
                        ->numeric()
                        ->rules(['nullable', 'numeric', 'between:-90,90']),
                    TextInput::make('longitude')
                        ->label(__('shopper::forms.label.longitude'))
                        ->helperText(__('shopper::pages/settings/global.location.longitude_helper'))
                        ->numeric()
                        ->rules(['nullable', 'numeric', 'between:-180,180']),
                ]),
        ]);
    }
}
