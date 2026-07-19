<?php

namespace Modules\DeviceSubscriptions\Application\Services;

use Modules\Core\Domain\Contracts\AuditLogger;
use Modules\DeviceSubscriptions\Domain\Models\DeviceApp;
use Modules\DeviceSubscriptions\Domain\Models\DevicePlan;
use Modules\DeviceSubscriptions\Domain\Models\DeviceSubscription;

/**
 * Writes to the app + plan catalog.
 *
 * Every mutation flushes the read cache, so an operator's edit is live on the next
 * device poll rather than up to five minutes later — a price they just changed
 * still showing the old number reads as a broken dashboard.
 */
final class DeviceCatalogService
{
    public function __construct(
        private readonly DeviceCatalogStore $store,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createPlan(array $attributes): DevicePlan
    {
        $plan = DevicePlan::create($attributes);

        $this->audit->log('device_plan.created', 'device_plan', $plan->uuid, [
            'key' => $plan->plan_key,
            'device_app_id' => $plan->device_app_id,
            'price' => $plan->price,
            'duration_months' => $plan->duration_months,
        ]);

        $this->store->flush();

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updatePlan(DevicePlan $plan, array $attributes): DevicePlan
    {
        $before = $plan->only(['title', 'price', 'price_after_discount', 'duration_months', 'enabled', 'recommended']);

        $plan->update($attributes);

        $this->audit->log('device_plan.updated', 'device_plan', $plan->uuid, [
            'key' => $plan->plan_key,
            'before' => $before,
            'after' => $plan->only(['title', 'price', 'price_after_discount', 'duration_months', 'enabled', 'recommended']),
        ]);

        $this->store->flush();

        return $plan;
    }

    public function deletePlan(DevicePlan $plan): void
    {
        $this->audit->log('device_plan.deleted', 'device_plan', $plan->uuid, [
            'key' => $plan->plan_key,
            'device_app_id' => $plan->device_app_id,
            'title' => $plan->title,
            'price' => $plan->price,
            'duration_months' => $plan->duration_months,
        ]);

        $plan->delete();

        $this->store->flush();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateApp(DeviceApp $app, array $attributes): DeviceApp
    {
        $before = $app->only(['label', 'trial_days', 'uses_shared_plans', 'product_id']);

        $app->update($attributes);

        $this->audit->log('device_app.updated', 'device_app', $app->uuid, [
            'name' => $app->name,
            'before' => $before,
            'after' => $app->only(['label', 'trial_days', 'uses_shared_plans', 'product_id']),
        ]);

        $this->store->flush();

        return $app;
    }

    /**
     * How many live subscriptions hold this plan.
     *
     * Deleting a referenced plan is the failure that motivated this method: a
     * device row stores the plan *key*, and renewal resolves a duration by looking
     * that key up in the catalog. Remove the row and the lookup returns 0 months —
     * so the next renewal of a paying customer expires the instant it is granted.
     * Disabling a plan hides it from the store while leaving it resolvable, which
     * is what an operator retiring a price actually wants.
     *
     * Counts `plan_id` only. A *pending request* naming this plan
     * (`requested_plan`) does not block deletion: nothing has been sold yet, and
     * the operator picks a plan at activation anyway.
     */
    public function subscriberCount(DevicePlan $plan): int
    {
        $query = DeviceSubscription::query()->where('plan_id', $plan->plan_key);

        if ($plan->device_app_id !== null) {
            // App-scoped: only that app's devices can be holding this key.
            $appName = DeviceApp::query()->whereKey($plan->device_app_id)->value('name');

            if (is_string($appName)) {
                $query->whereRaw('LOWER(app_name) = ?', [mb_strtolower($appName)]);
            }

            return $query->count();
        }

        /*
         * Shared scope: held by devices of every app that reads the shared list.
         * An app with its own catalog may define the same key independently, and
         * its holders are not this plan's holders — so they are excluded rather
         * than over-counted into a delete that then looks unsafe for no reason.
         */
        $sharedAppNames = [];

        foreach (DeviceApp::query()->where('uses_shared_plans', true)->get() as $sharedApp) {
            $sharedAppNames[] = mb_strtolower($sharedApp->name);
        }

        if ($sharedAppNames === []) {
            return $query->count();
        }

        $placeholders = implode(',', array_fill(0, count($sharedAppNames), '?'));

        return $query
            ->whereRaw("LOWER(app_name) IN ({$placeholders})", $sharedAppNames)
            ->count();
    }
}
