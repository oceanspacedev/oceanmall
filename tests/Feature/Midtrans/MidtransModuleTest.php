<?php

declare(strict_types=1);

namespace Tests\Feature\Midtrans;

use App\Integrations\Midtrans\Database\MidtransPaymentMethodSeeder;
use App\Integrations\Midtrans\Exceptions\MidtransPaymentNeedsReview;
use App\Integrations\Midtrans\Http\Controllers\MidtransNotificationController;
use App\Integrations\Midtrans\Midtrans;
use App\Integrations\Midtrans\MidtransServiceProvider;
use App\Integrations\Midtrans\Payment\MidtransDriver;
use App\Payment\Contracts\ExpiresPayments;
use App\Payment\Contracts\ProvidesPaymentAction;
use App\Payment\Contracts\ReconcilesPayments;
use App\Payment\Contracts\RetriesPayments;
use App\Payment\PaymentMethodCatalog;
use App\Support\PaymentLogos;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LogLevel;
use ReflectionProperty;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Payment\Facades\Payment;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Midtrans is a self-contained module (app/Integrations/Midtrans) wired only
 * by MidtransServiceProvider: these tests pin what it owns, that it is inert
 * without a key, and that generic store code never reaches into it.
 */
final class MidtransModuleTest extends TestCase
{
    use InteractsWithMidtrans;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMidtrans();
    }

    public function test_the_provider_is_registered_as_a_module(): void
    {
        $this->assertContains(MidtransServiceProvider::class, require base_path('bootstrap/providers.php'));
        $this->assertInstanceOf(MidtransServiceProvider::class, $this->app->getProvider(MidtransServiceProvider::class));
    }

    public function test_it_registers_its_shopper_payment_driver_with_the_store_contracts(): void
    {
        $this->assertContains('midtrans', Payment::availableDrivers());

        $driver = Payment::driver('midtrans');

        $this->assertInstanceOf(MidtransDriver::class, $driver);
        $this->assertInstanceOf(ProvidesPaymentAction::class, $driver);
        $this->assertInstanceOf(ReconcilesPayments::class, $driver);
        $this->assertInstanceOf(RetriesPayments::class, $driver);
        $this->assertInstanceOf(ExpiresPayments::class, $driver);
        $this->assertSame('Midtrans', $driver->name());
        $this->assertFalse($driver->supportsRefunds());
    }

    public function test_it_merges_its_config_as_the_single_source_of_credentials(): void
    {
        $this->assertSame('https://app.sandbox.midtrans.com', config('midtrans.hosts.sandbox.snap'));
        $this->assertSame('https://api.sandbox.midtrans.com', config('midtrans.hosts.sandbox.api'));
        $this->assertSame('https://app.midtrans.com', config('midtrans.hosts.production.snap'));
        $this->assertSame('https://api.midtrans.com', config('midtrans.hosts.production.api'));
        $this->assertSame(['enabled'], array_keys(config('shopper.payment.drivers.midtrans')));
        $this->assertFileDoesNotExist(config_path('midtrans.php'), 'The module ships its config; publish it only to override.');

        $this->assertArrayHasKey(
            dirname((new \ReflectionClass(MidtransServiceProvider::class))->getFileName()).'/config/midtrans.php',
            ServiceProvider::pathsToPublish(MidtransServiceProvider::class, 'midtrans-config'),
        );
    }

    public function test_it_owns_the_notification_route(): void
    {
        $route = Route::getRoutes()->getByName('webhooks.midtrans.notification');

        $this->assertNotNull($route);
        $this->assertSame('webhooks/midtrans/notification', $route->uri());
        $this->assertSame(['POST'], $route->methods());
        $this->assertSame(MidtransNotificationController::class, $route->getActionName());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('throttle:120,1', $route->gatherMiddleware());
    }

    public function test_without_a_key_the_module_is_inert(): void
    {
        Http::fake();

        $driver = Payment::driver('midtrans');
        $this->assertFalse($driver->isConfigured());
        $this->assertFalse((new PaymentMethodCatalog)->driverReady('midtrans'));

        $method = PaymentMethod::query()->create([
            'title' => 'QRIS', 'slug' => 'midtrans-qris', 'driver' => 'midtrans', 'is_enabled' => true,
            'metadata' => json_encode(['channel_code' => 'other_qris']),
        ]);
        $this->assertFalse((new PaymentMethodCatalog)->available($method), 'Keyless: Midtrans methods are not offered at checkout.');

        $this->postJson('/webhooks/midtrans/notification', ['order_id' => 'ORD-1', 'transaction_status' => 'settlement'])
            ->assertOk()
            ->assertExactJson(['status' => 'disabled']);

        Http::assertNothingSent();
    }

    public function test_the_operator_switch_keeps_methods_off_even_with_a_key(): void
    {
        $this->withMidtrans();
        $this->assertTrue((new PaymentMethodCatalog)->driverReady('midtrans'));

        config()->set('shopper.payment.drivers.midtrans.enabled', 'off');
        $this->assertFalse((new PaymentMethodCatalog)->driverReady('midtrans'));
    }

    public function test_it_contributes_its_payment_methods_to_the_store_seeder(): void
    {
        $this->seed(MidtransPaymentMethodSeeder::class);

        $this->assertSame(
            ['midtrans-bca-va', 'midtrans-bni-va', 'midtrans-bri-va', 'midtrans-credit-card', 'midtrans-gopay', 'midtrans-mandiri-bill', 'midtrans-other-va', 'midtrans-permata-va', 'midtrans-qris', 'midtrans-shopeepay'],
            PaymentMethod::query()->where('driver', 'midtrans')->orderBy('slug')->pluck('slug')->all(),
        );
        $this->assertSame(0, PaymentMethod::query()->where('driver', 'midtrans')->where('is_enabled', true)->count(), 'Keyless seed: all off.');

        $metadata = PaymentMethod::query()->where('slug', 'midtrans-qris')->firstOrFail()->metadata;
        $metadata = is_string($metadata) ? json_decode($metadata, true) : $metadata;
        $this->assertSame('other_qris', data_get($metadata, 'channel_code'));
    }

    public function test_seeding_with_a_key_enables_every_method_but_cards_and_keeps_staff_choices(): void
    {
        $this->withMidtrans();

        $this->seed(MidtransPaymentMethodSeeder::class);

        $this->assertSame(['midtrans-credit-card'], PaymentMethod::query()->where('driver', 'midtrans')->where('is_enabled', false)->pluck('slug')->all());

        PaymentMethod::query()->where('slug', 'midtrans-gopay')->update(['is_enabled' => false]);
        $this->seed(MidtransPaymentMethodSeeder::class);
        $this->assertFalse((bool) PaymentMethod::query()->where('slug', 'midtrans-gopay')->value('is_enabled'));
    }

    public function test_channel_codes_resolve_to_local_logo_files(): void
    {
        $logos = resolve(PaymentLogos::class);
        $method = static fn (string $channel, string $driver = 'midtrans'): PaymentMethod => new PaymentMethod([
            'slug' => 'midtrans-x', 'driver' => $driver, 'metadata' => json_encode(['channel_code' => $channel]),
        ]);

        $this->assertStringContainsString('images/payments/bca.svg', (string) $logos->forMethod($method('bca_va')));
        $this->assertStringContainsString('images/payments/mandiri.svg', (string) $logos->forMethod($method('echannel')));
        $this->assertStringContainsString('images/payments/qris.svg', (string) $logos->forMethod($method('other_qris')));
        $this->assertNull($logos->forMethod($method('gopay')));
        $this->assertNull($logos->forMethod($method('bca_va', 'acme')), 'Only Midtrans methods are mapped.');
    }

    public function test_unapplicable_money_is_reported_as_critical(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);
        $levels = (new ReflectionProperty($handler, 'levels'))->getValue($handler);

        $this->assertSame(LogLevel::CRITICAL, $levels[MidtransPaymentNeedsReview::class] ?? null);
    }

    public function test_generic_store_code_never_references_the_module(): void
    {
        $offenders = [];

        $finder = (new Finder)->files()->name('*.php')
            ->in([app_path(), base_path('routes'), base_path('bootstrap'), config_path(), database_path(), resource_path('views')])
            ->exclude(['Integrations', 'cache'])
            ->notPath('#^providers\.php$#');

        foreach ($finder as $file) {
            if (preg_match('/Integrations\\\\Midtrans/', $file->getContents()) === 1) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders, 'Only bootstrap/providers.php may name the Midtrans module.');
        $this->assertSame('midtrans', Midtrans::CODE);
    }
}
