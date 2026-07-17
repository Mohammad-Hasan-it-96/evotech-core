<?php

namespace Modules\DeviceSubscriptions\Application\Services;

/**
 * Read model over the per-app settings in config('device-subscriptions.apps').
 *
 * One deployment serves several shipped apps, told apart only by the `app_name`
 * they send, and they do NOT share policy: Fawateer grants a 30-day trial,
 * SmartAgent grants none. Lookups are case-insensitive, and an unknown app gets
 * the conservative default (no trial, its own name as the label) rather than
 * inheriting another app's terms.
 */
final class DeviceAppCatalog
{
    /** Free-trial length for an app; 0 (the default) means no trial. */
    public function trialDays(string $appName): int
    {
        $days = $this->settings($appName)['trial_days'] ?? 0;

        return is_numeric($days) ? max(0, (int) $days) : 0;
    }

    /** Product name for push copy; falls back to the raw app_name. */
    public function label(string $appName): string
    {
        $label = $this->settings($appName)['label'] ?? null;

        return is_string($label) && $label !== '' ? $label : $appName;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function settings(string $appName): array
    {
        $apps = config('device-subscriptions.apps', []);

        if (! is_array($apps)) {
            return [];
        }

        foreach ($apps as $name => $settings) {
            if (is_string($name) && strcasecmp($name, $appName) === 0 && is_array($settings)) {
                return $settings;
            }
        }

        return [];
    }
}
