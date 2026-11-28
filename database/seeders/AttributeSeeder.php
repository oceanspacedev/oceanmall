<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Support\Facades\DB;
use Shopper\Core\Models\Attribute;
use Shopper\Core\Models\AttributeValue;

class AttributeSeeder extends AbstractSeeder
{
    public function run(): void
    {
        $attributes = $this->getSeedData('attributes');

        $this->command->warn(PHP_EOL.'Creating attributes...');

        DB::transaction(function () use ($attributes): void {
            foreach ($attributes as $attribute) {
                $attributeModel = Attribute::query()->updateOrCreate(
                    ['slug' => $attribute->slug],
                    [
                        'name' => $attribute->name,
                        'description' => $attribute->description,
                        'type' => $attribute->type,
                        'icon' => $attribute->icon,
                        'is_enabled' => $attribute->is_enabled,
                        'is_searchable' => $attribute->is_searchable,
                        'is_filterable' => $attribute->is_filterable,
                    ],
                );

                foreach ($attribute->values as $value) {
                    AttributeValue::query()->updateOrCreate(
                        ['attribute_id' => $attributeModel->id, 'key' => $value->key],
                        ['value' => $value->value, 'position' => $value->position],
                    );
                }
            }
        });

        $this->command->info('Attributes created successfully.');
    }
}
