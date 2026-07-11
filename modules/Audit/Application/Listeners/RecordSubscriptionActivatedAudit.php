<?php

namespace Modules\Audit\Application\Listeners;

use Modules\Core\Domain\Contracts\AuditLogger;
use Modules\Subscriptions\Domain\Events\SubscriptionActivated;

/**
 * Audits subscription activation/renewal. Runs synchronously so the request's
 * actor/IP are captured.
 */
final class RecordSubscriptionActivatedAudit
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(SubscriptionActivated $event): void
    {
        $this->audit->log('subscription.activated', 'subscription', $event->subscription->uuid, [
            'status' => $event->subscription->status->value,
        ]);
    }
}
