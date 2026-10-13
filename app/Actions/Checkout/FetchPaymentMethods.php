<?php

declare(strict_types=1);

namespace App\Actions\Checkout;

use App\Support\KomercePaymentCatalog;
use InvalidArgumentException;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Payment\Facades\Payment;
use Shopper\Payment\Services\PaymentProcessingService;

final class FetchPaymentMethods
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function handle(int $countryId): array
    {
        $zone = resolve(ResolveZoneForCountry::class)->handle($countryId);

        if (! $zone) {
            return [];
        }

        $stripeEnabled = (bool) config('shopper.payment.drivers.stripe.enabled', false);
        $service = resolve(PaymentProcessingService::class);
        $official = resolve(KomercePaymentCatalog::class)->official();

        return $zone->paymentMethods()
            ->where('is_enabled', true)
            ->get()
            ->filter(fn (PaymentMethod $method): bool => $this->isAvailable($method, $stripeEnabled, $official))
            ->map(fn (PaymentMethod $method): array => $this->toArray($method, $service, $official))
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>|null  $official
     */
    private function isAvailable(PaymentMethod $method, bool $stripeEnabled, ?array $official): bool
    {
        return match ($method->driver ?? 'manual') {
            'stripe' => $stripeEnabled,
            'komerce' => $this->komerceMethodAvailable($method, $official),
            default => Payment::isConfigured($method->driver ?? 'manual'),
        };
    }

    /**
     * @param  list<array<string, mixed>>|null  $official
     */
    private function komerceMethodAvailable(PaymentMethod $method, ?array $official): bool
    {
        $meta = KomercePaymentCatalog::decodeMeta($method->metadata);
        $paymentType = strtolower((string) ($meta['payment_type'] ?? 'bank_transfer'));

        $locallyReady = $paymentType === 'qris'
            ? qrisly_enabled() || komerce_payment_enabled()
            : komerce_payment_enabled();

        if (! $locallyReady) {
            return false;
        }

        if ($official === null) {
            return true;
        }

        return resolve(KomercePaymentCatalog::class)->match($meta, $official) !== null;
    }

    /**
     * @param  list<array<string, mixed>>|null  $official
     * @return array<string, mixed>
     */
    private function toArray(PaymentMethod $method, PaymentProcessingService $service, ?array $official): array
    {
        $meta = KomercePaymentCatalog::decodeMeta($method->metadata);

        try {
            $logo = $service->getLogoUrl($method);
        } catch (InvalidArgumentException) {
            $logo = null;
        }

        $match = $official !== null ? resolve(KomercePaymentCatalog::class)->match($meta, $official) : null;

        return [
            'id' => $method->id,
            'title' => $method->title,
            'slug' => $method->slug,
            'driver' => $method->driver,
            'description' => $method->description,
            'logo' => is_string($match['logo_url'] ?? null) && $match['logo_url'] !== ''
                ? (string) $match['logo_url']
                : $logo,
            'channel_code' => $meta['channel_code'] ?? null,
            'payment_type' => $meta['payment_type'] ?? null,
            'min_amount' => is_numeric($match['min_amount'] ?? null) ? (int) $match['min_amount'] : null,
            'max_amount' => is_numeric($match['max_amount'] ?? null) ? (int) $match['max_amount'] : null,
        ];
    }
}
