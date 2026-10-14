<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Shopper\Core\Models\Review;

/**
 * Lets a signed-in customer report a published product review as inappropriate.
 *
 * The review goes back to pending, so it leaves the public list at once and appears in
 * cpanel > Reviews, where a moderator deletes it or approves it again. Reporting needs an
 * account and counts once per customer and review, so it is not a way for anonymous visitors to
 * take reviews down.
 */
final class ReviewReportController extends Controller
{
    public function __invoke(Request $request, int $review): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $model = Review::query()->where('approved', true)->find($review);
        if (! $model instanceof Review) {
            // Already hidden (or never public): nothing left to report, and nothing to leak.
            return response()->json(['message' => 'Terima kasih, laporanmu sudah kami terima.']);
        }

        $userId = (int) $request->user()->getAuthIdentifier();

        DB::transaction(function () use ($model, $userId, $data): void {
            DB::table('review_reports')->insertOrIgnore([
                'review_id' => $model->id,
                'user_id' => $userId,
                'reason' => $data['reason'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $model->forceFill(['approved' => false])->save();
        });

        return response()->json(['message' => 'Terima kasih, laporanmu sudah kami terima.']);
    }
}
