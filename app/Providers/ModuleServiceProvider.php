<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Discovers and registers every module's service provider.
 *
 * Convention (ADR 0002): each module lives at modules/<Name>/ and exposes
 * Modules\<Name>\Providers\<Name>ServiceProvider. This scans the modules
 * directory and registers each one — no manual wiring per module.
 */
class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        foreach ($this->discoverModuleProviders() as $provider) {
            $this->app->register($provider);
        }
    }

    /**
     * @return list<class-string<ServiceProvider>>
     */
    protected function discoverModuleProviders(): array
    {
        $modulesPath = base_path('modules');

        if (! is_dir($modulesPath)) {
            return [];
        }

        $providers = [];

        foreach (glob($modulesPath.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $name = basename($dir);
            $provider = "Modules\\{$name}\\Providers\\{$name}ServiceProvider";

            if (is_subclass_of($provider, ServiceProvider::class)) {
                $providers[] = $provider;
            }
        }

        // Core is the shared kernel — ensure it registers first so other
        // modules can depend on its bindings during their own registration.
        usort($providers, static function (string $a, string $b): int {
            return (str_contains($a, '\\Core\\') ? 0 : 1)
                <=> (str_contains($b, '\\Core\\') ? 0 : 1);
        });

        return $providers;
    }
}
