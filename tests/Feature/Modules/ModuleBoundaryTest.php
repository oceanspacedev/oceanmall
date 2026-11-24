<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Every provider module (app/Integrations/<Name>) is plug and play: generic
 * store code never references a module, and modules never reference each
 * other, so any one of them can be removed (provider line + folder) or
 * swapped without touching the rest. bootstrap/providers.php is the only
 * place that names them.
 */
final class ModuleBoundaryTest extends TestCase
{
    public function test_every_module_is_registered_in_bootstrap_providers(): void
    {
        $providers = require base_path('bootstrap/providers.php');
        $this->assertIsArray($providers);

        foreach ($this->modules() as $module) {
            $this->assertContains(
                "App\\Integrations\\{$module}\\{$module}ServiceProvider",
                $providers,
                "{$module} module is not registered in bootstrap/providers.php.",
            );
        }

        // And the other way round: no provider line outlives its folder.
        foreach ($providers as $provider) {
            if (preg_match('/^App\\\\Integrations\\\\([^\\\\]+)\\\\/', $provider, $match) === 1) {
                $this->assertContains($match[1], $this->modules(), "{$provider} is registered but app/Integrations/{$match[1]} is gone.");
            }
        }
    }

    public function test_generic_code_and_other_modules_never_reference_a_module(): void
    {
        $modules = $this->modules();

        if ($modules === []) {
            $this->markTestSkipped('No provider module installed (pure Shopper store).');
        }

        $files = Finder::create()
            ->files()
            ->name('*.php')
            ->in(array_map(base_path(...), ['app', 'routes', 'config', 'database', 'bootstrap']))
            ->notPath('cache');

        $leaks = [];

        foreach ($files as $file) {
            $path = str_replace('\\', '/', $file->getRealPath());

            if (str_ends_with($path, '/bootstrap/providers.php')) {
                continue;
            }

            $contents = $file->getContents();

            foreach ($modules as $module) {
                if (str_contains($path, "/app/Integrations/{$module}/")) {
                    continue;
                }

                if (str_contains($contents, "App\\Integrations\\{$module}\\")) {
                    $leaks[] = "{$path} references App\\Integrations\\{$module}";
                }
            }
        }

        $this->assertSame([], $leaks);
    }

    /**
     * Strings and views a module needs ship with the module
     * (loadJsonTranslationsFrom / loadViewsFrom), so dropping its folder
     * leaves no vendor-named copy behind in the generic store. The only
     * exception is the legacy `rajaongkir_destination_*` address input
     * vocabulary that old mobile builds still send (App\Shipping\AddressDestination).
     */
    public function test_generic_translations_and_views_never_name_a_module(): void
    {
        $names = array_map(strtolower(...), [...$this->modules(), 'RajaOngkir', 'QRISLY']);
        $pattern = '/'.implode('|', array_map(static fn (string $name): string => preg_quote($name, '/'), $names)).'/i';

        $files = Finder::create()->files()->in([base_path('lang'), resource_path('views')]);
        $leaks = [];

        foreach ($files as $file) {
            foreach (preg_split('/\R/', $file->getContents()) ?: [] as $number => $line) {
                $line = preg_replace('/rajaongkir_destination_(id|label|pin_point)/', '', $line) ?? $line;

                if (preg_match($pattern, $line, $match) === 1) {
                    $leaks[] = str_replace('\\', '/', $file->getRelativePathname()).':'.($number + 1)." names {$match[0]}";
                }
            }
        }

        $this->assertSame([], $leaks);
    }

    /**
     * @return list<string>
     */
    private function modules(): array
    {
        $modules = [];

        // A pure Shopper store has no modules at all (folder removed).
        if (! is_dir(base_path('app/Integrations'))) {
            return [];
        }

        foreach (Finder::create()->directories()->depth(0)->in(base_path('app/Integrations')) as $dir) {
            $modules[] = $dir->getFilename();
        }

        sort($modules);

        return $modules;
    }
}
