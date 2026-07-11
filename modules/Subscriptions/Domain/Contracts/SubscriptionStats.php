<?php

namespace Modules\Subscriptions\Domain\Contracts;

/**
 * Aggregate subscription counts for reporting. Owned by the Subscriptions module;
 * consumers depend on this contract, not the model (§2.1/§2.4).
 */
interface SubscriptionStats
{
    public function total(): int;

    public function active(): int;
}
