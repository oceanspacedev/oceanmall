<?php

declare(strict_types=1);

namespace App\Livewire\Shopper\SlideOvers;

use Carbon\Carbon;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Shopper\Livewire\SlideOvers\ReviewDetail as ShopperReviewDetail;

final class ReviewDetail extends ShopperReviewDetail
{
    public function dismissReports(): void
    {
        $this->authorize('edit_reviews');

        DB::table('review_reports')
            ->where('review_id', $this->review->id)
            ->delete();

        Notification::make()
            ->title('Daftar laporan ulasan dibersihkan.')
            ->success()
            ->send();
    }

    public function render(): View
    {
        $reports = DB::table('review_reports')
            ->leftJoin('users', 'users.id', '=', 'review_reports.user_id')
            ->where('review_reports.review_id', $this->review->id)
            ->orderByDesc('review_reports.created_at')
            ->get([
                'review_reports.id',
                'review_reports.reason',
                'review_reports.created_at',
                'users.first_name',
                'users.last_name',
                'users.email',
            ])
            ->map(static function (object $row): array {
                $name = trim(implode(' ', array_filter([
                    $row->first_name ?? null,
                    $row->last_name ?? null,
                ], static fn (mixed $v): bool => is_string($v) && trim($v) !== '')));

                return [
                    'id' => (int) $row->id,
                    'reporter' => $name !== '' ? $name : ((string) ($row->email ?? 'Pelanggan')),
                    'email' => (string) ($row->email ?? ''),
                    'reason' => is_string($row->reason) && trim($row->reason) !== '' ? $row->reason : 'Tanpa keterangan alasan',
                    'created_at' => $row->created_at ? Carbon::parse($row->created_at)->translatedFormat('j M Y H:i') : '-',
                ];
            })
            ->all();

        return view('shopper.livewire.slide-overs.review-detail', [
            'reports' => $reports,
        ]);
    }
}
