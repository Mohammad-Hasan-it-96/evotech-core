<?php

namespace Modules\Payments\Application\Listeners;

use Modules\Payments\Application\Services\PaymentService;
use Modules\Subscriptions\Domain\Events\SubscriptionActivated;

/**
 * Auto-issues an invoice whenever a subscription is activated or renewed — each
 * active period is billed once (issuance is idempotent per period). Free periods
 * (price 0) raise no invoice.
 */
final class IssueInvoiceOnActivation
{
    public function __construct(private readonly PaymentService $payments) {}

    public function handle(SubscriptionActivated $event): void
    {
        if ((float) $event->subscription->price > 0) {
            $this->payments->issueForSubscription($event->subscription);
        }
    }
}
