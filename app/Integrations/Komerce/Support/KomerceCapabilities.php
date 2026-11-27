<?php

declare(strict_types=1);

namespace App\Integrations\Komerce\Support;

use App\Integrations\Komerce\Komerce;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * What the Komerce Collaborator account can actually do, learned from the
 * APIs themselves (there is no "plan" endpoint):
 *
 *  - a product is *entitled* when its key is accepted (2xx), and *not
 *    entitled* when the API answers "Invalid API key" (400/401/403) — that is
 *    what a Starter account gets for Shipping Delivery;
 *  - the Shipping Cost daily quota is tracked from our own request count and
 *    from the provider's 429 / "limit" answers;
 *  - the tier is inferred from those facts (starter / pro / komship) and is
 *    labelled as inferred.
 *
 * Facts come from two sources: the daily `komerce:capabilities` probe and
 * passive observation of every real API response (see UsesKomerceHttp).
 */
final class KomerceCapabilities
{
    public const PRODUCTS = ['shipping_cost', 'shipping_delivery', 'payment', 'qrisly'];

    private const CACHE_KEY = 'komerce:capabilities:v1';

    private const TTL_HOURS = 24;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $state = null;

    /** @var array<string, string> product => config key holding its credential */
    public const KEY_CONFIG = [
        'shipping_cost' => 'komerce.shipping_cost_api_key',
        'shipping_delivery' => 'komerce.shipping_delivery_api_key',
        'payment' => 'komerce.payment_api_key',
        'qrisly' => 'komerce.qrisly_api_key',
    ];

    /**
     * Product the given config key belongs to.
     */
    public static function productForConfig(string $apiKeyConfig): ?string
    {
        $product = array_search($apiKeyConfig, self::KEY_CONFIG, true);

        return $product === false ? null : $product;
    }

    /**
     * True unless the provider has told us the key is not entitled.
     */
    public function entitled(string $product): bool
    {
        $state = $this->state()[$product] ?? [];

        if (($state['entitled'] ?? null) === false && $this->fresh($state['checked_at'] ?? null)) {
            return false;
        }

        return true;
    }

    public function reason(string $product): ?string
    {
        return $this->state()[$product]['reason'] ?? null;
    }

    public function quotaExhausted(string $product = 'shipping_cost'): bool
    {
        $until = $this->state()[$product]['quota_exhausted_until'] ?? null;

        return is_string($until) && CarbonImmutable::parse($until)->isFuture();
    }

    public function quotaExhaustedUntil(string $product = 'shipping_cost'): ?CarbonImmutable
    {
        $until = $this->state()[$product]['quota_exhausted_until'] ?? null;

        return is_string($until) ? CarbonImmutable::parse($until) : null;
    }

    /**
     * Requests we sent to a product today (WIB day), from passive observation.
     */
    public function requestsToday(string $product = 'shipping_cost'): int
    {
        return (int) Cache::get($this->counterKey($product), 0);
    }

    /**
     * Daily quota for Shipping Cost: explicit override, else inferred tier.
     */
    public function dailyQuota(string $product = 'shipping_cost'): ?int
    {
        $override = (int) config('komerce.plan.shipping_cost_daily_quota', 0);

        if ($override > 0) {
            return $override;
        }

        return match ($this->plan()) {
            'pro' => (int) config('komerce.plan.quotas.pro', 20000),
            'komship' => null,
            default => (int) config('komerce.plan.quotas.starter', 100),
        };
    }

    /**
     * Inferred Collaborator tier: 'komship' (has Shipping Delivery), 'pro'
     * (more than the Starter quota accepted in one day) or 'starter'.
     */
    public function plan(): string
    {
        $state = $this->state();

        if (($state['shipping_delivery']['entitled'] ?? null) === true) {
            return 'komship';
        }

        $starter = (int) config('komerce.plan.quotas.starter', 100);

        if ((int) ($state['shipping_cost']['max_daily_requests'] ?? 0) > $starter) {
            return 'pro';
        }

        return 'starter';
    }

    /**
     * Passive observation of a real API response.
     */
    public function observe(string $product, int $status, string $body = '', bool $clearQuotaOnSuccess = false): void
    {
        if (! in_array($product, self::PRODUCTS, true)) {
            return;
        }

        $now = CarbonImmutable::now()->toIso8601String();
        $message = $this->message($body);
        $effectiveStatus = $this->errorEnvelopeStatus($status, $body) ?? $status;
        $isSuccess = $effectiveStatus >= 200 && $effectiveStatus < 300;
        $count = $isSuccess ? $this->countRequest($product) : 0;

        $this->update($product, function (array $entry) use ($effectiveStatus, $isSuccess, $message, $now, $count, $clearQuotaOnSuccess): array {
            if ($isSuccess) {
                $until = $entry['quota_exhausted_until'] ?? null;
                $quotaActive = is_string($until) && CarbonImmutable::parse($until)->isFuture();
                $clearQuota = $clearQuotaOnSuccess || ! $quotaActive;

                return array_merge($entry, [
                    'entitled' => true,
                    'reason' => null,
                    'checked_at' => $now,
                    'max_daily_requests' => max((int) ($entry['max_daily_requests'] ?? 0), $count),
                ], $clearQuota ? [
                    'quota_exhausted_until' => null,
                    'quota_reason' => null,
                ] : []);
            }

            if ($this->isNotEntitled($effectiveStatus, $message)) {
                return array_merge($entry, [
                    'entitled' => false,
                    'reason' => $message !== '' ? $message : "HTTP {$effectiveStatus}",
                    'checked_at' => $now,
                ]);
            }

            if ($this->isQuotaExceeded($effectiveStatus, $message)) {
                $entry['quota_exhausted_until'] = $this->nextWibMidnight()->toIso8601String();
                $entry['quota_reason'] = $message !== '' ? $message : "HTTP {$effectiveStatus}";
            }

            return $entry;
        });
    }

    /**
     * Active probe with the configured keys (cheap read endpoints only).
     *
     * @return array<string, array<string, mixed>>
     */
    public function probe(): array
    {
        $probes = [
            'shipping_cost' => [
                'base' => (string) config('komerce.rajaongkir.cost_base_url'),
                'headers' => ['key' => (string) config('komerce.shipping_cost_api_key')],
                'path' => '/api/v1/destination/domestic-destination',
                'query' => ['search' => 'Gambir', 'limit' => 1],
            ],
            'shipping_delivery' => [
                'base' => (string) config('komerce.rajaongkir.delivery_base_url'),
                'headers' => ['x-api-key' => (string) config('komerce.shipping_delivery_api_key')],
                'path' => '/tariff/api/v1/destination/search',
                'query' => ['keyword' => 'Gambir'],
            ],
            'payment' => [
                'base' => (string) config('komerce.payment_base_url'),
                'headers' => ['x-api-key' => (string) config('komerce.payment_api_key')],
                'path' => '/api/v1/user/methods',
                'query' => [],
            ],
        ];

        foreach ($probes as $product => $probe) {
            if (trim((string) config(self::KEY_CONFIG[$product], '')) === '') {
                $this->markMissingKey($product);

                continue;
            }

            try {
                $response = Http::baseUrl(rtrim($probe['base'], '/'))
                    ->withHeaders($probe['headers'])
                    ->connectTimeout(3)
                    ->timeout(8)
                    ->acceptJson()
                    ->get($probe['path'], $probe['query']);
            } catch (Throwable $e) {
                // Unreachable ≠ not entitled; keep the last known state.
                $this->touch($product, ['last_error' => $e->getMessage()]);

                continue;
            }

            $this->observe($product, $response->status(), (string) $response->body(), clearQuotaOnSuccess: true);
        }

        // QRISLY needs a merchant qris_id and has no cheap read endpoint; treat
        // "configured" as the entitlement signal, while keeping any recent
        // 401/403 lockout intact until its TTL expires.
        $qrislyConfigured = config('komerce.enabled') !== false
            && trim((string) config('komerce.qrisly_api_key', '')) !== ''
            && trim((string) config('komerce.qrisly_qris_id', '')) !== '';

        if (! $qrislyConfigured) {
            $this->touch('qrisly', [
                'entitled' => null,
                'reason' => config('komerce.enabled') === false
                    ? 'Komerce integration disabled'
                    : 'QRISLY key or qris_id not configured',
                'checked_at' => CarbonImmutable::now()->toIso8601String(),
            ]);
        } else {
            $this->update('qrisly', function (array $entry): array {
                if (($entry['entitled'] ?? null) === false && $this->fresh($entry['checked_at'] ?? null)) {
                    return $entry;
                }

                return array_merge($entry, [
                    'entitled' => true,
                    'reason' => null,
                    'checked_at' => CarbonImmutable::now()->toIso8601String(),
                ]);
            });
        }

        return $this->state();
    }

    /**
     * Middleware for Laravel's HTTP client: observes every response of a product.
     */
    public function responseObserver(string $product): callable
    {
        return function (ResponseInterface $response) use ($product): ResponseInterface {
            try {
                $body = (string) $response->getBody();
                $response->getBody()->rewind();
                $this->observe($product, $response->getStatusCode(), $body);
            } catch (Throwable) {
                // Observation must never break the real call.
            }

            return $response;
        };
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function state(): array
    {
        // Memoised per instance (the container scopes it per request / job):
        // the Komerce::*Enabled() checks run many times per request
        // and the cache store is the database by default.
        if ($this->state === null) {
            $state = Cache::get(self::CACHE_KEY);
            $this->state = is_array($state) ? $state : [];
        }

        return $this->state;
    }

    public function forget(): void
    {
        $this->state = null;
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function touch(string $product, array $values): void
    {
        $this->update($product, static fn (array $entry): array => array_merge($entry, $values));
    }

    /**
     * Read-modify-write ONE product's entry against the cache as it is now,
     * not the per-request memo: another request or job may have stored a
     * quota or entitlement verdict since this instance first read the state,
     * and writing the memo back would erase it. Serialised with a short lock
     * when the cache store supports locks.
     *
     * @param  Closure(array<string, mixed>): array<string, mixed>  $change
     */
    private function update(string $product, Closure $change): void
    {
        $write = function () use ($product, $change): void {
            $stored = Cache::get(self::CACHE_KEY);
            $state = is_array($stored) ? $stored : [];
            $entry = is_array($state[$product] ?? null) ? $state[$product] : [];
            $state[$product] = $change($entry);
            $this->store($state);
        };

        if (! Cache::getStore() instanceof LockProvider) {
            $write();

            return;
        }

        try {
            Cache::lock(self::CACHE_KEY.':lock', 5)->block(2, $write);
        } catch (LockTimeoutException) {
            // Better a rare lost update than a lost observation.
            $write();
        }
    }

    private function markMissingKey(string $product): void
    {
        $this->touch($product, [
            'entitled' => null,
            'reason' => 'API key not configured',
            'checked_at' => CarbonImmutable::now()->toIso8601String(),
        ]);
    }

    /**
     * @param  array<string, array<string, mixed>>  $state
     */
    private function store(array $state): void
    {
        $this->state = $state;
        Cache::put(self::CACHE_KEY, $state, now()->addDays(7));
    }

    private function fresh(?string $checkedAt): bool
    {
        return is_string($checkedAt) && CarbonImmutable::parse($checkedAt)->addHours(self::TTL_HOURS)->isFuture();
    }

    private function countRequest(string $product): int
    {
        $key = $this->counterKey($product);

        // add() + increment() are atomic on the database / redis stores, so
        // concurrent requests do not undercount the daily quota.
        Cache::add($key, 0, $this->nextWibMidnight());

        return (int) Cache::increment($key);
    }

    private function counterKey(string $product): string
    {
        return 'komerce:requests:'.$product.':'.CarbonImmutable::now('Asia/Jakarta')->format('Y-m-d');
    }

    private function nextWibMidnight(): CarbonImmutable
    {
        return CarbonImmutable::now('Asia/Jakarta')->addDay()->startOfDay();
    }

    private function message(string $body): string
    {
        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            return '';
        }

        foreach (['meta.message', 'message'] as $path) {
            $value = data_get($decoded, $path);

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }

    private function errorEnvelopeStatus(int $httpStatus, string $body): ?int
    {
        if ($httpStatus < 200 || $httpStatus >= 300 || trim($body) === '') {
            return null;
        }

        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            return null;
        }

        $metaStatus = strtolower(trim((string) data_get($decoded, 'meta.status', '')));
        $metaCodeRaw = data_get($decoded, 'meta.code');
        $metaCode = is_numeric($metaCodeRaw) ? (int) $metaCodeRaw : 200;
        $success = $decoded['success'] ?? null;

        if (! in_array($metaStatus, ['error', 'failed'], true) && $success !== false && $metaCode < 400) {
            return null;
        }

        return $metaCode >= 400 ? $metaCode : 400;
    }

    private function isNotEntitled(int $status, string $message): bool
    {
        if ($status === 401) {
            return true;
        }

        if ($status === 403) {
            return ! $this->isQuotaExceeded($status, $message);
        }

        return $status === 400 && preg_match('/invalid api ?key/i', $message) === 1;
    }

    private function isQuotaExceeded(int $status, string $message): bool
    {
        if ($status === 429) {
            return true;
        }

        return preg_match('/(limit|quota|kuota|too many)/i', $message) === 1;
    }
}
