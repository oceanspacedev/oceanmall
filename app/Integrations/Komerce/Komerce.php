<?php

declare(strict_types=1);

namespace App\Integrations\Komerce;

use App\Integrations\Komerce\Support\KomerceCapabilities;

/**
 * Readiness of each Komerce product (Payment, RajaOngkir Shipping Cost,
 * Shipping Delivery, QRISLY). Module-internal: generic store code never asks
 * this class, it asks the Shopper managers (`Payment::isConfigured()`,
 * `Shipping::driver($code)->isConfigured()`) or App\Shipping\ShippingCapabilities,
 * which reach these checks through the Komerce drivers.
 *
 * A product is ready when KOMERCE_ENABLED is not false, its own key is set
 * (keys never authenticate another product) and the Collaborator plan
 * includes it (learned at runtime by KomerceCapabilities).
 */
final class Komerce
{
    public static function serviceEnabled(string $apiKeyConfig): bool
    {
        if (config('komerce.enabled') === false) {
            return false;
        }

        if (trim((string) config($apiKeyConfig, '')) === '') {
            return false;
        }

        // A configured key is not enough: the Collaborator plan must include
        // the product. KomerceCapabilities learns that from the API itself.
        $product = KomerceCapabilities::productForConfig($apiKeyConfig);

        return $product === null || resolve(KomerceCapabilities::class)->entitled($product);
    }

    public static function paymentEnabled(): bool
    {
        return self::serviceEnabled('komerce.payment_api_key');
    }

    public static function shippingCostEnabled(): bool
    {
        return self::serviceEnabled('komerce.shipping_cost_api_key');
    }

    public static function shippingDeliveryEnabled(): bool
    {
        return self::serviceEnabled('komerce.shipping_delivery_api_key');
    }

    /**
     * QRISLY product API is opt-in: both API key and merchant qris_id must be set.
     * When disabled, checkout QRIS falls back to Komerce Payment API (`payment_type=qris`).
     */
    public static function qrislyEnabled(): bool
    {
        return self::serviceEnabled('komerce.qrisly_api_key')
            && trim((string) config('komerce.qrisly_qris_id', '')) !== '';
    }

    /**
     * Whether at least one independently configured Komerce product is ready.
     * Product-specific callers should use the corresponding product check.
     */
    public static function enabled(): bool
    {
        if (config('komerce.enabled') === false) {
            return false;
        }

        return self::paymentEnabled()
            || self::shippingCostEnabled()
            || self::shippingDeliveryEnabled()
            || self::qrislyEnabled();
    }

    /**
     * Some Komerce shipping product (RajaOngkir Cost or Delivery) is ready.
     */
    public static function shippingEnabled(): bool
    {
        return self::shippingCostEnabled() || self::shippingDeliveryEnabled();
    }
}
