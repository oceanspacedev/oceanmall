<?php

declare(strict_types=1);

namespace Tests\Feature\Komerce;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SimulateKomercePaymentCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_refuses_outside_the_local_environment(): void
    {
        $this->artisan('komerce:simulate-payment', ['order' => 'ORD-1'])
            ->expectsOutput('Only available when APP_ENV=local.')
            ->assertFailed();
    }

    public function test_it_refuses_against_a_non_sandbox_payment_host(): void
    {
        $this->app['env'] = 'local';
        config()->set('komerce.payment_base_url', 'https://api.collaborator.komerce.id/user');

        $this->artisan('komerce:simulate-payment', ['order' => 'ORD-1'])
            ->expectsOutputToContain('is not a sandbox host')
            ->assertFailed();
    }
}
