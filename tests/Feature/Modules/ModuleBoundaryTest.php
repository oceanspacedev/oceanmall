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

        foreach ($this->modules() as $module) {
            $this->assertContains(
                "App\\Integrations\\{$module}\\{$module}ServiceProvider",
                $providers,
                "{$module} module is not registered in bootstrap/providers.php.",
            );
        }
    }

    public function test_generic_code_and_other_modules_never_reference_a_module(): void
    {
        $modules = $this->modules();
        $this->assertNotEmpty($modules);

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
     * @return list<string>
     */
    private function modules(): array
    {
        $modules = [];

        foreach (Finder::create()->directories()->depth(0)->in(base_path('app/Integrations')) as $dir) {
            $modules[] = $dir->getFilename();
        }

        sort($modules);

        return $modules;
    }
}
