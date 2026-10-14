<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Shopper\Core\Models\Review;
use Tests\TestCase;

final class ReviewReportApiTest extends TestCase
{
    use RefreshDatabase;

    private function publishedReview(User $author, Product $product, bool $approved = true): Review
    {
        return Review::query()->create([
            'rating' => 1,
            'title' => 'Kasar',
            'content' => 'Isi ulasan',
            'approved' => $approved,
            'is_recommended' => false,
            'reviewrateable_type' => $product->getMorphClass(),
            'reviewrateable_id' => $product->id,
            'author_type' => $author->getMorphClass(),
            'author_id' => $author->id,
        ]);
    }

    public function test_signed_in_customer_reports_a_review_and_it_leaves_the_public_list(): void
    {
        $product = Product::factory()->standard()->create(['slug' => 'dilaporkan', 'published_at' => now()->subDay()]);
        $author = User::factory()->create(['first_name' => 'Budi', 'last_name' => 'Santoso']);
        $review = $this->publishedReview($author, $product);

        $this->getJson('/api/v1/catalog/products/dilaporkan')
            ->assertOk()
            ->assertJsonPath('data.reviews.count', 1)
            ->assertJsonPath('data.reviews.items.0.author_name', 'Budi S.');

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/v1/catalog/reviews/{$review->id}/report", ['reason' => 'Kata kasar'])
            ->assertOk();

        $this->assertFalse((bool) $review->fresh()->approved);
        $this->assertDatabaseHas('review_reports', ['review_id' => $review->id, 'reason' => 'Kata kasar']);

        $this->getJson('/api/v1/catalog/products/dilaporkan')
            ->assertOk()
            ->assertJsonPath('data.reviews.count', 0);
    }

    public function test_reporting_twice_is_harmless(): void
    {
        $product = Product::factory()->standard()->create(['published_at' => now()->subDay()]);
        $review = $this->publishedReview(User::factory()->create(), $product);

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/v1/catalog/reviews/{$review->id}/report")->assertOk();
        $this->postJson("/api/v1/catalog/reviews/{$review->id}/report")->assertOk();

        $this->assertSame(1, DB::table('review_reports')->where('review_id', $review->id)->count());
    }

    public function test_a_pending_or_missing_review_reveals_nothing(): void
    {
        $product = Product::factory()->standard()->create(['published_at' => now()->subDay()]);
        $pending = $this->publishedReview(User::factory()->create(), $product, approved: false);

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/v1/catalog/reviews/{$pending->id}/report")->assertOk();
        $this->postJson('/api/v1/catalog/reviews/999999/report')->assertOk();

        $this->assertSame(0, DB::table('review_reports')->count());
    }

    public function test_reporting_needs_an_account(): void
    {
        $product = Product::factory()->standard()->create(['published_at' => now()->subDay()]);
        $review = $this->publishedReview(User::factory()->create(), $product);

        $this->postJson("/api/v1/catalog/reviews/{$review->id}/report")->assertUnauthorized();
        $this->assertTrue((bool) $review->fresh()->approved);
    }

    public function test_reviewer_without_a_last_name_is_shown_by_first_name_only(): void
    {
        $product = Product::factory()->standard()->create(['slug' => 'tanpa-nama-belakang', 'published_at' => now()->subDay()]);
        $author = User::factory()->create(['first_name' => 'Wulan', 'last_name' => '']);
        $this->publishedReview($author, $product);

        $this->getJson('/api/v1/catalog/products/tanpa-nama-belakang')
            ->assertJsonPath('data.reviews.items.0.author_name', 'Wulan');
    }
}
