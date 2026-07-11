<?php

namespace Modules\Licenses\Providers;

use Illuminate\Support\Facades\Event;
use Modules\Core\Providers\BaseModuleServiceProvider;
use Modules\Licenses\Application\Listeners\IssueLicenseOnActivation;
use Modules\Licenses\Console\ExpireLicensesCommand;
use Modules\Licenses\Console\GenerateSigningKeyCommand;
use Modules\Licenses\Domain\Contracts\LicenseStats;
use Modules\Licenses\Domain\Contracts\OfflineTokenSigner;
use Modules\Licenses\Infrastructure\Reporting\EloquentLicenseStats;
use Modules\Licenses\Infrastructure\Signing\SodiumOfflineTokenSigner;
use Modules\Subscriptions\Domain\Events\SubscriptionActivated;

/**
 * Licenses module: issues and manages the machine-readable credentials that
 * prove a company's product entitlement. Composition module — reacts to
 * Subscriptions events and references Subscriptions + Companies.
 */
final class LicensesServiceProvider extends BaseModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Licenses';
    }

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath('Config/licenses.php'), 'licenses');

        $this->app->bind(OfflineTokenSigner::class, SodiumOfflineTokenSigner::class);
        $this->app->bind(LicenseStats::class, EloquentLicenseStats::class);
    }

    protected function bootModule(): void
    {
        Event::listen(SubscriptionActivated::class, IssueLicenseOnActivation::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                ExpireLicensesCommand::class,
                GenerateSigningKeyCommand::class,
            ]);
        }
    }
}
