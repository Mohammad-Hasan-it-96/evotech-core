<?php

namespace Modules\Subscriptions\Infrastructure\Reporting;

use Modules\Subscriptions\Domain\Contracts\SubscriptionStats;
use Modules\Subscriptions\Domain\Enums\SubscriptionStatus;
use Modules\Subscriptions\Domain\Models\Subscription;

final class EloquentSubscriptionStats implements SubscriptionStats
{
    public function total(): int
    {
        return Subscription::query()->count();
    }

    public function active(): int
    {
        return Subscription::query()->where('status', SubscriptionStatus::Active->value)->count();
    }
}
