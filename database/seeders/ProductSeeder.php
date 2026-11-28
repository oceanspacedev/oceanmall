<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Collection;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Shopper\Core\Models\Attribute;
use Shopper\Core\Models\AttributeValue;
use Shopper\Core\Models\Channel;
use Shopper\Core\Models\Currency;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\ProductTag;
use Shopper\Core\Models\ProductVariant;

/**
 * Seeds the Minimo, Rapa and Oraimo catalog from database/seeders/data/products.json
 * (+ variants.json, related-products.json). Safe to re-run: products and variants are
 * upserted by slug / SKU, relations and media are re-synced, and initial stock is only
 * recorded when a product or variant is created.
 */
class ProductSeeder extends AbstractSeeder
{
    public function run(): void
    {
        $products = $this->getSeedData('products');
        $variantsData = collect($this->getSeedData('variants'))
            ->keyBy('product_slug');
        $thumbnailCollection = config('shopper.media.storage.thumbnail_collection', 'thumbnail');
        $uploadsCollection = config('shopper.media.storage.collection_name', 'uploads');
        $defaultInventory = Inventory::query()->where('is_default', true)->first()
            ?? Inventory::query()->first();
        $defaultChannel = Channel::query()->where('is_default', true)->first()
            ?? Channel::query()->first();

        $this->command->warn(PHP_EOL.'Creating products...');

        DB::transaction(function () use ($products, $variantsData, $thumbnailCollection, $uploadsCollection, $defaultInventory, $defaultChannel): void {
            foreach ($products as $product) {
                $productModel = $this->upsertProduct($product);

                $this->syncRelations($productModel, $product, $defaultChannel);
                $this->syncMedia($productModel, $product, $thumbnailCollection, $uploadsCollection);
                $this->syncPrices($productModel, $product->prices ?? []);

                if ($productModel->wasRecentlyCreated && ($product->stock ?? 0) > 0 && $defaultInventory instanceof Inventory) {
                    $productModel->mutateStock(
                        inventoryId: $defaultInventory->id,
                        quantity: $product->stock,
                        oldQuantity: $product->stock,
                        event: 'Initial inventory',
                    );
                }

                $this->syncTags($productModel, $product->tags ?? []);
                $this->syncProductAttributes($productModel, $product->attributes ?? null);

                $variantEntry = $variantsData->get($product->slug);

                if ($variantEntry) {
                    $this->upsertVariants(
                        $productModel,
                        $variantEntry->variants,
                        $thumbnailCollection,
                        $uploadsCollection,
                        $defaultInventory,
                    );
                }
            }
        });

        $this->command->info('Products created successfully.');

        $this->attachRelatedProducts();
    }

    private function upsertProduct(object $product): Product
    {
        $model = Product::query()->updateOrCreate(
            ['slug' => $product->slug],
            [
                'name' => $product->name,
                'sku' => $product->sku,
                'barcode' => $product->barcode,
                'description' => $product->description,
                'summary' => $product->summary ?? null,
                'security_stock' => $product->security_stock ?? 0,
                'featured' => $product->featured ?? false,
                'is_visible' => $product->is_visible ?? true,
                'type' => $product->type,
                'seo_title' => $product->seo_title ?? null,
                'seo_description' => $product->seo_description ?? null,
                'weight_value' => $product->weight_value ?? 0,
                'weight_unit' => $product->weight_unit ?? 'kg',
                'height_value' => $product->height_value ?? 0,
                'width_value' => $product->width_value ?? 0,
                'depth_value' => $product->depth_value ?? 0,
                'brand_id' => Brand::query()->where('slug', $product->brand_slug)->value('id'),
            ],
        );

        if ($model->published_at === null) {
            $model->forceFill(['published_at' => now()])->save();
        }

        return $model;
    }

    private function syncRelations(Product $productModel, object $product, ?Channel $defaultChannel): void
    {
        $productModel->categories()->sync(
            Category::query()->whereIn('slug', $product->categories ?? [])->pluck('id'),
        );

        $productModel->collections()->sync(
            Collection::query()->whereIn('slug', $product->collections ?? [])->pluck('id'),
        );

        if ($defaultChannel instanceof Channel) {
            $productModel->channels()->syncWithoutDetaching([$defaultChannel->id]);
        }
    }

    private function syncMedia(Product|ProductVariant $model, object $data, string $thumbnailCollection, string $uploadsCollection): void
    {
        $this->syncSeedMedia($model, 'products', $data->thumbnail ?? null, $thumbnailCollection);
        $this->syncSeedMediaFiles($model, 'products', $data->images ?? [], $uploadsCollection);
    }

    /**
     * One price per currency; amounts are whole Rupiah (IDR is zero-decimal).
     *
     * @param  array<int, object>  $prices
     */
    private function syncPrices(Product|ProductVariant $model, array $prices): void
    {
        foreach ($prices as $price) {
            $currencyId = Currency::query()->where('code', $price->currency_code)->value('id');

            if ($currencyId) {
                $model->prices()->updateOrCreate(
                    ['currency_id' => $currencyId],
                    [
                        'amount' => $price->amount,
                        'compare_amount' => $price->compare_amount ?? null,
                        'cost_amount' => $price->cost_amount ?? null,
                    ],
                );
            }
        }
    }

    /**
     * @param  array<int, string>  $tags
     */
    private function syncTags(Product $productModel, array $tags): void
    {
        $tagIds = [];

        foreach ($tags as $tagSlug) {
            $tagIds[] = ProductTag::query()->firstOrCreate(
                ['slug' => $tagSlug],
                ['name' => str($tagSlug)->replace('-', ' ')->title()->toString()],
            )->id;
        }

        $productModel->tags()->sync($tagIds);
    }

    private function syncProductAttributes(Product $productModel, ?object $attributes): void
    {
        $table = shopper_table('attribute_product');

        DB::table($table)->where('product_id', $productModel->id)->delete();

        foreach ($attributes ?? [] as $attributeSlug => $valueKeys) {
            $attribute = Attribute::query()->where('slug', $attributeSlug)->first();

            if (! $attribute) {
                continue;
            }

            foreach ($valueKeys as $valueKey) {
                DB::table($table)->insert([
                    'product_id' => $productModel->id,
                    'attribute_id' => $attribute->id,
                    'attribute_value_id' => AttributeValue::query()
                        ->where('attribute_id', $attribute->id)
                        ->where('key', $valueKey)
                        ->value('id'),
                    'attribute_custom_value' => null,
                ]);
            }
        }
    }

    /**
     * @param  array<int, object>  $variants
     */
    private function upsertVariants(
        Product $productModel,
        array $variants,
        string $thumbnailCollection,
        string $uploadsCollection,
        ?Inventory $defaultInventory,
    ): void {
        foreach ($variants as $variant) {
            /** @var ProductVariant $variantModel */
            $variantModel = $productModel->variants()->updateOrCreate(
                ['sku' => $variant->sku],
                [
                    'name' => $variant->name,
                    'barcode' => $variant->barcode ?? null,
                    'ean' => $variant->ean ?? null,
                    'upc' => $variant->upc ?? null,
                    'allow_backorder' => $variant->allow_backorder ?? false,
                    'position' => $variant->position ?? 1,
                ],
            );

            $variantModel->values()->sync($this->variantValueIds($variant->attributes ?? null));

            $this->syncPrices($variantModel, $variant->prices ?? []);

            if ($variantModel->wasRecentlyCreated && ($variant->stock ?? 0) > 0 && $defaultInventory instanceof Inventory) {
                $variantModel->mutateStock(
                    inventoryId: $defaultInventory->id,
                    quantity: $variant->stock,
                    oldQuantity: $variant->stock,
                    event: 'Initial inventory',
                );
            }

            $this->syncMedia($variantModel, $variant, $thumbnailCollection, $uploadsCollection);
        }
    }

    /**
     * @return array<int, int>
     */
    private function variantValueIds(?object $attributes): array
    {
        $ids = [];

        foreach ($attributes ?? [] as $attributeSlug => $valueKey) {
            $valueId = AttributeValue::query()
                ->whereHas('attribute', fn ($q) => $q->where('slug', $attributeSlug))
                ->where('key', $valueKey)
                ->value('id');

            if ($valueId) {
                $ids[] = $valueId;
            }
        }

        return $ids;
    }

    private function attachRelatedProducts(): void
    {
        $relatedData = $this->getSeedData('related-products');

        $this->command->warn(PHP_EOL.'Attaching related products...');

        foreach ($relatedData as $entry) {
            $product = Product::query()->where('slug', $entry->product_slug)->first();

            if (! $product) {
                continue;
            }

            $product->relatedProducts()->sync(
                Product::query()->whereIn('slug', $entry->related_slugs)->pluck('id'),
            );
        }

        $this->command->info('Related products attached successfully.');
    }
}
