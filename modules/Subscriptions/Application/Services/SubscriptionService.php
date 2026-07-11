<?php

namespace Modules\Subscriptions\Application\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Modules\Companies\Domain\Models\Company;
use Modules\Products\Domain\Models\Plan;
use Modules\Subscriptions\Application\DTOs\CreateSubscriptionData;
use Modules\Subscriptions\Application\DTOs\UpdateSubscriptionData;
use Modules\Subscriptions\Domain\Enums\SubscriptionStatus;
use Modules\Subscriptions\Domain\Events\SubscriptionActivated;
use Modules\Subscriptions\Domain\Models\Subscription;

/**
 * Subscription lifecycle use-cases. Resolves the subscriber (Company) and Plan,
 * snapshots the plan price, and derives the period from the plan's billing period.
 */
final class SubscriptionService
{
    /**
     * @return LengthAwarePaginator<int, Subscription>
     */
    public function paginate(int $perPage): LengthAwarePaginator
    {
        return Subscription::query()
            ->with(['company', 'plan.product'])
            ->latest()
            ->paginate($perPage);
    }

    public function create(CreateSubscriptionData $data): Subscription
    {
        $company = Company::query()->where('uuid', $data->companyUuid)->firstOrFail();
        $plan = Plan::query()->where('uuid', $data->planUuid)->firstOrFail();

        $startsAt = $data->startsAt ?? Carbon::now();
        $days = $plan->billing_period->days();

        $subscription = Subscription::create([
            'company_id' => $company->id,
            'plan_id' => $plan->id,
            'identifier_type' => $data->identifierType,
            'identifier_value' => $data->identifierValue,
            'status' => SubscriptionStatus::Active,
            'starts_at' => $startsAt,
            'ends_at' => $days !== null ? $startsAt->copy()->addDays($days) : null,
            'auto_renew' => $data->autoRenew,
            'price' => $plan->price,
            'currency' => $plan->currency,
        ]);

        SubscriptionActivated::dispatch($subscription);

        return $subscription;
    }

    public function update(Subscription $subscription, UpdateSubscriptionData $data): Subscription
    {
        if ($data->identifierType !== null) {
            $subscription->identifier_type = $data->identifierType;
        }
        if ($data->identifierValue !== null) {
            $subscription->identifier_value = $data->identifierValue;
        }
        if ($data->status !== null) {
            $subscription->status = $data->status;
        }
        if ($data->autoRenew !== null) {
            $subscription->auto_renew = $data->autoRenew;
        }

        $subscription->save();

        return $subscription;
    }

    /** Extend the period by one plan cycle and re-activate. */
    public function renew(Subscription $subscription): Subscription
    {
        $days = $subscription->plan->billing_period->days();

        if ($days !== null) {
            $base = $subscription->ends_at !== null && $subscription->ends_at->isFuture()
                ? $subscription->ends_at
                : Carbon::now();

            $subscription->ends_at = $base->copy()->addDays($days);
        }

        $subscription->status = SubscriptionStatus::Active;
        $subscription->save();

        SubscriptionActivated::dispatch($subscription);

        return $subscription;
    }

    public function cancel(Subscription $subscription): Subscription
    {
        $subscription->status = SubscriptionStatus::Cancelled;
        $subscription->auto_renew = false;
        $subscription->save();

        return $subscription;
    }

    public function delete(Subscription $subscription): void
    {
        $subscription->delete();
    }

    /** Mark active subscriptions past their end date as expired. Returns the count. */
    public function expireDue(): int
    {
        return Subscription::query()
            ->where('status', SubscriptionStatus::Active)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', Carbon::now())
            ->update(['status' => SubscriptionStatus::Expired]);
    }
}
