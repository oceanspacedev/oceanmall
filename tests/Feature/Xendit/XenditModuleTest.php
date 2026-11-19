<?php

declare(strict_types=1);

namespace Tests\Feature\Xendit;

use App\Integrations\Xendit\Database\XenditPaymentMethodSeeder;
use App\Integrations\Xendit\Exceptions\XenditPaidAfterCancellation;
use App\Integrations\Xendit\Http\Controllers\XenditInvoiceWebhookController;
use App\Integrations\Xendit\Payment\XenditDriver;
use App\Integrations\Xendit\XenditServiceProvider;
use App\Support\PaymentLogos;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Psr\Log\LogLevel;
use ReflectionProperty;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Payment\Facades\Payment;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Xendit is a self-contained module (app/Integrations/Xendit) wired only by
 * XenditServiceProvider. These tests pin what the provider owns and that
 * generic store code never reaches into the module.
 */
final class XenditModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_provider_is_registered_as_a_module(): void
    {
        $this->assertContains(XenditServiceProvider::class, require base_path('bootstrap/providers.php'));
        $this->assertInstanceOf(XenditServiceProvider::class, $this->app->getProvider(XenditServiceProvider::class));
    }

    public function test_it_registers_its_shopper_payment_driver(): void
    {
        $this->assertContains(XenditDriver::CODE, Payment::availableDrivers());
        $this->assertInstanceOf(XenditDriver::class, Payment::driver(XenditDriver::CODE));
    }

    public function test_it_merges_its_config_as_the_single_source_of_credentials(): void
    {
        $this->assertSame('', config('xendit.secret_key'));
        $this->assertSame('', config('xendit.callback_token'));
        $this->assertSame('https://api.xendit.co', config('xendit.base_url'));
        $this->assertSame(86400, config('xendit.invoice_duration'));
        $this->assertFalse(config('xendit.allow_development_key_in_production'));

        $this->assertSame(['enabled'], array_keys(config('shopper.payment.drivers.xendit')));
        $this->assertFileDoesNotExist(config_path('xendit.php'), 'The module ships its config; publish it only to override.');
    }

    public function test_it_owns_the_webhook_route(): void
    {
        $route = Route::getRoutes()->getByName('webhooks.xendit.invoice');

        $this->assertNotNull($route);
        $this->assertSame('webhooks/xendit/invoice', $route->uri());
        $this->assertSame(['POST'], $route->methods());
        $this->assertSame(XenditInvoiceWebhookController::class, $route->getActionName());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('throttle:60,1', $route->gatherMiddleware());
    }

    public function test_without_a_key_the_webhook_is_acknowledged_and_nothing_is_processed(): void
    {
        Http::fake();

        $this->withHeader('x-callback-token', 'anything')
            ->postJson('/webhooks/xendit/invoice', ['id' => 'inv_1', 'status' => 'PAID'])
            ->assertOk()
            ->assertExactJson(['status' => 'disabled']);

        // A key without a verification token cannot verify anything either.
        config()->set('xendit.secret_key', 'xnd_development_secret');

        $this->postJson('/webhooks/xendit/invoice', ['id' => 'inv_1', 'status' => 'PAID'])
            ->assertOk()
            ->assertJsonPath('status', 'disabled');

        Http::assertNothingSent();
    }

    public function test_it_registers_its_status_command(): void
    {
        $this->assertArrayHasKey('xendit:status', Artisan::all());

        $this->artisan('xendit:status', ['--json' => true])
            ->expectsOutputToContain('"configured": false')
            ->assertSuccessful();
    }

    public function test_paid_after_cancellation_is_reported_as_critical(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);
        $levels = (new ReflectionProperty($handler, 'levels'))->getValue($handler);

        $this->assertSame(LogLevel::CRITICAL, $levels[XenditPaidAfterCancellation::class] ?? null);
    }

    public function test_xendit_slugs_resolve_to_local_logo_files_through_the_module_source(): void
    {
        $logos = resolve(PaymentLogos::class);

        $bca = new PaymentMethod(['slug' => 'xendit-va-bca', 'driver' => XenditDriver::CODE]);
        $this->assertStringContainsString('images/payments/bca.svg', (string) $logos->forMethod($bca));

        $qris = new PaymentMethod(['slug' => 'xendit-qris', 'driver' => XenditDriver::CODE]);
        $this->assertStringContainsString('images/payments/qris.svg', (string) $logos->forMethod($qris));

        $foreign = new PaymentMethod(['slug' => 'xendit-va-bca', 'driver' => 'acme']);
        $this->assertNull($logos->forMethod($foreign));
    }

    public function test_seeded_methods_stay_off_without_a_key(): void
    {
        $this->seed(XenditPaymentMethodSeeder::class);

        $this->assertSame(
            ['xendit-credit-card', 'xendit-dana', 'xendit-linkaja', 'xendit-ovo', 'xendit-qris', 'xendit-shopeepay', 'xendit-va-bca', 'xendit-va-bni', 'xendit-va-bri', 'xendit-va-mandiri', 'xendit-va-permata'],
            PaymentMethod::query()->where('driver', XenditDriver::CODE)->orderBy('slug')->pluck('slug')->all(),
        );
        $this->assertSame(0, PaymentMethod::query()->where('driver', XenditDriver::CODE)->where('is_enabled', true)->count());

        $metadata = json_decode((string) PaymentMethod::query()->where('slug', 'xendit-va-bri')->value('metadata'), true);
        $this->assertSame(['payment_type' => 'bank_transfer', 'channel_code' => 'BRI'], $metadata);
    }

    public function test_seeded_methods_are_enabled_with_a_key_except_cards_and_reseeding_keeps_staff_choice(): void
    {
        config()->set('xendit.secret_key', 'xnd_development_secret');

        $this->seed(XenditPaymentMethodSeeder::class);

        $this->assertSame(10, PaymentMethod::query()->where('driver', XenditDriver::CODE)->where('is_enabled', true)->count());
        $this->assertFalse((bool) PaymentMethod::query()->where('slug', 'xendit-credit-card')->value('is_enabled'));

        PaymentMethod::query()->where('slug', 'xendit-ovo')->update(['is_enabled' => false]);
        $this->seed(XenditPaymentMethodSeeder::class);

        $this->assertFalse((bool) PaymentMethod::query()->where('slug', 'xendit-ovo')->value('is_enabled'));
    }

    public function test_generic_store_code_never_references_the_module(): void
    {
        $offenders = [];

        $finder = (new Finder)->files()->name('*.php')
            ->in([app_path(), base_path('routes'), base_path('bootstrap'), config_path(), database_path(), resource_path('views')])
            ->exclude(['Integrations', 'cache'])
            ->notPath('#^providers\.php$#');

        foreach ($finder as $file) {
            if (preg_match('/Integrations\\\\Xendit/', $file->getContents()) === 1) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders, 'Only bootstrap/providers.php may name the Xendit module.');
    }

    public function test_the_module_does_not_reach_into_other_provider_modules(): void
    {
        $offenders = [];

        foreach ((new Finder)->files()->name('*.php')->in(app_path('Integrations/Xendit')) as $file) {
            if (preg_match('/Integrations\\\\(?!Xendit\b)[A-Z]/', $file->getContents()) === 1) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders);
    }
}
