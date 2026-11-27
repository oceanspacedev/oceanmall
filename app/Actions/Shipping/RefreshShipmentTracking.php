<?php

declare(strict_types=1);

namespace App\Actions\Shipping;

use App\Models\OrderShipment;
use App\Shipping\Contracts\TracksShipments;
use App\Shipping\ShipmentMetadata;
use App\Shipping\ShippingCapabilities;
use App\Support\ProviderQuotaRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Shopper\Shipping\DataTransferObjects\TrackingEvent;
use Shopper\Shipping\DataTransferObjects\TrackingInfo;
use Throwable;

/**
 * Pull the latest tracking of a shipment from the shipping driver that can
 * track it (ShippingCapabilities::trackerFor) and store the normalized status.
 *
 * - A driver that TracksShipments receives the whole shipment and records its
 *   provider details itself: raw answers in its own metadata bucket, and the
 *   customer-facing timeline under the neutral metadata.tracking_history
 *   (App\Shipping\ShipmentMetadata), which web and mobile read.
 * - Any other Shopper driver is asked with track($awb); its events are stored
 *   here as metadata.tracking_history.
 *
 * A failed lookup preserves any history already recorded and surfaces a
 * customer-safe failure without consuming the customer cooldown.
 */
final readonly class RefreshShipmentTracking
{
    public function __construct(
        private ShippingCapabilities $capabilities,
        private NormalizeShipmentStatus $normalizeStatus,
        private SyncOrderShippingFromShipments $syncOrderShipping,
    ) {}

    /**
     * Minutes a customer's "track" tap is answered from the stored tracking
     * instead of asking the provider again.
     */
    public const CUSTOMER_COOLDOWN_MINUTES = 15;

    /**
     * The customer's "track" button (web and app). Each provider lookup may
     * spend an account-wide quota that checkout quotes also need, so a
     * customer cannot spend it by tapping: a delivered or cancelled parcel is final, and
     * any other parcel is asked at most once per cooldown. Staff, webhooks
     * and the scheduled refresh call handle() and are not limited.
     *
     * @return bool Whether the provider was asked (false: stored tracking is current enough).
     *
     * @throws RuntimeException When the shipment has no AWB or no driver can track it.
     */
    public function handleForCustomer(OrderShipment $shipment): bool
    {
        if (NormalizeShipmentStatus::isTerminal(is_string($shipment->status) ? $shipment->status : null)) {
            return false;
        }

        $cooldown = 'shipment-track-cooldown:'.$shipment->getKey();

        // Cache::add is atomic: of two taps at once only one asks the provider.
        if (! Cache::add($cooldown, true, now()->addMinutes(self::CUSTOMER_COOLDOWN_MINUTES))) {
            return false;
        }

        try {
            $this->handle($shipment);
        } catch (Throwable $e) {
            // Nothing was refreshed: the next tap may try again (the route's
            // own throttle still bounds how often).
            Cache::forget($cooldown);

            throw $e;
        }

        return true;
    }

    /**
     * @throws RuntimeException When the shipment has no AWB or no driver can track it.
     */
    public function handle(OrderShipment $shipment): OrderShipment
    {
        $awb = $this->airwayBill($shipment);

        if ($awb === null) {
            throw new RuntimeException(__('This shipment has no airway bill (AWB) to track yet.'));
        }

        $driver = $this->capabilities->trackerFor($shipment);

        if ($driver === null) {
            throw new RuntimeException(__('No configured shipping provider can track this airway bill.'));
        }

        try {
            $info = $driver instanceof TracksShipments
                ? $driver->trackShipment($shipment)
                : $driver->track($awb);
        } catch (Throwable $e) {
            report($e);
            $shipment->refresh();

            throw new RuntimeException($this->localizedFailureMessage($e), 0, $e);
        }

        $shipment->refresh();
        $metadata = is_array($shipment->metadata) ? $shipment->metadata : [];

        if (! $driver instanceof TracksShipments) {
            $metadata[ShipmentMetadata::TRACKING_HISTORY] = $this->history($info);
            $metadata['tracking_status'] = $info->statusDescription;
            $metadata['tracked_at'] = now()->toIso8601String();
            $metadata['tracked_by'] = $driver->code();
        }

        // Always the stable vocabulary (NormalizeShipmentStatus::STABLE): a driver
        // may answer with Shopper's ShipmentStatus values (out_for_delivery,
        // delivery_failed...) or its own words (dropping_off, on_hold). An
        // unknown answer keeps the current status.
        $raw = trim($info->status) !== '' ? $info->status : $info->statusDescription;
        $current = is_string($shipment->status) ? $shipment->status : null;
        $normalized = in_array($raw, NormalizeShipmentStatus::STABLE, true)
            ? $raw
            : $this->normalizeStatus->handle($raw, $current);

        $attributes = ['metadata' => $metadata];

        if ($normalized !== null && NormalizeShipmentStatus::shouldTransition($current, $normalized)) {
            $attributes['status'] = $normalized;
        }

        $shipment->forceFill($attributes)->save();

        $order = $shipment->order()->first();

        if ($order !== null) {
            $this->syncOrderShipping->handle($order);
        }

        return $shipment->refresh();
    }

    private function airwayBill(OrderShipment $shipment): ?string
    {
        $candidates = [$shipment->awb, $shipment->tracking_number];

        // Provider buckets (metadata.<provider>.awb) written before the AWB column was filled.
        foreach ((array) $shipment->metadata as $bucket) {
            if (is_array($bucket)) {
                $candidates[] = $bucket['awb'] ?? null;
            }
        }

        foreach ($candidates as $candidate) {
            if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                return trim((string) $candidate);
            }
        }

        return null;
    }

    private function localizedFailureMessage(Throwable $e): string
    {
        $raw = $e->getMessage();

        if (preg_match('/(no airway bill|airway bill is required)/i', $raw) === 1) {
            return 'Nomor resi belum tersedia untuk paket ini.';
        }

        if (preg_match('/(no courier|courier.*required)/i', $raw) === 1) {
            return 'Data kurir pengiriman belum lengkap untuk pelacakan.';
        }

        if (preg_match('/(different airway bill|instead of)/i', $raw) === 1) {
            return 'Data pelacakan kurir tidak sesuai dengan nomor resi paket ini.';
        }

        if (preg_match('/(429|quota|kuota|limit|too many|exhausted)/i', $raw) === 1) {
            foreach (resolve(ProviderQuotaRegistry::class)->all() as $quota) {
                if (($quota['exhausted'] ?? false) !== true) {
                    continue;
                }

                $rawUntil = $quota['exhausted_until'] ?? null;

                if (is_string($rawUntil) && $rawUntil !== '') {
                    try {
                        $until = CarbonImmutable::parse($rawUntil)->setTimezone('Asia/Jakarta')->format('d M Y H:i');

                        return "Batas pelacakan harian kurir sudah tercapai. Silakan coba lagi setelah {$until} WIB.";
                    } catch (Throwable) {
                        // Fall through to generic quota copy.
                    }
                }
            }

            return 'Batas pelacakan harian kurir sudah tercapai. Silakan coba lagi nanti.';
        }

        return 'Gagal memperbarui pelacakan dari penyedia pengiriman. Coba lagi sebentar.';
    }

    /**
     * @return list<array{description: string, datetime: string|null, location: string|null}>
     */
    private function history(TrackingInfo $info): array
    {
        return array_values(array_map(static fn (TrackingEvent $event): array => [
            'description' => $event->description !== '' ? $event->description : $event->status,
            'datetime' => $event->occurredAt->format('Y-m-d H:i'),
            'location' => $event->location,
        ], $info->events));
    }
}
