<?php

namespace Modules\Licenses\Application\Listeners;

use Modules\Licenses\Application\Services\LicenseService;
use Modules\Subscriptions\Domain\Events\SubscriptionActivated;

/**
 * Auto-issues (or renews) a license whenever a subscription is activated —
 * the platform's "subscription = entitlement, license = the credential" rule.
 */
final class IssueLicenseOnActivation
{
    public function __construct(private readonly LicenseService $licenses) {}

    public function handle(SubscriptionActivated $event): void
    {
        $this->licenses->syncForSubscription($event->subscription);
    }
}
