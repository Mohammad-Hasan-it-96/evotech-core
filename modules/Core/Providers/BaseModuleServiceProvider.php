<?php

namespace Modules\Core\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use ReflectionClass;

/**
 * Base for every module's service provider. Wires the module's routes,
 * migrations and translations by convention so concrete providers stay tiny.
 *
 * Convention (ADR 0002): a module lives at modules/<Name>/ with optional
 * Routes/{api,web,console}.php, Database/Migrations, and Lang directories.
 */
abstract class BaseModuleServiceProvider extends ServiceProvider
{
    /** Machine name of the module, e.g. "Core", "Auth". */
    abstract protected function moduleName(): string;

    public function boot(): void
    {
        $this->registerMigrations();
        $this->registerTranslations();
        $this->registerRoutes();

        $this->bootModule();
    }

    /** Hook for module-specific boot logic (events, policies, macros). */
    protected function bootModule(): void
    {
        //
    }

    /** Absolute path inside the module directory. */
    protected function modulePath(string $path = ''): string
    {
        $file = (new ReflectionClass($this))->getFileName();

        // Providers/ live one level under the module root. getFileName() is only
        // false for internal classes, which a module provider never is.
        $root = $file !== false ? dirname($file, 2) : base_path();

        return $path === '' ? $root : $root.DIRECTORY_SEPARATOR.$path;
    }

    protected function registerMigrations(): void
    {
        $path = $this->modulePath('Database/Migrations');

        if (is_dir($path)) {
            $this->loadMigrationsFrom($path);
        }
    }

    protected function registerTranslations(): void
    {
        $path = $this->modulePath('Lang');

        if (is_dir($path)) {
            $this->loadTranslationsFrom($path, strtolower($this->moduleName()));
        }
    }

    protected function registerRoutes(): void
    {
        $api = $this->modulePath('Routes/api.php');
        if (is_file($api)) {
            Route::middleware('api')->group($api);
        }

        $web = $this->modulePath('Routes/web.php');
        if (is_file($web)) {
            Route::middleware('web')->group($web);
        }

        $console = $this->modulePath('Routes/console.php');
        if (is_file($console)) {
            $this->loadRoutesFrom($console);
        }
    }
}
