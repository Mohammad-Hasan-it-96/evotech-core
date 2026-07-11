<?php

namespace Modules\Companies\Providers;

use Modules\Companies\Domain\Contracts\CompanyStats;
use Modules\Companies\Infrastructure\Reporting\EloquentCompanyStats;
use Modules\Core\Providers\BaseModuleServiceProvider;

/**
 * Companies module: owns the tenant organization entity and its management API.
 */
final class CompaniesServiceProvider extends BaseModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Companies';
    }

    public function register(): void
    {
        $this->app->bind(CompanyStats::class, EloquentCompanyStats::class);
    }
}
