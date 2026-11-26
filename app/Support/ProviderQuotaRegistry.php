<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Throwable;

/**
 * Neutral registry for shipping / payment provider quota meters displayed on
 * the /cpanel operational dashboard without coupling core UI to integration classes.
 */
final class ProviderQuotaRegistry
{
    /** @var array<string, Closure(): ?array<string, mixed>> */
    private array $resolvers = [];

    /**
     * @param  Closure(): ?array<string, mixed>  $resolver
     */
    public function extend(string $key, Closure $resolver): void
    {
        $this->resolvers[$key] = $resolver;
    }

    /**
     * @return list<array{
     *   provider: string,
     *   label: string,
     *   used: int,
     *   limit: int|null,
     *   exhausted: bool,
     *   exhausted_until: string|null,
     *   plan: string|null
     * }>
     */
    public function all(): array
    {
        $items = [];

        foreach ($this->resolvers as $key => $resolver) {
            try {
                $entry = $resolver();
            } catch (Throwable) {
                continue;
            }

            if (! is_array($entry)) {
                continue;
            }

            $items[] = [
                'provider' => (string) ($entry['provider'] ?? $key),
                'label' => (string) ($entry['label'] ?? $key),
                'used' => (int) ($entry['used'] ?? 0),
                'limit' => isset($entry['limit']) ? (int) $entry['limit'] : null,
                'exhausted' => (bool) ($entry['exhausted'] ?? false),
                'exhausted_until' => isset($entry['exhausted_until']) ? (string) $entry['exhausted_until'] : null,
                'plan' => isset($entry['plan']) ? (string) $entry['plan'] : null,
            ];
        }

        return $items;
    }
}
