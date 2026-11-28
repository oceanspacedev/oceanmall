<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

abstract class AbstractSeeder extends Seeder
{
    protected function getSeedData(string $file): Collection
    {
        return collect(json_decode(
            File::get(base_path("database/seeders/data/{$file}.json"))
        ));
    }

    protected function imagePath(string $folder, string $filename): string
    {
        return base_path("database/seeders/data/images/{$folder}/{$filename}");
    }

    /**
     * Replace a media collection with one seed image, so re-running a seeder
     * never stacks duplicate media.
     */
    protected function syncSeedMedia(object $model, string $folder, ?string $filename, string $collection): void
    {
        $this->syncSeedMediaFiles($model, $folder, $filename ? [$filename] : [], $collection);
    }

    /**
     * Replace a media collection with the given seed images (missing files are skipped).
     *
     * @param  array<int, string>  $filenames
     */
    protected function syncSeedMediaFiles(object $model, string $folder, array $filenames, string $collection): void
    {
        $model->clearMediaCollection($collection);

        foreach ($filenames as $filename) {
            $path = $this->imagePath($folder, $filename);

            if (! file_exists($path)) {
                continue;
            }

            $model->addMedia($path)
                ->preservingOriginal()
                ->toMediaCollection($collection);
        }
    }
}
