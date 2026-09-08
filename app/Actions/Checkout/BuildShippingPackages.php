<?php

declare(strict_types=1);

namespace App\Actions\Checkout;

use Illuminate\Database\Eloquent\Relations\Relation;
use Shopper\Cart\Models\CartLine;
use Shopper\Core\Enum\Dimension\Weight;
use Shopper\Shipping\DataTransferObjects\Package;

final class BuildShippingPackages
{
    /**
     * @return array<int, Package>
     */
    public function handle(): array
    {
        $cart = cartSession();
        $cart->load('lines.purchasable');

        $packages = [];

        /** @var CartLine $line */
        foreach ($cart->lines as $line) {
            $model = $line->purchasable;

            if (! $model) {
                continue;
            }

            if (method_exists($model, 'product')) {
                $model->loadMissing('product');
            }

            for ($i = 0; $i < $line->quantity; $i++) {
                $packages[] = new Package(
                    length: $this->dimensionValue($model, 'depth_value', 10.0),
                    width: $this->dimensionValue($model, 'width_value', 10.0),
                    height: $this->dimensionValue($model, 'height_value', 10.0),
                    weight: $this->packageWeight($model),
                    unit: $this->packageUnit($model),
                );
            }
        }

        return $packages ?: [new Package(length: 10.0, width: 10.0, height: 10.0, weight: 1.0)];
    }

    /**
     * Build packages from an explicit set of allocated lines (per-shipment).
     *
     * @param  list<array{purchasable_type: string, purchasable_id: int, qty: int}>  $lines
     * @return array<int, Package>
     */
    public function handleFromLines(array $lines): array
    {
        $packages = [];

        foreach ($lines as $line) {
            $morphType = $line['purchasable_type'];
            /** @var class-string $type */
            $type = Relation::getMorphedModel($morphType) ?? $morphType;
            $model = app($type)->newQuery()->find($line['purchasable_id']);

            if (! $model) {
                continue;
            }

            if (method_exists($model, 'product')) {
                $model->loadMissing('product');
            }

            for ($i = 0; $i < (int) $line['qty']; $i++) {
                $packages[] = new Package(
                    length: $this->dimensionValue($model, 'depth_value', 10.0),
                    width: $this->dimensionValue($model, 'width_value', 10.0),
                    height: $this->dimensionValue($model, 'height_value', 10.0),
                    weight: $this->packageWeight($model),
                    unit: $this->packageUnit($model),
                );
            }
        }

        return $packages ?: [new Package(length: 10.0, width: 10.0, height: 10.0, weight: 1.0)];
    }

    private function packageWeight(object $model): float
    {
        $value = (float) ($model->weight_value ?? 0);
        $unit = $model->weight_unit ?? null;

        if ($value <= 0 && (method_exists($model, 'product') || property_exists($model, 'product')) && $model->product) {
            $value = (float) ($model->product->weight_value ?? 0);
            $unit = $model->product->weight_unit ?? null;
        }

        if ($value <= 0) {
            $value = 1.0;
            $unit = Weight::KG;
        }

        if ($unit instanceof Weight) {
            $unit = $unit->value;
        }

        $unitStr = strtolower(trim((string) ($unit ?: Weight::KG->value)));

        return match ($unitStr) {
            Weight::G->value => max($value / 1000, 0.001),
            Weight::LBS->value => max($value * 0.45359237, 0.001),
            default => max($value, 0.001),
        };
    }

    private function dimensionValue(object $model, string $attribute, float $fallback = 10.0): float
    {
        $val = (float) ($model->{$attribute} ?? 0);

        if ($val <= 0 && (method_exists($model, 'product') || property_exists($model, 'product'))) {
            $val = (float) ($model->product?->{$attribute} ?? 0);
        }

        return $val > 0 ? $val : $fallback;
    }

    private function packageUnit(object $model): string
    {
        return $this->weightUnit($model) === Weight::LBS->value ? 'imperial' : 'metric';
    }

    private function weightUnit(object $model): string
    {
        $value = (float) ($model->weight_value ?? 0);
        $unit = $model->weight_unit ?? null;

        if ($value <= 0 && (method_exists($model, 'product') || property_exists($model, 'product')) && $model->product) {
            $unit = $model->product->weight_unit ?? null;
        }

        $unit = $unit ?? Weight::KG;

        if ($unit instanceof Weight) {
            return $unit->value;
        }

        return strtolower((string) $unit ?: Weight::KG->value);
    }
}
