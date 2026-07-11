<?php

namespace Modules\Companies\Infrastructure\Reporting;

use Modules\Companies\Domain\Contracts\CompanyStats;
use Modules\Companies\Domain\Enums\CompanyStatus;
use Modules\Companies\Domain\Models\Company;

final class EloquentCompanyStats implements CompanyStats
{
    public function total(): int
    {
        return Company::query()->count();
    }

    public function active(): int
    {
        return Company::query()->where('status', CompanyStatus::Active->value)->count();
    }
}
