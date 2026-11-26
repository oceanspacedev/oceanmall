<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cpanel;

use App\Http\Controllers\Controller;
use App\Models\OrderPaymentProof;
use App\Models\OrderReturnRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Shopper\Core\Models\Order;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class OrderFileController extends Controller
{
    public function showProof(Order $order, OrderPaymentProof $proof): StreamedResponse
    {
        abort_unless(Gate::allows('read_orders') || Gate::allows('edit_orders') || auth()->user()?->isAdmin(), 403);
        abort_unless((int) $proof->order_id === (int) $order->id, 404);
        abort_unless(Storage::disk($proof->disk)->exists($proof->path), 404);

        return Storage::disk($proof->disk)->response($proof->path, $proof->original_name, [
            'Content-Type' => $proof->mime_type,
        ]);
    }

    public function showReturnEvidence(Order $order, OrderReturnRequest $returnRequest, int $index): StreamedResponse
    {
        abort_unless(Gate::allows('read_orders') || Gate::allows('edit_orders') || auth()->user()?->isAdmin(), 403);
        abort_unless((int) $returnRequest->order_id === (int) $order->id, 404);

        $paths = (array) $returnRequest->evidence_paths;
        $path = $paths[$index] ?? null;
        abort_unless(is_string($path) && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path);
    }
}
