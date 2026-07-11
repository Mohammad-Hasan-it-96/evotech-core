<?php

namespace Modules\Products\Providers;

use Modules\Core\Providers\BaseModuleServiceProvider;

/**
 * Products module: the platform's product catalog (products + pricing plans),
 * the single source consumed by the website and the subscriptions dashboard.
 */
final class ProductsServiceProvider extends BaseModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Products';
    }
}
