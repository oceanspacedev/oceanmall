<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Support\Str;
use Shopper\Core\Models\Carrier;

/**
 * Carrier logo lookup shared by Shopper's `shopper.carrier.logo` binding,
 * checkout rates and order pages. Order of precedence:
 *
 *  1. the carrier's own metadata (`logo_url` / `logo`), which a driver or a
 *     carrier sync may write;
 *  2. a local file in public/images/couriers/{slug}.svg|png;
 *  3. sources registered by shipping providers with extend(). A provider
 *     registers its CDN mapping from its service provider and only answers
 *     while it is configured, so a keyless store never loads third-party
 *     logo URLs.
 */
final class CarrierLogos
{
    /** @var list<Closure(string): ?string> */
    private array $sources = [];

    /** @var list<Closure(string): ?string> */
    private array $remoteSources = [];

    /**
     * @param  Closure(string): ?string  $source  Receives a lower-case carrier slug
     * @param  bool  $remote  A provider CDN: asked only after every module's
     *                        local source, so one active module's CDN never
     *                        overrides another module's local logo alias
     */
    public function extend(Closure $source, bool $remote = false): void
    {
        if ($remote) {
            $this->remoteSources[] = $source;
        } else {
            $this->sources[] = $source;
        }
    }

    public function forCarrier(Carrier $carrier): ?string
    {
        $slug = strtolower((string) $carrier->slug);
        $courier = EnabledCarriers::courierCode($carrier);

        // A provider-namespaced slug (e.g. "rajaongkir-jne") still finds the
        // courier's logo by its courier code.
        return $this->fromMetadata($carrier->metadata)
            ?? $this->forSlug($slug)
            ?? ($courier !== '' && $courier !== $slug ? $this->forSlug($courier) : null);
    }

    /**
     * Logo for a carrier code stored on a shipment or rate (e.g. "jne").
     */
    public function forCode(?string $code): ?string
    {
        $slug = strtolower(trim((string) $code));

        if ($slug === '') {
            return null;
        }

        $carrier = Carrier::query()->where('slug', $slug)->first();

        return ($carrier !== null ? $this->fromMetadata($carrier->metadata) : null)
            ?? $this->forSlug($slug);
    }

    private function forSlug(string $slug): ?string
    {
        $slug = strtolower(trim($slug));

        if ($slug === '') {
            return null;
        }

        $local = self::local($slug);

        if ($local !== null) {
            return $local;
        }

        foreach ([...$this->sources, ...$this->remoteSources] as $source) {
            $url = $source($slug);

            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return null;
    }

    public static function local(string $slug): ?string
    {
        if (preg_match('/^[a-z0-9_-]+$/', $slug) !== 1) {
            return null;
        }

        foreach (['svg', 'png'] as $extension) {
            $path = public_path("images/couriers/{$slug}.{$extension}");

            if (file_exists($path)) {
                return asset("images/couriers/{$slug}.{$extension}").'?v='.filemtime($path);
            }
        }

        return null;
    }

    private function fromMetadata(mixed $metadata): ?string
    {
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true);
        }

        $logo = data_get($metadata, 'logo_url') ?? data_get($metadata, 'logo');

        if (! is_string($logo) || trim($logo) === '') {
            return null;
        }

        return Str::startsWith($logo, ['http://', 'https://']) ? $logo : asset($logo);
    }
}
