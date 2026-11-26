<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shop;

use App\Actions\Product\AddProductReviewAction;
use App\Actions\Product\CustomerPurchasedProduct;
use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Shopper\Core\Models\Review;

final class ProductReviewController extends Controller
{
    public function store(
        Request $request,
        Product $product,
        CustomerPurchasedProduct $purchased,
        AddProductReviewAction $addReview,
    ): RedirectResponse {
        abort_unless($product->isPublished(), 404);

        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'title' => ['nullable', 'string', 'max:120'],
            'content' => ['nullable', 'string', 'max:2000'],
            'is_recommended' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();

        if (! $purchased->handle($user, $product)) {
            return back()->withErrors([
                'review' => 'Hanya pembeli yang sudah menerima pesanan yang dapat menulis ulasan.',
            ]);
        }

        if ($purchased->alreadyReviewed($user, $product)) {
            return back()->withErrors([
                'review' => 'Kamu sudah menulis ulasan untuk produk ini.',
            ]);
        }

        $addReview->execute($product, [
            'rating' => (int) $data['rating'],
            'title' => $data['title'] ?? null,
            'content' => $data['content'] ?? null,
            'is_recommended' => (bool) ($data['is_recommended'] ?? ((int) $data['rating'] >= 3)),
        ], $user);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Ulasan terkirim dan menunggu moderasi.',
        ]);

        return back()->with('success', 'Ulasan terkirim dan menunggu moderasi.');
    }

    public function report(Request $request, Review $review): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:200'],
        ]);

        abort_unless($review->reviewrateable_type === (new Product)->getMorphClass(), 404);

        $userId = (int) $request->user()->getAuthIdentifier();

        DB::transaction(function () use ($review, $userId, $data): void {
            $inserted = DB::table('review_reports')->insertOrIgnore([
                'review_id' => $review->id,
                'user_id' => $userId,
                'reason' => $data['reason'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted > 0 && $review->approved) {
                $review->forceFill(['approved' => false])->save();
            }
        });

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Terima kasih, laporan ulasan sudah kami terima.',
        ]);

        return back()->with('success', 'Terima kasih, laporan ulasan sudah kami terima.');
    }
}
