<?php

declare(strict_types=1);

namespace App\Actions\Shipping;

final class NormalizeShipmentStatus
{
    public const PENDING = 'pending';

    public const LABELED = 'labeled';

    public const PICKED_UP = 'picked_up';

    public const IN_TRANSIT = 'in_transit';

    public const DELIVERED = 'delivered';

    public const DELIVERY_FAILED = 'delivery_failed';

    public const RETURNED = 'returned';

    public const CANCELLED = 'cancelled';

    /** @var list<string> */
    public const STABLE = [
        self::PENDING,
        self::LABELED,
        self::PICKED_UP,
        self::IN_TRANSIT,
        self::DELIVERY_FAILED,
        self::DELIVERED,
        self::RETURNED,
        self::CANCELLED,
    ];

    /**
     * Forward-only rank for shipment status transitions.
     * Terminal states (delivered, returned, cancelled) never regress to an earlier stage.
     */
    public static function rank(?string $status): int
    {
        return match (strtolower(trim((string) $status))) {
            self::PENDING, 'ready' => 0,
            self::LABELED => 1,
            self::PICKED_UP => 2,
            self::IN_TRANSIT => 3,
            self::DELIVERY_FAILED => 4,
            self::DELIVERED, self::RETURNED => 5,
            self::CANCELLED => 6,
            default => 0,
        };
    }

    public static function isTerminal(?string $status): bool
    {
        return in_array(strtolower(trim((string) $status)), [
            self::DELIVERED,
            self::RETURNED,
            self::CANCELLED,
        ], true);
    }

    public static function shouldTransition(?string $current, string $incoming): bool
    {
        $currentNorm = strtolower(trim((string) $current));
        $incomingNorm = strtolower(trim($incoming));

        if ($incomingNorm === '' || ! in_array($incomingNorm, self::STABLE, true)) {
            return false;
        }

        if ($currentNorm === '' || ! in_array($currentNorm, [...self::STABLE, 'ready'], true)) {
            return true;
        }

        if ($currentNorm === $incomingNorm) {
            return true;
        }

        if (self::isTerminal($currentNorm)) {
            // A returned parcel cannot become delivered, nor can delivered/cancelled regress.
            return false;
        }

        if ($incomingNorm === self::CANCELLED || $incomingNorm === self::RETURNED) {
            return true;
        }

        return self::rank($incomingNorm) >= self::rank($currentNorm);
    }

    /**
     * Map a raw carrier / RajaOngkir status string into a stable shipment status.
     */
    public function handle(?string $raw, ?string $fallback = null): ?string
    {
        $candidate = trim((string) $raw);

        if ($candidate === '') {
            return $this->stableOrNull($fallback);
        }

        $upper = strtoupper($candidate);
        $lower = strtolower($candidate);

        if (
            str_contains($lower, 'cancel')
            || str_contains($lower, 'batal')
            || in_array($upper, ['CANCELLED', 'CANCELED', 'DIBATALKAN'], true)
        ) {
            return self::CANCELLED;
        }

        if (
            str_contains($lower, 'return')
            || str_contains($lower, 'retur')
            || in_array($upper, ['RTS', 'RETURNED', 'RETURN_TO_SENDER', 'RETUR'], true)
        ) {
            return self::RETURNED;
        }

        if (
            str_contains($lower, 'failed')
            || str_contains($lower, 'undeliver')
            || str_contains($lower, 'gagal')
            || in_array($upper, ['DELIVERY_FAILED', 'FAILED', 'UNDELIVERED', 'GAGAL_KIRIM'], true)
        ) {
            return $fallback !== null ? $this->stableOrNull($fallback) : self::DELIVERY_FAILED;
        }

        if (
            in_array($lower, ['delivered', 'delivery success', 'selesai'], true)
            || (str_starts_with($lower, 'package delivered') && ! str_contains($lower, 'failed'))
            || in_array($upper, ['DELIVERED', 'DELIVERY_SUCCESS', 'COMPLETED', 'POD', 'SELESAI'], true)
        ) {
            return self::DELIVERED;
        }

        if (
            str_contains($lower, 'transit')
            || str_contains($lower, 'on_process')
            || str_contains($lower, 'on process')
            || str_contains($lower, 'dikirim')
            || str_contains($lower, 'out for delivery')
            || in_array($upper, ['ON_PROCESS', 'OTW', 'IN_TRANSIT', 'SHIPPING', 'PROCESS', 'DIKIRIM'], true)
            // Shopper\Core\Enum\ShipmentStatus values a Shopper driver's track() may answer.
            || in_array($lower, ['at_sorting_center', 'out_for_delivery'], true)
        ) {
            return self::IN_TRANSIT;
        }

        if (
            str_contains($lower, 'pick')
            || str_contains($lower, 'dijemput')
            || in_array($upper, ['PICKED_UP', 'PICKED UP', 'PICKEDUP', 'PICKED', 'PICKUP', 'DIJEMPUT'], true)
        ) {
            return self::PICKED_UP;
        }

        if (
            str_contains($lower, 'diajukan')
            || in_array($lower, [self::LABELED, 'label', 'labeled'], true)
            || in_array($upper, ['LABELED', 'DIAJUKAN'], true)
        ) {
            return self::LABELED;
        }

        if (
            str_contains($lower, 'pack')
            || str_contains($lower, 'kemas')
            || in_array($lower, [self::PENDING, 'ready'], true)
        ) {
            return self::PENDING;
        }

        return $this->stableOrNull($fallback) ?? self::IN_TRANSIT;
    }

    private function stableOrNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = strtolower(trim($value));

        return in_array($normalized, self::STABLE, true) ? $normalized : null;
    }
}
