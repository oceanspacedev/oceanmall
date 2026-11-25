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
     * Removing a module = its provider line, app/Integrations/<Name> and its
     * test folders tests/{Feature,Unit}/<Name>. Every other test must survive
     * that: it never loads a module's test helpers (a missing trait is a fatal
     * error for the whole run), and a test that touches a module (its classes
     * or its `<name>.*` config) skips itself with requireModules().
     */
    public function test_generic_tests_survive_removing_a_module(): void
    {
        $modules = $this->modules();

        if ($modules === []) {
            $this->markTestSkipped('No provider module installed (pure Shopper store).');
        }

        $moduleFolders = [];

        foreach ($modules as $module) {
            $moduleFolders[] = "/tests/Feature/{$module}/";
            $moduleFolders[] = "/tests/Unit/{$module}/";
        }

        $leaks = [];

        foreach (Finder::create()->files()->name('*.php')->in(base_path('tests')) as $file) {
            $path = str_replace('\\', '/', $file->getRealPath());

            if (str_contains($path, '/tests/Feature/Modules/') || str_ends_with($path, '/tests/TestCase.php')) {
                continue;
            }

            foreach ($moduleFolders as $folder) {
                if (str_contains($path, $folder)) {
                    continue 2;
                }
            }

            $contents = $file->getContents();
            $relative = 'tests/'.str_replace('\\', '/', $file->getRelativePathname());

            foreach ($modules as $module) {
                if (preg_match("/Tests\\\\(Feature|Unit)\\\\{$module}\\\\/", $contents) === 1) {
                    $leaks[] = "{$relative} uses test helpers of the {$module} module";
                }

                // Module classes, or the module's config (`<name>.*` keys set via
                // config()->set(), config() or a config([...]) array key).
                $key = "['\"]".strtolower($module).'\.[a-z_.]+[\'"]';
                $touches = str_contains($contents, "App\\Integrations\\{$module}\\")
                    || preg_match("/config\\((\\)->set\\()?\\s*{$key}|{$key}\\s*=>/", $contents) === 1;

                if ($touches && ! str_contains($contents, "requireModules('{$module}'") && ! str_contains($contents, "'{$module}')")) {
                    $leaks[] = "{$relative} touches the {$module} module without requireModules('{$module}')";
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
