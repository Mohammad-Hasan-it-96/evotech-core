<?php

namespace Modules\Subscriptions\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Modules\Subscriptions\Domain\Models\Subscription;

/**
 * Published when a subscription becomes (or is renewed as) active. Other modules
 * — notably Licenses — react to this via listeners; Subscriptions itself does not
 * depend on them (constitution §2.1: cross-module comms via events only).
 */
final class SubscriptionActivated
{
    use Dispatchable;

    public function __construct(public readonly Subscription $subscription) {}
}
