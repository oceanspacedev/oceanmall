<?php

declare(strict_types=1);

namespace Tests\Feature\Shop;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class HomePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_latest_rail_shows_newest_published_products_first_without_any_flag(): void
    {
        $older = Product::factory()->standard()->create([
            'slug' => 'older',
            'featured' => false,
            'published_at' => now()->subDays(3),
        ]);
        $newest = Product::factory()->standard()->create([
            'slug' => 'newest',
            'featured' => false,
            'published_at' => now()->subHour(),
        ]);
        Product::factory()->standard()->create([
            'slug' => 'scheduled',
            'published_at' => now()->addDays(2),
        ]);
        Product::factory()->standard()->create([
            'slug' => 'hidden',
            'is_visible' => false,
            'published_at' => now()->subDay(),
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('shop/home')
                ->has('latestProducts', 2)
                ->where('latestProducts.0.slug', $newest->slug)
                ->where('latestProducts.1.slug', $older->slug));
    }

    public function test_featured_rail_lists_recently_updated_featured_products_first(): void
    {
        $first = Product::factory()->standard()->create(['slug' => 'featured-a', 'featured' => true]);
        $second = Product::factory()->standard()->create(['slug' => 'featured-b', 'featured' => true]);
        Product::factory()->standard()->create(['slug' => 'plain', 'featured' => false]);

        $first->forceFill(['updated_at' => now()->subWeek()])->saveQuietly();
        $second->forceFill(['updated_at' => now()])->saveQuietly();

        $this->get(route('home'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('featuredProducts', 2)
                ->where('featuredProducts.0.slug', 'featured-b')
                ->where('featuredProducts.1.slug', 'featured-a'));
    }

    public function test_home_hides_top_level_categories_without_published_products(): void
    {
        $stocked = Category::query()->create(['name' => 'Handphone', 'slug' => 'handphone', 'is_enabled' => true]);
        Category::query()->create(['name' => 'Tablet', 'slug' => 'tablet', 'is_enabled' => true]);
        Category::query()->create(['name' => 'Hidden', 'slug' => 'hidden', 'is_enabled' => false]);

        $product = Product::factory()->standard()->create(['slug' => 'phone-x']);
        $product->categories()->attach($stocked);

        $this->get(route('home'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('categories', 1)
                ->where('categories.0.slug', 'handphone'));
    }
}
