<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Collection;
use App\Models\Product;
use Database\Seeders\AttributeSeeder;
use Database\Seeders\BrandSeeder;
use Database\Seeders\CategorySeeder;
use Database\Seeders\CollectionSeeder;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Shopper\Core\Models\Currency;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Price;
use Tests\TestCase;

final class CatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    private const array SEEDERS = [
        BrandSeeder::class,
        CategorySeeder::class,
        CollectionSeeder::class,
        AttributeSeeder::class,
        ProductSeeder::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        // Image conversions are queued; the test only checks the stored originals.
        Queue::fake();

        Currency::query()->firstOrCreate(
            ['code' => 'IDR'],
            ['name' => 'Rupiah', 'symbol' => 'Rp', 'format' => '1,234.56'],
        );
        Inventory::factory()->create(['is_default' => true]);
    }

    public function test_catalog_only_holds_minimo_rapa_and_oraimo_products_with_images_and_prices(): void
    {
        $this->seed(self::SEEDERS);

        $this->assertSame(['Minimo', 'Rapa', 'Oraimo'], Brand::query()->orderBy('position')->pluck('name')->all());

        $seed = collect(json_decode((string) file_get_contents(database_path('seeders/data/products.json')), true));
        $products = Product::query()->with(['brand', 'media', 'prices', 'variants.prices', 'categories'])->get();

        $this->assertCount($seed->count(), $products);
        $this->assertSame(
            $seed->countBy('brand_slug')->sortKeys()->all(),
            $products->countBy(fn (Product $product): string => $product->brand->slug)->sortKeys()->all(),
        );
        $this->assertSame(['minimo', 'oraimo', 'rapa'], $products->pluck('brand.slug')->unique()->sort()->values()->all());

        foreach ($products as $product) {
            $this->assertNotNull($product->published_at, "{$product->slug} is not published");
            $this->assertTrue(
                $product->media->contains('collection_name', config('shopper.media.storage.thumbnail_collection', 'thumbnail')),
                "{$product->slug} has no thumbnail",
            );
            $this->assertTrue($product->categories->isNotEmpty(), "{$product->slug} has no category");

            $prices = $product->prices->isNotEmpty()
                ? $product->prices
                : $product->variants->flatMap->prices;

            $this->assertTrue($prices->isNotEmpty(), "{$product->slug} has no price");
        }

        // IDR is zero-decimal: amounts are stored as whole Rupiah, exactly as in the seed file.
        $standard = $seed->firstWhere('type', 'standard');
        $this->assertSame($standard['prices'][0]['amount'], (int) Price::query()
            ->where('priceable_id', Product::query()->where('slug', $standard['slug'])->value('id'))
            ->where('priceable_type', (new Product)->getMorphClass())
            ->value('amount'));

        // Child categories use Shopper's "parent-child" slugs, so products attach to them.
        $this->assertSame(
            $seed->filter(fn (array $p): bool => in_array('aksesoris-kabel-data', $p['categories'], true))->count(),
            Category::query()->where('slug', 'aksesoris-kabel-data')->firstOrFail()->products()->count(),
        );

        // Reviews only come from real customers: there is no review seeder.
        $this->assertFalse(class_exists('Database\\Seeders\\ReviewSeeder'));
        $this->assertSame(0, DB::table(shopper_table('reviews'))->count());
    }

    public function test_catalog_seeders_are_idempotent(): void
    {
        $this->seed(self::SEEDERS);

        $counts = fn (): array => [
            'brands' => Brand::query()->count(),
            'categories' => Category::query()->count(),
            'collections' => Collection::query()->count(),
            'products' => Product::query()->count(),
            'prices' => Price::query()->count(),
            'media' => \DB::table('media')->count(),
        ];

        $first = $counts();

        $this->seed(self::SEEDERS);

        $this->assertSame($first, $counts());
    }
}
