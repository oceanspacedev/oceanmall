<?php

declare(strict_types=1);

namespace App\Http\Controllers\Cpanel;

use App\Actions\Shipping\NormalizeShipmentStatus;
use App\Actions\Shipping\PrintShipmentLabels;
use App\Http\Controllers\Controller;
use App\Models\OrderShipment;
use App\Shipping\Contracts\PrintsLabels;
use App\Shipping\ShipmentMetadata;
use App\Shipping\ShippingCapabilities;
use App\Support\OrderShipmentOpsPresenter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\View;
use RuntimeException;
use Shopper\Core\Models\Order;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class PrintShipmentLabelController extends Controller
{
    public function __invoke(
        Request $request,
        Order $order,
        PrintShipmentLabels $printLabels,
        OrderShipmentOpsPresenter $presenter,
    ): View|RedirectResponse|JsonResponse|StreamedResponse|Response {
        Gate::authorize('print-shipment-label', $order);

        // The label format (page size) is the printing driver's own vocabulary;
        // it validates the value itself. Return 422 HTML error directly instead
        // of redirecting to `/` inside a target="_blank" tab.
        $validator = Validator::make($request->all(), [
            'page' => ['nullable', 'string', 'max:50', 'regex:/^[a-z0-9_-]+$/i'],
            'shipment' => ['nullable', 'integer'],
        ]);

        if ($validator->fails()) {
            return $this->labelErrorResponse(
                $request,
                $order,
                'Format halaman label atau parameter pengiriman tidak valid.',
            );
        }

        $validated = $validator->validated();
        $page = $validated['page'] ?? null;
        $shipmentId = isset($validated['shipment']) ? (int) $validated['shipment'] : null;

        $allOrderShipments = OrderShipment::query()
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->get();

        $targetShipments = $shipmentId !== null
            ? $allOrderShipments->where('id', $shipmentId)->values()
            : $allOrderShipments;

        $activeTargets = $targetShipments->reject(
            static fn (OrderShipment $s): bool => strtolower((string) $s->status) === NormalizeShipmentStatus::CANCELLED
                || ($shipmentId === null && ShipmentMetadata::isSupersededByReship($s, $allOrderShipments)),
        );

        if ($targetShipments->isNotEmpty() && $activeTargets->isEmpty()) {
            return $this->labelErrorResponse(
                $request,
                $order,
                'Stiker resi tidak tersedia untuk pengiriman yang sudah dibatalkan.',
            );
        }

        if ($shipmentId === null && $activeTargets->isNotEmpty()) {
            $activeHasPrintableCandidate = $activeTargets->contains(
                static fn (OrderShipment $s): bool => filled($s->awb)
                    || filled($s->tracking_number)
                    || $presenter->deliveryOrderNo($s) !== null,
            );
            $cancelledHasPrintableCandidate = $targetShipments
                ->filter(static fn (OrderShipment $s): bool => strtolower((string) $s->status) === NormalizeShipmentStatus::CANCELLED)
                ->contains(
                    static fn (OrderShipment $s): bool => filled($s->awb)
                        || filled($s->tracking_number)
                        || $presenter->deliveryOrderNo($s) !== null,
                );

            if (! $activeHasPrintableCandidate && $cancelledHasPrintableCandidate) {
                return $this->labelErrorResponse(
                    $request,
                    $order,
                    'Stiker resi tidak tersedia untuk pengiriman yang sudah dibatalkan.',
                );
            }
        }

        $capabilities = resolve(ShippingCapabilities::class);

        $printableTargets = $shipmentId !== null
            ? $targetShipments
            : $activeTargets->filter(static fn (OrderShipment $s): bool => $presenter->canPrintLabel($s, $allOrderShipments));

        $isProviderPrintable = static function (OrderShipment $s) use ($capabilities, $presenter): bool {
            if (strtolower((string) $s->status) === NormalizeShipmentStatus::CANCELLED) {
                return false;
            }

            if (! filled($s->awb) && ! filled($s->tracking_number)) {
                return false;
            }

            return $capabilities->forShipmentSupporting($s, PrintsLabels::class) !== null
                && $presenter->deliveryOrderNo($s) !== null;
        };

        $isLocalThermal = static function (OrderShipment $s) use ($capabilities, $presenter): bool {
            if (strtolower((string) $s->status) === NormalizeShipmentStatus::CANCELLED) {
                return false;
            }

            if (! filled($s->awb) && ! filled($s->tracking_number)) {
                return false;
            }

            if ($capabilities->forShipmentSupporting($s, PrintsLabels::class) !== null
                && $presenter->deliveryOrderNo($s) !== null) {
                return false;
            }

            $driverCode = is_string($s->driver) ? trim($s->driver) : '';

            if ($driverCode === '' || $driverCode === 'manual') {
                return true;
            }

            return $capabilities->forShipment($s) !== null;
        };

        $hasLocalThermal = $printableTargets->isNotEmpty() && $printableTargets->contains($isLocalThermal);
        $hasProviderPrintable = $printableTargets->isNotEmpty() && $printableTargets->contains($isProviderPrintable);

        if ($hasLocalThermal && ! $hasProviderPrintable && $printableTargets->every($isLocalThermal)) {
            $allShipments = $presenter->shipments($order);
            $shipmentPresentations = array_filter(
                $allShipments,
                static fn (array $s): bool => $shipmentId !== null
                    ? (int) $s['id'] === $shipmentId
                    : (bool) ($s['can_print_label'] ?? false),
            );

            return view('shopper.shipping.thermal-label', [
                'order' => $order,
                'shipmentPresentations' => $shipmentPresentations,
            ]);
        }

        if ($hasLocalThermal && $hasProviderPrintable && $printableTargets->every(static fn (OrderShipment $s): bool => $isLocalThermal($s) || $isProviderPrintable($s))) {
            $providerLabelUrl = null;

            try {
                $response = $printLabels->handle($order, $page, $shipmentId);
                $providerLabelUrl = $this->reachableLabelUrl($response['label_url'] ?? null);
            } catch (Throwable $e) {
                report($e);
            }

            $providerIds = $printableTargets
                ->filter($isProviderPrintable)
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            $allShipments = $presenter->shipments($order);
            $shipmentPresentations = array_map(
                static function (array $s) use ($order, $page, $providerIds): array {
                    if (in_array((int) $s['id'], $providerIds, true)) {
                        $s['provider_print_url'] = route('shopper.orders.fulfillment.print-label', array_filter([
                            'order' => $order,
                            'shipment' => (int) $s['id'],
                            'page' => $page,
                        ], static fn (mixed $v): bool => $v !== null));
                    }

                    return $s;
                },
                array_filter(
                    $allShipments,
                    static fn (array $s): bool => $shipmentId !== null
                        ? (int) $s['id'] === $shipmentId
                        : (bool) ($s['can_print_label'] ?? false),
                ),
            );

            return view('shopper.shipping.thermal-label', [
                'order' => $order,
                'shipmentPresentations' => $shipmentPresentations,
                'isMixedLabelBatch' => true,
                'providerLabelUrl' => $providerLabelUrl,
            ]);
        }

        try {
            $response = $printLabels->handle($order, $page, $shipmentId);
        } catch (RuntimeException $e) {
            return $this->labelErrorResponse($request, $order, $e->getMessage(), $e);
        } catch (Throwable $e) {
            report($e);

            return $this->labelErrorResponse(
                $request,
                $order,
                __('We could not generate this shipping label right now. Confirm the shipment has a delivery order and pickup, then try again.'),
            );
        }

        $labelUrl = $this->reachableLabelUrl($response['label_url'] ?? null);

        if ($labelUrl !== null) {
            return redirect()->away($labelUrl);
        }

        $pdf = $response['pdf'];

        if ($pdf !== null) {
            $filename = sprintf('label-order-%s.pdf', $order->number);

            return response($pdf, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.$filename.'"',
            ]);
        }

        // Fallback for Sandbox 404s: render built-in thermal printable label
        $allShipments = $presenter->shipments($order);
        $shipmentPresentations = array_filter(
            $allShipments,
            static fn (array $s): bool => $shipmentId !== null
                ? (int) $s['id'] === $shipmentId
                : (bool) ($s['can_print_label'] ?? false),
        );

        return view('shopper.shipping.thermal-label', [
            'order' => $order,
            'shipmentPresentations' => $shipmentPresentations,
        ]);
    }

    private function labelErrorResponse(Request $request, Order $order, string $message, ?Throwable $exception = null): Response
    {
        $cleanMessage = $this->sanitizeLabelErrorMessage($message, $exception);
        $errorBag = (new ViewErrorBag)->put('default', new MessageBag(['label' => $cleanMessage]));

        if ($request->hasSession()) {
            $request->session()->flash('errors', $errorBag);
        }

        return response()->view('shopper.shipping.label-error', [
            'order' => $order,
            'message' => $cleanMessage,
            'orderUrl' => route('shopper.orders.detail', $order),
        ], 422);
    }

    private function sanitizeLabelErrorMessage(string $message, ?Throwable $exception = null): string
    {
        $previous = $exception?->getPrevious();
        $status = null;

        if ($exception instanceof RequestException) {
            $status = $exception->response->status();
        } elseif ($previous instanceof RequestException) {
            $status = $previous->response->status();
        }

        if ($status === null && preg_match('/HTTP request returned status code (\d{3})/i', $message, $matches) === 1) {
            $status = (int) $matches[1];
        }

        if ($status !== null) {
            return match (true) {
                in_array($status, [401, 403], true) => 'Akses layanan cetak label pengiriman tidak aktif atau ditolak penyedia. Periksa konfigurasi API key, atau cetak label secara manual.',
                $status === 429 => 'Batas permintaan layanan cetak label pengiriman sedang tercapai. Silakan coba lagi beberapa saat lagi.',
                $status >= 500 => 'Layanan cetak label penyedia pengiriman sedang mengalami gangguan. Silakan coba lagi sebentar.',
                default => 'Gagal mencetak label dari penyedia pengiriman. Pastikan nomor order dan resi sudah tersedia, lalu coba lagi.',
            };
        }

        if ($exception instanceof ConnectionException || $previous instanceof ConnectionException) {
            return 'Koneksi ke penyedia pengiriman terputus saat mencetak label. Silakan coba lagi sebentar.';
        }

        if (str_contains($message, 'HTTP request returned status code') || str_contains($message, '{"')) {
            return 'Gagal mencetak label dari penyedia pengiriman. Silakan coba lagi atau cetak label secara manual.';
        }

        return $message;
    }

    private function reachableLabelUrl(?string $labelUrl): ?string
    {
        if (! is_string($labelUrl) || trim($labelUrl) === '') {
            return null;
        }

        try {
            $check = Http::timeout(10)->get($labelUrl);

            return $check->successful() ? $labelUrl : null;
        } catch (Throwable) {
            return $labelUrl;
        }
    }
}
