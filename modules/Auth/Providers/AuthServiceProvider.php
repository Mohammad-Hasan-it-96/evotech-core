<?php

namespace Modules\Auth\Providers;

use Modules\Core\Providers\BaseModuleServiceProvider;

/**
 * Auth module: authentication use-cases and endpoints (register/login/logout/me),
 * backed by Laravel Sanctum tokens. Depends on the Users module for the identity.
 */
final class AuthServiceProvider extends BaseModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Auth';
    }
}
