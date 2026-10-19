<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Checkout\MarkOrderPaidFromKomerce;
use App\Models\User;
use Database\Seeders\DiscountSeeder;
use Database\Seeders\StoreConfigSeeder;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Discount;
use Shopper\Core\Models\Order;
use Shopper\Payment\Enum\TransactionStatus;
use Shopper\Payment\Enum\TransactionType;
use Shopper\Payment\Models\PaymentTransaction;
use Tests\TestCase;

final class LaunchHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seeders_create_nothing_in_production(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');

        $this->artisan('db:seed', ['--class' => DiscountSeeder::class, '--force' => true])->assertSuccessful();
        $this->artisan('db:seed', ['--class' => StoreConfigSeeder::class, '--force' => true])->assertSuccessful();

        $this->assertSame(0, Discount::query()->count());
        $this->assertNull(User::findByEmail('admin@oceanmall.test'));
        $this->assertNull(User::findByEmail('customer@oceanmall.test'));
    }

    public function test_demo_seeders_still_run_outside_production(): void
    {
        $this->artisan('db:seed', ['--class' => DiscountSeeder::class])->assertSuccessful();

        $this->assertSame(1, Discount::query()->count());
    }

    public function test_api_registration_requires_email_verification(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Baru',
            'last_name' => 'Daftar',
            'email' => 'baru@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertCreated();

        $user = User::query()->findOrFail($response->json('data.id'));
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_api_email_change_resets_verification(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'lama@example.com']);
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/auth/profile', [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => 'lama@example.com',
        ])->assertOk();
        $this->assertNotNull($user->fresh()->email_verified_at);
        Notification::assertNothingSent();

        $this->patchJson('/api/v1/auth/profile', [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => 'baru@example.com',
        ])->assertOk();
        $this->assertNull($user->fresh()->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_coupon_attempts_are_throttled(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->assertNotSame(429, $this->postJson('/api/v1/cart/coupon', ['code' => "GUESS{$attempt}"])->status());
        }

        $this->postJson('/api/v1/cart/coupon', ['code' => 'GUESS6'])->assertTooManyRequests();
    }

    public function test_forwarded_ip_is_only_trusted_from_the_local_proxy(): void
    {
        Route::get('/_test/ip', static fn (Request $request): string => (string) $request->ip());

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
            ->withHeader('X-Forwarded-For', '198.51.100.7')
            ->get('/_test/ip')
            ->assertSeeText('203.0.113.5');

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('X-Forwarded-For', '198.51.100.7')
            ->get('/_test/ip')
            ->assertSeeText('198.51.100.7');
    }

    public function test_missing_komerce_base_url_fails_instead_of_falling_back_to_sandbox(): void
    {
        config()->set('komerce.payment_api_key', 'live-key');
        config()->set('komerce.payment_base_url', null);
        Http::fake();

        $order = Order::factory()->create([
            'status' => OrderStatus::New,
            'payment_status' => PaymentStatus::Pending,
            'currency_code' => 'IDR',
        ]);
        PaymentTransaction::query()->create([
            'order_id' => $order->id,
            'driver' => 'komerce',
            'type' => TransactionType::Initiate,
            'status' => TransactionStatus::Pending,
            'amount' => 10000,
            'currency_code' => 'IDR',
            'reference' => 'KOMPAY-NO-BASE',
        ]);

        $this->assertSame('lookup_failed', resolve(MarkOrderPaidFromKomerce::class)->handle('KOMPAY-NO-BASE'));
        Http::assertNothingSent();
    }
}
