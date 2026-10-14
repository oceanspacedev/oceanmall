<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ReviewerDemoAccountApiTest extends TestCase
{
    use RefreshDatabase;

    private function login(string $email): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'password',
        ])->assertOk()->json('token');
    }

    public function test_a_normal_account_keeps_one_device(): void
    {
        $user = User::factory()->create(['email' => 'pelanggan@example.com']);

        $first = $this->login($user->email);
        $this->login($user->email);

        $this->app['auth']->forgetGuards();
        $this->withToken($first)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_a_reviewer_demo_account_stays_signed_in_on_every_device(): void
    {
        config(['auth.reviewer_demo_emails' => ['Reviewer@Example.com']]);
        $user = User::factory()->create(['email' => 'reviewer@example.com']);

        $first = $this->login($user->email);
        $second = $this->login($user->email);

        $this->app['auth']->forgetGuards();
        $this->withToken($first)->getJson('/api/v1/auth/me')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($second)->getJson('/api/v1/auth/me')->assertOk();
    }
}
