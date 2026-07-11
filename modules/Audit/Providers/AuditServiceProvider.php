<?php

namespace Modules\Audit\Providers;

use Illuminate\Support\Facades\Event;
use Modules\Audit\Application\Listeners\RecordInvoicePaidAudit;
use Modules\Audit\Application\Listeners\RecordSubscriptionActivatedAudit;
use Modules\Audit\Infrastructure\Logging\EloquentAuditLogger;
use Modules\Core\Domain\Contracts\AuditLogger;
use Modules\Core\Providers\BaseModuleServiceProvider;
use Modules\Payments\Domain\Events\InvoicePaid;
use Modules\Subscriptions\Domain\Events\SubscriptionActivated;

/**
 * Audit module: the platform's immutable audit trail (ADR 0007). Provides the
 * persisting adapter for Core's AuditLogger port and captures domain events that
 * are already emitted (without touching the producing modules).
 */
final class AuditServiceProvider extends BaseModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Audit';
    }

    public function register(): void
    {
        // Override Core's no-op default with the persisting adapter.
        $this->app->bind(AuditLogger::class, EloquentAuditLogger::class);
    }

    protected function bootModule(): void
    {
        Event::listen(InvoicePaid::class, RecordInvoicePaidAudit::class);
        Event::listen(SubscriptionActivated::class, RecordSubscriptionActivatedAudit::class);
    }
}
