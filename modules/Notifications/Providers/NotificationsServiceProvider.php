<?php

namespace Modules\Notifications\Providers;

use Illuminate\Support\Facades\Event;
use Modules\Core\Providers\BaseModuleServiceProvider;
use Modules\Notifications\Application\Listeners\SendInvoicePaidNotification;
use Modules\Payments\Domain\Events\InvoicePaid;

/**
 * Notifications module: cross-channel notification dispatch and the in-app store
 * (constitution §3). Composition consumer — listens to other modules' domain
 * events and resolves recipients from Users; producers stay decoupled (§2.1).
 */
final class NotificationsServiceProvider extends BaseModuleServiceProvider
{
    protected function moduleName(): string
    {
        return 'Notifications';
    }

    protected function bootModule(): void
    {
        Event::listen(InvoicePaid::class, SendInvoicePaidNotification::class);
    }
}
