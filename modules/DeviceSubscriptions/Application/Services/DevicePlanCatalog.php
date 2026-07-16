<?php

namespace Modules\DeviceSubscriptions\Application\Services;

/**
 * Read model over the configured plans (config/device-subscriptions.php). Returns
 * the exact `getPlans` payload the shipped app expects and resolves a plan's
 * duration in months for activation.
 */
final class DevicePlanCatalog
{
    /**
     * The full plans payload, currency included, matching the legacy response.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'success' => true,
            'currency' => config('device-subscriptions.currency'),
            'plans' => array_values($this->plans()),
        ];
    }

    /** Duration in months for a plan_id, from config; 0 if unknown. */
    public function durationMonths(?string $planId): int
    {
        foreach ($this->plans() as $plan) {
            if (is_array($plan) && ($plan['id'] ?? null) === $planId) {
                $months = $plan['duration_months'] ?? 0;

                return is_numeric($months) ? (int) $months : 0;
            }
        }

        return 0;
    }

    /**
     * The configured plans as an array (defensive against a malformed config).
     *
     * @return array<int, mixed>
     */
    private function plans(): array
    {
        $plans = config('device-subscriptions.plans', []);

        return is_array($plans) ? array_values($plans) : [];
    }
}
