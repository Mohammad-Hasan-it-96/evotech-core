<?php

namespace Modules\Customers\Providers;

use Modules\Core\Providers\BaseModuleServiceProvider;

/**
 * Customers module: tenant-scoped customer records owned by a company.
 */
final class CustomersServiceProvider extends BaseModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Customers';
    }
}
