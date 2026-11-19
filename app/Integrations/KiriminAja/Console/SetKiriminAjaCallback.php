<?php

declare(strict_types=1);

namespace App\Integrations\KiriminAja\Console;

use App\Integrations\KiriminAja\KiriminAja;
use App\Integrations\KiriminAja\Services\KiriminAjaClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Registers this store's callback URL with KiriminAja
 * (POST /api/mitra/set_callback). KiriminAja keeps ONE callback URL per
 * account and replaces it on every call, and rejects URLs without a public
 * A / AAAA record, so run it once per environment after APP_URL is final.
 *
 * Default URL: route('webhooks.kiriminaja.callback') (= {APP_URL}/webhooks/kiriminaja),
 * plus ?token=<KIRIMINAJA_WEBHOOK_SECRET> when a secret is set.
 */
final class SetKiriminAjaCallback extends Command
{
    protected $signature = 'kiriminaja:set-callback
        {--url= : Callback URL to register instead of {APP_URL}/webhooks/kiriminaja}
        {--dry-run : Print the URL without calling KiriminAja}';

    protected $description = 'Register the KiriminAja callback URL ({APP_URL}/webhooks/kiriminaja) for this account';

    public function handle(KiriminAjaClient $client): int
    {
        if (! KiriminAja::configured()) {
            $this->components->error('KiriminAja is not configured: set KIRIMINAJA_API_KEY (and KIRIMINAJA_BASE_URL in production).');

            return self::FAILURE;
        }

        $url = trim((string) $this->option('url'));

        if ($url === '') {
            $url = route('webhooks.kiriminaja.callback');
            $secret = KiriminAja::webhookSecret();

            if ($secret !== '') {
                $url .= '?'.http_build_query(['token' => $secret]);
            }
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false || ! str_starts_with($url, 'https://')) {
            $this->components->error('The callback URL must be an absolute https:// URL reachable from the internet (check APP_URL).');

            return self::FAILURE;
        }

        $display = preg_replace('/token=[^&]+/', 'token=***', $url);

        if ($this->option('dry-run')) {
            $this->components->info("Would register: {$display}");

            return self::SUCCESS;
        }

        try {
            $response = $client->setCallback($url);
        } catch (Throwable $e) {
            $this->components->error('KiriminAja rejected the callback URL: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Callback registered: '.$display.' ('.(KiriminAjaClient::text($response) ?? 'ok').')');

        return self::SUCCESS;
    }
}
