<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The cart routes (`auth.optional`) serve guests and signed-in customers alike, but a Bearer token
 * that no longer resolves is an ended session, not a guest: it gets the same 401 as auth:sanctum
 * so the app signs out instead of showing the empty guest cart.
 */
final class OptionalSanctumAuthTest extends TestCase
{
    use RefreshDatabase;

    private function login(string $email): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'password',
        ])->assertOk()->json('token');
    }

    public function test_a_request_without_a_token_is_served_as_a_guest(): void
    {
        $this->withHeader('X-Cart-Token', (string) Str::uuid())
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.lines', []);
    }

    public function test_a_valid_token_is_served_as_the_customer(): void
    {
        $user = User::factory()->create(['email' => 'pelanggan@example.com']);
        $token = $this->login($user->email);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->withHeader('X-Cart-Token', (string) Str::uuid())
            ->getJson('/api/v1/cart')
            ->assertOk();
    }

    public function test_a_token_revoked_by_a_login_on_another_device_is_rejected(): void
    {
        $user = User::factory()->create(['email' => 'pelanggan@example.com']);
        $first = $this->login($user->email);
        $this->login($user->email);

        $this->app['auth']->forgetGuards();
        $this->withToken($first)
            ->withHeader('X-Cart-Token', (string) Str::uuid())
            ->getJson('/api/v1/cart')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_a_revoked_token_cannot_write_to_the_guest_cart(): void
    {
        $user = User::factory()->create(['email' => 'pelanggan@example.com']);
        $token = $this->login($user->email);
        $user->tokens()->delete();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)
            ->withHeader('X-Cart-Token', (string) Str::uuid())
            ->deleteJson('/api/v1/cart')
            ->assertUnauthorized();
    }

    public function test_an_unknown_token_is_rejected(): void
    {
        $this->withToken('1|not-a-real-token')
            ->getJson('/api/v1/cart')
            ->assertUnauthorized();
    }
}
