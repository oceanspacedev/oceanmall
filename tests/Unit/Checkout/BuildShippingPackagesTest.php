<?php

declare(strict_types=1);

namespace Tests\Unit\Checkout;

use App\Actions\Checkout\BuildShippingPackages;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Shopper\Core\Enum\Dimension\Weight;
use Tests\TestCase;

final class BuildShippingPackagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_variant_inherits_weight_and_dimensions_from_parent_product(): void
    {
        $product = Product::factory()->standard()->create([
            'name' => 'Realme C67 Test',
            'weight_value' => 1.5,
            'weight_unit' => Weight::KG,
            'depth_value' => 16.0,
            'width_value' => 8.0,
            'height_value' => 2.0,
        ]);

        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'name' => 'Hijau / 256 GB',
            'sku' => 'RMC67-GRN-256',
            'weight_value' => 0.0,
        ]);

        $builder = resolve(BuildShippingPackages::class);

        $packages = $builder->handleFromLines([
            [
                'purchasable_type' => $variant->getMorphClass(),
                'purchasable_id' => $variant->id,
                'qty' => 3,
            ],
        ]);

        $this->assertCount(3, $packages);
        foreach ($packages as $pkg) {
            $this->assertSame(1.5, $pkg->weight);
            $this->assertSame(16.0, $pkg->length);
            $this->assertSame(8.0, $pkg->width);
            $this->assertSame(2.0, $pkg->height);
        }
    }

    public function test_variant_uses_own_weight_when_explicitly_defined(): void
    {
        $product = Product::factory()->standard()->create([
            'name' => 'Realme C67 Test',
            'weight_value' => 1.5,
            'weight_unit' => Weight::KG,
        ]);

        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'name' => 'Bundle Box Spesial',
            'sku' => 'RMC67-BNDL',
            'weight_value' => 2.8,
            'weight_unit' => Weight::KG,
        ]);

        $builder = resolve(BuildShippingPackages::class);

        $packages = $builder->handleFromLines([
            [
                'purchasable_type' => $variant->getMorphClass(),
                'purchasable_id' => $variant->id,
                'qty' => 1,
            ],
        ]);

        $this->assertCount(1, $packages);
        $this->assertSame(2.8, $packages[0]->weight);
    }

    public function test_variant_inherits_gram_weight_unit_from_parent_product(): void
    {
        $product = Product::factory()->standard()->create([
            'name' => 'Realme C67 Gram Test',
            'weight_value' => 500.0,
            'weight_unit' => Weight::G,
        ]);

        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'name' => 'Hijau / 128 GB',
            'sku' => 'RMC67-GRN-128',
            'weight_value' => 0.0,
            'weight_unit' => Weight::KG, // default in DB
        ]);

        $builder = resolve(BuildShippingPackages::class);

        $packages = $builder->handleFromLines([
            [
                'purchasable_type' => $variant->getMorphClass(),
                'purchasable_id' => $variant->id,
                'qty' => 1,
            ],
        ]);

        $this->assertCount(1, $packages);
        $this->assertSame(0.5, $packages[0]->weight);
    }
}
