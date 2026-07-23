<?php

namespace Modules\DeviceSubscriptions\Application\Services;

/**
 * Read model over the plan catalog (`device_plans`, dashboard-editable; config is
 * the fallback — see DeviceCatalogStore). Returns the exact `getPlans` payload the
 * shipped app expects and resolves a plan's duration in months for activation.
 *
 * Plans resolve **per app** (Phase D): an app with its own `plans` list gets it,
 * anything else gets the shared list. Every lookup takes the app so a plan id is
 * always read in the catalog it belongs to — the same id may exist in two apps with
 * different durations, and resolving it against the wrong one would set the wrong
 * expiry on a paying customer.
 */
final class DevicePlanCatalog
{
    public function __construct(
        private readonly DeviceAppCatalog $apps,
        private readonly DeviceCatalogStore $store,
    ) {}

    /**
     * The full plans payload, currency included, matching the legacy response.
     *
     * @return array<string, mixed>
     */
    public function payload(?string $appName = null): array
    {
        return [
            'success' => true,
            'currency' => config('device-subscriptions.currency'),
            'plans' => $this->plans($appName),
        ];
    }

    /** Duration in months for a plan_id within an app's catalog; 0 if unknown. */
    public function durationMonths(?string $planId, ?string $appName = null): int
    {
        foreach ($this->plans($appName) as $plan) {
            if (is_array($plan) && ($plan['id'] ?? null) === $planId) {
                $months = $plan['duration_months'] ?? 0;

                return is_numeric($months) ? (int) $months : 0;
            }
        }

        return 0;
    }

    /**
     * Display title for a plan_id within an app's catalog (e.g. "الخطة السنوية"),
     * or null if the catalog does not list it. Used by the activation push so the
     * customer is told *which* plan was switched on, as the legacy backend did.
     */
    public function title(?string $planId, ?string $appName = null): ?string
    {
        foreach ($this->plans($appName) as $plan) {
            if (is_array($plan) && ($plan['id'] ?? null) === $planId) {
                $title = $plan['title'] ?? null;

                return is_string($title) && $title !== '' ? $title : null;
            }
        }

        return null;
    }

    /**
     * An app's plans, falling back to the shared list (defensive against a
     * malformed config).
     *
     * @return array<int, mixed>
     */
    public function plans(?string $appName = null): array
    {
        $plans = $appName === null ? null : $this->apps->plans($appName);

        $plans ??= $this->store->snapshot()['shared_plans'];

        return array_values($plans);
    }
}
