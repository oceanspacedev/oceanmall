<?php

declare(strict_types=1);

namespace App\Actions\Checkout;

use App\Payment\PaymentMethodCatalog;
use InvalidArgumentException;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Payment\Services\PaymentProcessingService;

final class FetchPaymentMethods
{
    /**
     * Enabled payment methods of the country's zone that can take a payment
     * now (PaymentMethodCatalog: the same rule for every driver).
     *
     * @return array<int, array<string, mixed>>
     */
    public function handle(int $countryId): array
    {
        $zone = resolve(ResolveZoneForCountry::class)->handle($countryId);

        if (! $zone) {
            return [];
        }

        $service = resolve(PaymentProcessingService::class);
        $catalog = new PaymentMethodCatalog;

        return $zone->paymentMethods()
            ->where('is_enabled', true)
            ->get()
            ->filter(fn (PaymentMethod $method): bool => $catalog->available($method))
            ->map(fn (PaymentMethod $method): array => $this->toArray($method, $service, $catalog))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(PaymentMethod $method, PaymentProcessingService $service, PaymentMethodCatalog $catalog): array
    {
        $meta = PaymentMethodCatalog::metadata($method);

        try {
            $logo = $service->getLogoUrl($method);
        } catch (InvalidArgumentException) {
            $logo = null;
        }

        $match = $catalog->entry($method);
        $entry = $match['entry'] ?? [];

        return [
            'id' => $method->id,
            'title' => $method->title,
            'slug' => $method->slug,
            // A method without a driver is Shopper's manual one.
            'driver' => filled($method->driver) ? trim((string) $method->driver) : 'manual',
            'description' => $method->description,
            'logo' => is_string($match['logo_url'] ?? null) && $match['logo_url'] !== ''
                ? (string) $match['logo_url']
                : $logo,
            'channel_code' => $meta['channel_code'] ?? null,
            'payment_type' => $meta['payment_type'] ?? null,
            'min_amount' => is_numeric($entry['min_amount'] ?? null) ? (int) $entry['min_amount'] : null,
            'max_amount' => is_numeric($entry['max_amount'] ?? null) ? (int) $entry['max_amount'] : null,
        ];
    }
}
