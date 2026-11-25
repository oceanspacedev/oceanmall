<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Shipping\RefreshShipmentTracking;
use App\Models\OrderShipment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Shipping\Contracts\TracksShipments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Fortify;
use Laravel\Sanctum\Sanctum;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;
use Shopper\Core\Models\Category;
use Shopper\Core\Models\Currency;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\Price;
use Shopper\Shipping\DataTransferObjects\Address;
use Shopper\Shipping\DataTransferObjects\TrackingInfo;
use Shopper\Shipping\Drivers\Driver;
use Shopper\Shipping\Facades\Shipping;
use Tests\TestCase;

/**
 * Security findings of the production-readiness QA:
 *
 * - storefront pages serialised the merchant's cost price to every visitor;
 * - the mobile API login ignored two-step verification;
 * - a password reset / change on the website left the app's tokens valid,
 *   and tokens never expired;
 * - the customer's "track" button asked the provider on every tap, spending
 *   the quota checkout quotes share.
 */
final class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake();
    }

    public function test_storefront_pages_never_serialise_the_cost_price(): void
    {
        $currency = Currency::query()->where('code', shopper_currency())->first()
            ?? Currency::factory()->create(['code' => shopper_currency(), 'name' => 'Rupiah', 'symbol' => 'Rp', 'format' => 'Rp1.234']);
        $category = Category::query()->create(['name' => 'Handphone', 'slug' => 'handphone', 'is_enabled' => true]);
        $related = $this->pricedProduct('Related Phone', 'related-phone', $currency);
        $product = $this->pricedProduct('Leaky Phone', 'leaky-phone', $currency);
        $product->categories()->attach($category->id);
        $product->relatedProducts()->attach($related->id);
        // A draft related in cpanel is not public yet.
        $draft = $this->pricedProduct('Unreleased Phone', 'unreleased-phone', $currency);
        $draft->forceFill(['published_at' => now()->addMonth()])->save();
        $product->relatedProducts()->attach($draft->id);
        // A variant carries its own price row.
        $variant = ProductVariant::query()->create(['product_id' => $product->id, 'name' => 'Blue 128GB', 'sku' => 'LEAKY-BLUE']);
        Price::query()->create([
            'priceable_type' => $variant->getMorphClass(),
            'priceable_id' => $variant->id,
            'amount' => 888000,
            'cost_amount' => 751234,
            'currency_id' => $currency->id,
        ]);

        $pages = [
            route('shop.product', $product),
            route('shop.index'),
            route('shop.category', $category),
            route('shop.search', ['q' => 'Leaky']),
            route('home'),
        ];

        foreach ($pages as $url) {
            $response = $this->get($url)->assertOk();
            $html = (string) $response->getContent();

            $this->assertStringNotContainsString('cost_amount', $html, $url);
            $this->assertStringNotContainsString('750000', $html, $url);
            $this->assertStringNotContainsString('751234', $html, $url);
            $this->assertStringNotContainsString('Unreleased Phone', $html, $url);
        }

        $productPage = (string) $this->get(route('shop.product', $product))->getContent();
        $this->assertStringContainsString('888000', $productPage, 'The variant and its selling price are on the page.');
        $this->assertStringContainsString('Related Phone', $productPage);

        // The selling price still reaches the page.
        $this->assertStringContainsString('999000', (string) $this->get(route('shop.product', $product))->getContent());

        // Staff code reads the attribute itself (cpanel pricing form / table).
        $price = $product->fresh()->prices->firstOrFail();
        $this->assertSame(750000, (int) $price->cost_amount);
        $this->assertArrayNotHasKey('cost_amount', $price->toArray());
    }

    public function test_api_login_requires_the_two_step_code_when_the_account_uses_it(): void
    {
        $secret = resolve(TwoFactorAuthenticationProvider::class)->generateSecretKey();
        $user = User::factory()->create();
        $user->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['recovery-one', 'recovery-two'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $login = fn (array $extra = []) => $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password', ...$extra]);

        // A wrong password never reveals that the account uses two-step verification.
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertStatus(422)
            ->assertJsonMissingPath('two_factor_required');

        $login()->assertStatus(422)->assertJsonPath('two_factor_required', true)->assertJsonMissingPath('token');
        $login(['code' => '000000'])->assertStatus(422)->assertJsonPath('two_factor_required', true)->assertJsonMissingPath('token');
        $this->assertSame(0, $user->tokens()->count());

        // An app build without the code field never sends the key.
        $login()->assertJsonPath('message', 'Akun ini memakai verifikasi dua langkah. Perbarui aplikasi OceanMall ke versi terbaru untuk masuk.');
        $login(['code' => ''])->assertJsonPath('message', 'Masukkan kode verifikasi dua langkah dari aplikasi autentikator.');

        $login(['code' => (new Google2FA)->getCurrentOtp($secret)])->assertOk()->assertJsonStructure(['token']);

        // Authenticator apps show the code as "123 456".
        $this->travel(2)->minutes();
        $otp = (new Google2FA)->getCurrentOtp($secret);
        $login(['code' => substr($otp, 0, 3).' '.substr($otp, 3)])->assertOk();

        // A recovery code works once.
        $login(['code' => 'recovery-one'])->assertOk()->assertJsonStructure(['token']);
        $login(['code' => 'recovery-one'])->assertStatus(422);
        $this->assertNotContains('recovery-one', $user->fresh()->recoveryCodes());
    }

    public function test_two_step_codes_cannot_be_guessed_by_volume_and_enabling_it_signs_the_app_out(): void
    {
        $secret = resolve(TwoFactorAuthenticationProvider::class)->generateSecretKey();
        $user = User::factory()->create();
        $user->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['recovery-one'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        // Five wrong codes for one account, each from another address.
        foreach (range(1, 5) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])
                ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password', 'code' => '000000'])
                ->assertStatus(422);
        }

        // The account pauses, even for the right code from a new address.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password', 'code' => (new Google2FA)->getCurrentOtp($secret)])
            ->assertStatus(429)
            ->assertHeader('Retry-After');
        $this->assertSame(0, $user->tokens()->count());

        $this->travel(11)->minutes();
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password', 'code' => (new Google2FA)->getCurrentOtp($secret)])
            ->assertOk();

        // Confirming two-step verification on the website revokes tokens issued before it.
        $this->assertSame(1, $user->tokens()->count());
        event(new TwoFactorAuthenticationConfirmed($user));
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_api_login_without_two_step_verification_is_unchanged(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonStructure(['token']);
    }

    public function test_changing_or_resetting_the_password_on_the_website_signs_the_app_out(): void
    {
        $user = User::factory()->create();
        $user->createToken('mobile');
        $this->assertSame(1, $user->tokens()->count());

        $this->actingAs($user)
            ->from(route('security.edit'))
            ->put(route('user-password.update'), [
                'current_password' => 'password',
                'password' => 'a-New-password-9!',
                'password_confirmation' => 'a-New-password-9!',
            ])
            ->assertSessionHasNoErrors();
        $this->assertSame(0, $user->tokens()->count(), 'Password changed on the website.');

        // The session that changed the password stays signed in.
        $this->get('/account/orders')->assertOk();

        $user->createToken('mobile');
        resolve(ResetUserPassword::class)->reset($user, [
            'password' => 'another-New-password-9!',
            'password_confirmation' => 'another-New-password-9!',
        ]);
        $this->assertSame(0, $user->tokens()->count(), 'Password reset by email link.');

        // Any other path that sets a password (cpanel, a command) does too.
        $user->createToken('mobile');
        $user->fresh()->update(['password' => 'set-Elsewhere-9!']);
        $this->assertSame(0, $user->tokens()->count());

        // The app's own change-password keeps the device that made it.
        $keep = $user->createToken('mobile');
        $user->createToken('mobile');
        $this->app['auth']->forgetGuards();
        $this->withToken($keep->plainTextToken)
            ->putJson('/api/v1/auth/password', [
                'current_password' => 'set-Elsewhere-9!',
                'password' => 'from-The-app-9!',
                'password_confirmation' => 'from-The-app-9!',
            ])
            ->assertOk();
        $this->assertSame([$keep->accessToken->getKey()], $user->tokens()->pluck('id')->all());
    }

    public function test_another_web_session_is_signed_out_when_the_password_changes(): void
    {
        $user = User::factory()->create();

        // A real session opened before the change (it stores the password hash).
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->app['auth']->forgetGuards();
        $this->get('/account/orders')->assertOk();

        // The password changes somewhere else.
        User::query()->findOrFail($user->id)->update(['password' => 'changed-Elsewhere-9!']);

        $this->app['auth']->forgetGuards();
        $this->get('/account/orders')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_app_tokens_expire(): void
    {
        $this->assertSame(60 * 24 * 90, (int) config('sanctum.expiration'), 'Default when SANCTUM_EXPIRATION is not set.');

        $user = User::factory()->create();
        $token = $user->createToken('mobile')->plainTextToken;
        $me = function () use ($token) {
            $this->app['auth']->forgetGuards();

            return $this->withToken($token)->getJson('/api/v1/auth/me');
        };

        $me()->assertOk();
        $this->travel(89)->days();
        $me()->assertOk();
        $this->travel(2)->days();
        $me()->assertUnauthorized();
    }

    public function test_the_customers_track_button_asks_the_provider_once_per_cooldown(): void
    {
        $driver = new CountingTrackDriver;
        Shipping::extend('tracksec', static fn (): CountingTrackDriver => $driver);
        config()->set('shopper.shipping.drivers.tracksec.enabled', true);

        $customer = User::factory()->create();
        $order = Order::factory()->create(['customer_id' => $customer->id, 'currency_code' => 'IDR']);
        $shipment = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => Inventory::factory()->create()->id,
            'driver' => 'tracksec',
            'carrier_code' => 'jne',
            'carrier_name' => 'JNE',
            'service_code' => 'REG',
            'service_name' => 'Regular',
            'cost' => 18000,
            'currency_code' => 'IDR',
            'status' => 'labeled',
            'awb' => 'AWB-SEC-1',
        ]);

        $track = fn () => $this->actingAs($customer)
            ->from(route('account.orders.show', $order))
            ->post(route('account.orders.shipments.track', ['order' => $order, 'shipment' => $shipment]))
            ->assertRedirect(route('account.orders.show', $order));

        $track();
        $track();
        $this->assertSame(1, $driver->calls, 'The second tap is answered from the stored tracking.');

        // The app shares the cooldown.
        Sanctum::actingAs($customer);
        $this->postJson("/api/v1/orders/{$order->number}/shipments/{$shipment->id}/track")->assertOk();
        $this->assertSame(1, $driver->calls);

        // After the cooldown the provider is asked again.
        $this->travel(RefreshShipmentTracking::CUSTOMER_COOLDOWN_MINUTES + 1)->minutes();
        $this->postJson("/api/v1/orders/{$order->number}/shipments/{$shipment->id}/track")->assertOk();
        $this->assertSame(2, $driver->calls);

        // A delivered parcel is final.
        $this->travel(RefreshShipmentTracking::CUSTOMER_COOLDOWN_MINUTES + 1)->minutes();
        $shipment->forceFill(['status' => 'delivered'])->save();
        $this->postJson("/api/v1/orders/{$order->number}/shipments/{$shipment->id}/track")->assertOk();
        $this->assertSame(2, $driver->calls);

        // A cancelled parcel is final too.
        $shipment->forceFill(['status' => 'cancelled'])->save();
        $this->postJson("/api/v1/orders/{$order->number}/shipments/{$shipment->id}/track")
            ->assertOk()
            ->assertJsonPath('tracking_refreshed', false);
        $this->assertSame(2, $driver->calls);

        // A failed lookup does not spend the cooldown: the next tap tries again.
        $shipment->forceFill(['status' => 'labeled'])->save();
        $this->travel(RefreshShipmentTracking::CUSTOMER_COOLDOWN_MINUTES + 1)->minutes();
        $driver->fail = true;
        $this->postJson("/api/v1/orders/{$order->number}/shipments/{$shipment->id}/track")->assertStatus(422);
        $driver->fail = false;
        $this->postJson("/api/v1/orders/{$order->number}/shipments/{$shipment->id}/track")
            ->assertOk()
            ->assertJsonPath('tracking_refreshed', true);
        $this->assertSame(4, $driver->calls);
        $driver->calls = 2;

        // Staff / webhook / schedule callers are not limited.
        $shipment->forceFill(['status' => 'labeled'])->save();
        resolve(RefreshShipmentTracking::class)->handle($shipment);
        $this->assertSame(3, $driver->calls);
    }

    public function test_track_taps_are_limited_per_customer_with_a_readable_answer(): void
    {
        $customer = User::factory()->create();
        $other = User::factory()->create();
        $order = Order::factory()->create(['customer_id' => $customer->id, 'currency_code' => 'IDR']);
        $shipment = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => Inventory::factory()->create()->id,
            'driver' => 'manual',
            'carrier_code' => 'jne',
            'carrier_name' => 'JNE',
            'service_code' => 'REG',
            'service_name' => 'Regular',
            'cost' => 18000,
            'currency_code' => 'IDR',
            'status' => 'delivered',
            'awb' => 'AWB-SEC-2',
        ]);
        $url = "/api/v1/orders/{$order->number}/shipments/{$shipment->id}/track";

        Sanctum::actingAs($customer);

        foreach (range(1, 6) as $tap) {
            $this->postJson($url)->assertOk();
        }

        $this->postJson($url)
            ->assertTooManyRequests()
            ->assertJsonPath('message', 'Terlalu sering memperbarui pelacakan. Coba lagi nanti.');

        // The web page gets its own inline error, not a bare 429 page.
        $this->actingAs($customer)
            ->from(route('account.orders.show', $order))
            ->post(route('account.orders.shipments.track', ['order' => $order, 'shipment' => $shipment]))
            ->assertRedirect(route('account.orders.show', $order))
            ->assertSessionHasErrors(['tracking' => 'Terlalu sering memperbarui pelacakan. Coba lagi nanti.']);

        // Per customer, not per address: someone else on the same network is not limited.
        Sanctum::actingAs($other);
        $theirs = Order::factory()->create(['customer_id' => $other->id, 'currency_code' => 'IDR']);
        $this->postJson("/api/v1/orders/{$theirs->number}/shipments/{$shipment->id}/track")->assertNotFound();
    }

    public function test_both_track_routes_are_rate_limited(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn ($route): bool => str_ends_with($route->uri(), 'shipments/{shipment}/track'));

        $this->assertCount(2, $routes, 'Web and API.');

        foreach ($routes as $route) {
            $this->assertContains('throttle:shipment-track', $route->gatherMiddleware(), $route->uri());
        }
    }

    private function pricedProduct(string $name, string $slug, Currency $currency): Product
    {
        $product = Product::factory()->standard()->create([
            'name' => $name,
            'slug' => $slug,
            'published_at' => now()->subDay(),
        ]);

        Price::query()->create([
            'priceable_type' => $product->getMorphClass(),
            'priceable_id' => $product->id,
            'amount' => 999000,
            'cost_amount' => 750000,
            'currency_id' => $currency->id,
        ]);

        return $product;
    }
}

final class CountingTrackDriver extends Driver implements TracksShipments
{
    public int $calls = 0;

    public bool $fail = false;

    public function code(): string
    {
        return 'tracksec';
    }

    public function name(): string
    {
        return 'Track Sec';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function supportsRealTimeRates(): bool
    {
        return false;
    }

    public function supportsLabels(): bool
    {
        return false;
    }

    public function supportsTracking(): bool
    {
        return true;
    }

    public function calculateRates(Address $from, Address $to, array $packages): Collection
    {
        return collect();
    }

    public function trackShipment(OrderShipment $shipment): TrackingInfo
    {
        $this->calls++;

        if ($this->fail) {
            throw new RuntimeException('provider down');
        }

        return new TrackingInfo(trackingNumber: (string) $shipment->awb, status: 'in_transit');
    }
}
