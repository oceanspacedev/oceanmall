<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Support\Str;
use Shopper\Core\Models\PaymentMethod;

/**
 * Payment method logo lookup behind Shopper's `shopper.payment.logo` binding.
 * Order of precedence:
 *
 *  1. the method's own metadata (`logo_url` / `logo`);
 *  2. a local file public/images/payments/{key}.svg|png, where key is the
 *     method's `channel_code` metadata, then its slug;
 *  3. keys or URLs contributed by payment providers with extend(), e.g. a
 *     provider whose slugs carry a prefix maps them to the local file name.
 */
final class PaymentLogos
{
    /** @var list<Closure(PaymentMethod): ?string> */
    private array $sources = [];

    /**
     * @param  Closure(PaymentMethod): ?string  $source  Returns a local file key (e.g. "qris") or an absolute URL
     */
    public function extend(Closure $source): void
    {
        $this->sources[] = $source;
    }

    public function forMethod(PaymentMethod $method): ?string
    {
        $metadata = $method->metadata;

        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true);
        }

        $logo = data_get($metadata, 'logo_url') ?? data_get($metadata, 'logo');

        if (is_string($logo) && trim($logo) !== '') {
            return Str::startsWith($logo, ['http://', 'https://']) ? $logo : asset($logo);
        }

        $channel = data_get($metadata, 'channel_code');

        foreach ([is_string($channel) ? $channel : null, (string) $method->slug] as $key) {
            $local = self::local((string) $key);

            if ($local !== null) {
                return $local;
            }
        }

        foreach ($this->sources as $source) {
            $value = $source($method);

            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            if (Str::startsWith($value, ['http://', 'https://'])) {
                return $value;
            }

            $local = self::local($value);

            if ($local !== null) {
                return $local;
            }
        }

        return null;
    }

    public static function local(string $key): ?string
    {
        $key = strtolower(trim($key));

        if (preg_match('/^[a-z0-9_-]+$/', $key) !== 1) {
            return null;
        }

        foreach (['svg', 'png'] as $extension) {
            $path = public_path("images/payments/{$key}.{$extension}");

            if (file_exists($path)) {
                return asset("images/payments/{$key}.{$extension}").'?v='.filemtime($path);
            }
        }

        return null;
    }
}
