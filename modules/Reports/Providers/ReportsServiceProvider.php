<?php

namespace Modules\Reports\Providers;

use Modules\Core\Providers\BaseModuleServiceProvider;

/**
 * Reports module: read-only aggregations composed from each module's stats
 * contract. It owns no data and binds nothing — the ReportService resolves the
 * per-module contracts each source module registers.
 */
final class ReportsServiceProvider extends BaseModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Reports';
    }
}
