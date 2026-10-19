<?php

declare(strict_types=1);

namespace Tests\Feature\Cpanel;

use Illuminate\Support\Arr;
use Livewire\Livewire;

use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Livewire\Components\LocaleSwitcher;
use Tests\TestCase;

final class LocaleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app('translator')->addNamespace('shopper', base_path('vendor/shopper/framework/resources/lang'));
    }

    public function test_shopper_admin_locales_config_contains_en_and_id_and_not_fr(): void
    {
        $locales = config('shopper.admin.locales');

        $this->assertIsArray($locales);
        $this->assertArrayHasKey('en', $locales);
        $this->assertArrayHasKey('id', $locales);
        $this->assertArrayNotHasKey('fr', $locales);
        $this->assertSame('English', $locales['en']['label']);
        $this->assertSame('Bahasa Indonesia', $locales['id']['label']);
        $this->assertSame('id', $locales['id']['flag']);
    }

    public function test_shopper_words_translate_to_indonesian_by_default(): void
    {
        $translated = __('shopper::pages/dashboard.menu', [], 'id');
        $this->assertSame('Dasbor', $translated);

        $translatedProduct = __('shopper::words.product', [], 'id');
        $this->assertSame('Produk', $translatedProduct);
    }

    public function test_order_list_status_tabs_translate_to_indonesian(): void
    {
        app()->setLocale('id');

        $this->assertSame('Dibatalkan', OrderStatus::Cancelled->getLabel());
        $this->assertSame('Diarsipkan', OrderStatus::Archived->getLabel());
        $this->assertSame('Dibayar', PaymentStatus::Paid->getLabel());
    }

    public function test_indonesian_status_override_matches_english_keys(): void
    {
        $en = require base_path('vendor/shopper/core/resources/lang/en/status.php');
        $id = require base_path('lang/vendor/shopper-core/id/status.php');

        $this->assertSame(array_keys(Arr::dot($en)), array_keys(Arr::dot($id)));
    }

    public function test_locale_switcher_changes_session_locale_to_english(): void
    {
        Livewire::test(LocaleSwitcher::class)
            ->call('switchLocale', 'en')
            ->assertSessionHas('shopper_locale', 'en');
    }

    public function test_locale_switcher_rejects_unregistered_locale_fr(): void
    {
        Livewire::test(LocaleSwitcher::class)
            ->call('switchLocale', 'fr')
            ->assertSessionMissing('shopper_locale');
    }
}
