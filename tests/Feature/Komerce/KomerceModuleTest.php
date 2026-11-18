<?php

declare(strict_types=1);

namespace Tests\Feature\Komerce;

use App\Integrations\Komerce\Database\KomercePaymentMethodSeeder;
use App\Integrations\Komerce\Exceptions\KomercePaidAfterCancellation;
use App\Integrations\Komerce\Http\Controllers\KomerceDeliveryWebhookController;
use App\Integrations\Komerce\Http\Controllers\KomercePaymentWebhookController;
use App\Integrations\Komerce\Http\Controllers\KomerceQrislyWebhookController;
use App\Integrations\Komerce\KomerceServiceProvider;
use App\Integrations\Komerce\Payment\KomerceDriver;
use App\Integrations\Komerce\Shipping\KomerceShippingDriver;
use App\Integrations\Komerce\Shipping\RajaOngkirDriver;
use App\Support\PaymentLogos;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Psr\Log\LogLevel;
use ReflectionProperty;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Payment\Facades\Payment;
use Shopper\Shipping\Facades\Shipping;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Komerce is a self-contained module (app/Integrations/Komerce) wired only
 * by KomerceServiceProvider. These tests pin what the provider owns and that
 * generic store code never reaches into the module, so removing the provider
 * line (and the folder) leaves a pure-Shopper store.
 */
final class KomerceModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_provider_is_registered_as_a_module(): void
    {
        $this->assertContains(KomerceServiceProvider::class, require base_path('bootstrap/providers.php'));
        $this->assertInstanceOf(KomerceServiceProvider::class, $this->app->getProvider(KomerceServiceProvider::class));
    }

    public function test_it_registers_its_shopper_drivers(): void
    {
        $this->assertContains(KomerceDriver::CODE, Payment::availableDrivers());
        $this->assertInstanceOf(KomerceDriver::class, Payment::driver(KomerceDriver::CODE));

        $this->assertContains(RajaOngkirDriver::CODE, Shipping::availableDrivers());
        $this->assertContains(KomerceShippingDriver::CODE, Shipping::availableDrivers());
        $this->assertInstanceOf(RajaOngkirDriver::class, Shipping::driver(RajaOngkirDriver::CODE));
        $this->assertInstanceOf(KomerceShippingDriver::class, Shipping::driver(KomerceShippingDriver::CODE));
    }

    public function test_it_merges_its_config_as_the_single_source_of_credentials(): void
    {
        // Defaults come from the module config (sandbox host outside production).
        $this->assertSame('https://api-sandbox.collaborator.komerce.id/user', config('komerce.payment_base_url'));
        $this->assertSame('https://rajaongkir.komerce.id', config('komerce.rajaongkir.cost_base_url'));
        $this->assertSame('', config('komerce.payment_api_key'));

        // Shopper's driver entries keep only the switch: no duplicated credentials.
        $this->assertSame(['enabled'], array_keys(config('shopper.payment.drivers.komerce')));
        $this->assertSame(['enabled'], array_keys(config('shopper.shipping.drivers.komerce')));
        $this->assertSame(['enabled'], array_keys(config('shopper.shipping.drivers.rajaongkir')));
        $this->assertFileDoesNotExist(config_path('komerce.php'), 'The module ships its config; publish it only to override.');
    }

    public function test_it_owns_the_webhook_routes_with_unchanged_urls_and_names(): void
    {
        $expected = [
            'webhooks.komerce.payment' => ['webhooks/komerce/payment', KomercePaymentWebhookController::class],
            'webhooks.komerce.delivery' => ['webhooks/komerce/delivery', KomerceDeliveryWebhookController::class],
            'webhooks.komerce.qrisly' => ['webhooks/komerce/qrisly', KomerceQrislyWebhookController::class],
        ];

        foreach ($expected as $name => [$uri, $controller]) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "{$name} is registered");
            $this->assertSame($uri, $route->uri());
            $this->assertSame($controller, $route->getActionName());
            $this->assertContains('web', $route->gatherMiddleware());
        }

        // Still a uniform acknowledgement while the product is off.
        $this->postJson('/webhooks/komerce/payment', ['order_id' => 'x'])
            ->assertOk()
            ->assertJsonPath('status', 'disabled');
    }

    public function test_it_registers_its_commands(): void
    {
        $commands = Artisan::all();

        foreach (['komerce:capabilities', 'komerce:uat', 'komerce:simulate-payment'] as $command) {
            $this->assertArrayHasKey($command, $commands, "{$command} is registered by the module");
        }
    }

    public function test_plan_detection_is_scheduled_only_while_a_komerce_product_is_configured(): void
    {
        $event = $this->scheduledEvent('komerce:capabilities');

        $this->assertNotNull($event, 'komerce:capabilities is scheduled by the module');
        $this->assertSame('5 0 * * *', $event->expression);
        $this->assertFalse($event->filtersPass($this->app), 'keyless: no work scheduled');

        config()->set('komerce.payment_api_key', 'test-payment-key');
        $this->assertTrue($event->filtersPass($this->app));
    }

    public function test_paid_after_cancellation_is_reported_as_critical(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);
        $levels = (new ReflectionProperty($handler, 'levels'))->getValue($handler);

        $this->assertSame(LogLevel::CRITICAL, $levels[KomercePaidAfterCancellation::class] ?? null);
    }

    public function test_it_renders_its_cpanel_views_from_its_own_namespace(): void
    {
        $this->assertTrue(view()->exists('komerce::livewire.order-shipping'));
        $this->assertTrue(view()->exists('komerce::partials.order-shipping-hook'));
    }

    public function test_komerce_payment_slugs_resolve_to_local_logo_files_through_the_module_source(): void
    {
        $logos = resolve(PaymentLogos::class);

        $qris = new PaymentMethod(['slug' => 'komerce-qris', 'driver' => KomerceDriver::CODE]);
        $this->assertStringContainsString('images/payments/qris.svg', (string) $logos->forMethod($qris));

        $bca = new PaymentMethod(['slug' => 'komerce-va-bca', 'driver' => KomerceDriver::CODE]);
        $this->assertStringContainsString('images/payments/bca.svg', (string) $logos->forMethod($bca));

        // The prefix mapping is the module's: another driver's slug is not rewritten.
        $foreign = new PaymentMethod(['slug' => 'komerce-qris', 'driver' => 'acme']);
        $this->assertNull($logos->forMethod($foreign));
    }

    public function test_it_contributes_its_payment_methods_to_the_store_seeder(): void
    {
        $this->seed(KomercePaymentMethodSeeder::class);

        $this->assertSame(
            ['komerce-qris', 'komerce-va-bca', 'komerce-va-bni', 'komerce-va-bri', 'komerce-va-mandiri', 'komerce-va-permata', 'komerce-va-qris'],
            PaymentMethod::query()->where('driver', 'komerce')->orderBy('slug')->pluck('slug')->all(),
        );
        $this->assertSame(0, PaymentMethod::query()->where('driver', 'komerce')->where('is_enabled', true)->count());
        $metadata = PaymentMethod::query()->where('slug', 'komerce-va-bri')->firstOrFail()->metadata;
        $metadata = is_string($metadata) ? json_decode($metadata, true) : $metadata;
        $this->assertSame('BRI', data_get($metadata, 'channel_code'));
    }

    public function test_generic_store_code_never_references_the_module(): void
    {
        $offenders = [];

        $finder = (new Finder)->files()->name('*.php')
            ->in([app_path(), base_path('routes'), base_path('bootstrap'), config_path(), database_path(), resource_path('views')])
            ->exclude(['Integrations', 'cache'])
            ->notPath('#^providers\.php$#');

        foreach ($finder as $file) {
            $contents = $file->getContents();

            if (preg_match('/Integrations\\\\Komerce|\bkomerce_[a-z_]*enabled\s*\(|\bqrisly_enabled\s*\(/', $contents) === 1) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders, 'Only bootstrap/providers.php may name the Komerce module.');
        $this->assertFalse(function_exists('komerce_enabled'), 'Readiness lives in App\Integrations\Komerce\Komerce, not in global helpers.');
    }

    public function test_env_example_leaves_provider_base_urls_to_the_production_guard(): void
    {
        $env = (string) file_get_contents(base_path('.env.example'));

        foreach (['KOMERCE_PAYMENT_BASE_URL', 'RAJAONGKIR_DELIVERY_BASE_URL', 'KOMERCE_QRISLY_BASE_URL'] as $key) {
            $this->assertDoesNotMatchRegularExpression("/^{$key}=/m", $env, "{$key} must stay commented so production has no sandbox default");
        }

        $this->assertStringNotContainsString('KOMERCE_API_KEY"', (string) file_get_contents(base_path('phpunit.xml')));
    }

    private function scheduledEvent(string $command): ?ScheduledEvent
    {
        foreach ($this->app->make(Schedule::class)->events() as $event) {
            if (str_contains((string) $event->command, $command)) {
                return $event;
            }
        }

        return null;
    }
}
