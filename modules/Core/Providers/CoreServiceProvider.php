<?php

namespace Modules\Core\Providers;

use Modules\Core\Domain\Contracts\AuditLogger;
use Modules\Core\Domain\Contracts\TenantContext;
use Modules\Core\Infrastructure\Logging\NullAuditLogger;
use Modules\Core\Infrastructure\Tenancy\RequestTenantContext;

/**
 * Core is the shared kernel module: base classes, the API response envelope,
 * common contracts and value objects that other modules may depend on.
 * It is the only module other modules may reference directly.
 */
final class CoreServiceProvider extends BaseModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Core';
    }

    public function register(): void
    {
        // One tenant context per request.
        $this->app->singleton(TenantContext::class, RequestTenantContext::class);

        // Safe default audit sink; the Audit module overrides it (ADR 0007).
        $this->app->bind(AuditLogger::class, NullAuditLogger::class);
    }
}
