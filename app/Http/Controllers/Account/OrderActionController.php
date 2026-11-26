<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Account\CancelCustomerOrder;
use App\Actions\Account\SubmitReturnRequest;
use App\Actions\Account\UploadPaymentProof;
use App\Http\Controllers\Controller;
use App\Models\OrderPaymentProof;
use App\Models\OrderReturnRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Shopper\Core\Models\Order;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class OrderActionController extends Controller
{
    public function cancel(Request $request, Order $order, CancelCustomerOrder $action): RedirectResponse
    {
        abort_unless((int) $order->customer_id === (int) auth()->id(), 403);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $action->handle($order, $user, $data['reason'] ?? null);

        return back()->with('success', 'Pesanan berhasil dibatalkan dan stok/kupon telah dikembalikan.');
    }

    public function storeReturn(Request $request, Order $order, SubmitReturnRequest $action): RedirectResponse
    {
        abort_unless((int) $order->customer_id === (int) auth()->id(), 403);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.order_item_id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'evidence' => ['nullable', 'array', 'max:5'],
            'evidence.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $action->handle(
            order: $order,
            customer: $user,
            reason: (string) $data['reason'],
            description: $data['description'] ?? null,
            requestedItems: $data['items'],
            evidenceFiles: $request->file('evidence', []),
        );

        return back()->with('success', 'Pengajuan retur berhasil dikirim dan menunggu tinjauan CS.');
    }

    public function uploadProof(Request $request, Order $order, UploadPaymentProof $action): RedirectResponse
    {
        abort_unless((int) $order->customer_id === (int) auth()->id(), 403);

        $data = $request->validate([
            'proof' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'sender_name' => ['nullable', 'string', 'max:120'],
            'sender_bank' => ['nullable', 'string', 'max:80'],
            'transfer_amount' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $action->handle(
            order: $order,
            customer: $user,
            file: $request->file('proof'),
            senderName: $data['sender_name'] ?? null,
            senderBank: $data['sender_bank'] ?? null,
            transferAmount: isset($data['transfer_amount']) ? (int) $data['transfer_amount'] : null,
            notes: $data['notes'] ?? null,
        );

        return back()->with('success', 'Bukti transfer berhasil diunggah dan menunggu verifikasi Finance.');
    }

    public function showProof(Order $order, OrderPaymentProof $proof): StreamedResponse
    {
        abort_unless((int) $order->customer_id === (int) auth()->id() && (int) $proof->order_id === (int) $order->id, 403);
        abort_unless(Storage::disk($proof->disk)->exists($proof->path), 404);

        return Storage::disk($proof->disk)->response($proof->path, $proof->original_name, [
            'Content-Type' => $proof->mime_type,
        ]);
    }

    public function showReturnEvidence(Order $order, OrderReturnRequest $returnRequest, int $index): StreamedResponse
    {
        abort_unless((int) $order->customer_id === (int) auth()->id() && (int) $returnRequest->order_id === (int) $order->id, 403);

        $paths = (array) $returnRequest->evidence_paths;
        $path = $paths[$index] ?? null;
        abort_unless(is_string($path) && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path);
    }
}
