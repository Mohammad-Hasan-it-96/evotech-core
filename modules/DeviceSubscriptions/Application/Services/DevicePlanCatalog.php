<?php

namespace Modules\DeviceSubscriptions\Application\Services;

/**
 * Read model over the configured plans (config/device-subscriptions.php). Returns
 * the exact `getPlans` payload the shipped app expects and resolves a plan's
 * duration in months for activation.
 *
 * Plans resolve **per app** (Phase D): an app with its own `plans` list gets it,
 * anything else gets the shared list. Every lookup takes the app so a plan id is
 * always read in the catalog it belongs to — the same id may exist in two apps with
 * different durations, and resolving it against the wrong one would set the wrong
 * expiry on a paying customer.
 */
final class DevicePlanCatalog
{
    public function __construct(private readonly DeviceAppCatalog $apps) {}

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
     * An app's plans, falling back to the shared list (defensive against a
     * malformed config).
     *
     * @return array<int, mixed>
     */
    public function plans(?string $appName = null): array
    {
        $plans = $appName === null ? null : $this->apps->plans($appName);

        $plans ??= config('device-subscriptions.plans', []);

        return is_array($plans) ? array_values($plans) : [];
    }
}
