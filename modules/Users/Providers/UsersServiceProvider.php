<?php

namespace Modules\Users\Providers;

use Modules\Core\Providers\BaseModuleServiceProvider;

/**
 * Users module: owns the User identity/profile model, its migrations and factory.
 * Authentication behaviour lives in the Auth module.
 */
final class UsersServiceProvider extends BaseModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Users';
    }
}
