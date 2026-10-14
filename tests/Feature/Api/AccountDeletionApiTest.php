<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Product;
use App\Models\User;
use App\Support\CustomerCheckoutState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Shopper\Cart\Models\Cart;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\Review;
use Tests\TestCase;

final class AccountDeletionApiTest extends TestCase
{
    use RefreshDatabase;

    private function registerAndToken(string $email = 'hapus@oceanmall.test'): array
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Hapus',
            'last_name' => 'Akun',
            'email' => $email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertCreated();

        return [User::query()->findOrFail($response->json('data.id')), (string) $response->json('token')];
    }

    public function test_customer_deletes_their_account_with_a_bearer_token(): void
    {
        [$user, $token] = $this->registerAndToken();

        $this->withToken($token)
            ->deleteJson('/api/v1/auth/me', ['password' => 'password'])
            ->assertOk()
            ->assertJsonPath('message', 'Akunmu sudah dihapus.');

        $this->assertModelMissing($user);
        $this->assertSame(0, PersonalAccessToken::query()->where('tokenable_id', $user->id)->count());
    }

    public function test_wrong_or_missing_password_keeps_the_account(): void
    {
        [$user, $token] = $this->registerAndToken();

        $this->withToken($token)
            ->deleteJson('/api/v1/auth/me', ['password' => 'salah-sekali'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->withToken($token)
            ->deleteJson('/api/v1/auth/me')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertModelExists($user);
    }

    public function test_deleting_requires_a_signed_in_customer(): void
    {
        $this->deleteJson('/api/v1/auth/me', ['password' => 'password'])->assertUnauthorized();
    }

    public function test_the_old_token_stops_working_after_deletion(): void
    {
        [, $token] = $this->registerAndToken();

        $this->withToken($token)->deleteJson('/api/v1/auth/me', ['password' => 'password'])->assertOk();

        // The test client keeps its guard between calls; start from a clean one.
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_personal_data_is_removed_but_orders_survive_without_an_account(): void
    {
        [$user, $token] = $this->registerAndToken();
        $other = User::factory()->create();
        $product = Product::factory()->standard()->create(['published_at' => now()->subDay()]);

        $order = Order::factory()->create([
            'customer_id' => $user->id,
            'status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
            'currency_code' => 'IDR',
        ]);

        $review = fn (User $author) => Review::query()->create([
            'rating' => 5,
            'title' => 'Bagus',
            'content' => 'Mantap',
            'approved' => true,
            'is_recommended' => true,
            'reviewrateable_type' => $product->getMorphClass(),
            'reviewrateable_id' => $product->id,
            'author_type' => $author->getMorphClass(),
            'author_id' => $author->id,
        ]);
        $mine = $review($user);
        $theirs = $review($other);

        $openCart = Cart::query()->create(['customer_id' => $user->id, 'currency_code' => 'IDR']);
        DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => 'x', 'created_at' => now()]);
        $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'test',
            'data' => ['title' => 'Halo'],
        ]);
        resolve(CustomerCheckoutState::class)->put($user, ['shipping_address' => ['first_name' => 'Hapus']]);

        $this->withToken($token)
            ->deleteJson('/api/v1/auth/me', ['password' => 'password'])
            ->assertOk();

        $this->assertModelMissing($user);
        $this->assertModelMissing($mine);
        $this->assertModelExists($theirs);
        $this->assertModelMissing($openCart);
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $user->id)->count());
        $this->assertSame(0, DB::table('password_reset_tokens')->where('email', $user->email)->count());
        $this->assertSame([], resolve(CustomerCheckoutState::class)->get($user));

        // The order is kept as a transaction record, no longer linked to any account.
        $this->assertNull($order->fresh()->customer_id);
    }

    public function test_deletion_is_throttled(): void
    {
        [, $token] = $this->registerAndToken();

        for ($i = 0; $i < 5; $i++) {
            $this->withToken($token)->deleteJson('/api/v1/auth/me', ['password' => 'salah'])->assertUnprocessable();
        }

        $this->withToken($token)->deleteJson('/api/v1/auth/me', ['password' => 'salah'])->assertTooManyRequests();
    }
}
