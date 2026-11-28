<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Support\Facades\DB;

class CategorySeeder extends AbstractSeeder
{
    public function run(): void
    {
        $categories = $this->getSeedData('categories');

        $thumbnailCollection = config('shopper.media.storage.thumbnail_collection', 'thumbnail');

        $this->command->warn(PHP_EOL.'Creating categories...');

        DB::transaction(function () use ($categories, $thumbnailCollection): void {
            foreach ($categories as $category) {
                $parent = $this->upsert($category, null, $thumbnailCollection);

                foreach ($category->children ?? [] as $child) {
                    $this->upsert($child, $parent->id, $thumbnailCollection);
                }
            }
        });

        $this->command->info('Categories created successfully.');
    }

    private function upsert(object $data, ?int $parentId, string $thumbnailCollection): Category
    {
        $category = Category::query()->updateOrCreate(
            ['slug' => $data->slug],
            [
                'name' => $data->name,
                'description' => $data->description,
                'is_enabled' => $data->is_enabled,
                'position' => $data->position,
                'parent_id' => $parentId,
            ],
        );

        $this->syncSeedMedia($category, 'categories', $data->image ?? null, $thumbnailCollection);

        return $category;
    }
}
