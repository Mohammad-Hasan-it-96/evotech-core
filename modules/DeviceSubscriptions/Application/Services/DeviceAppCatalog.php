<?php

namespace Modules\DeviceSubscriptions\Application\Services;

/**
 * Read model over the per-app settings, now stored in `device_apps` and editable
 * from the dashboard (config is the fallback — see DeviceCatalogStore).
 *
 * One deployment serves several shipped apps, told apart only by the `app_name`
 * they send, and they do NOT share policy: Fawateer grants a 30-day trial,
 * SmartAgent grants none. Lookups are case-insensitive, and an unknown app gets
 * the conservative default (no trial, its own name as the label) rather than
 * inheriting another app's terms.
 *
 * Firebase credentials stay in config deliberately and are NOT part of the editable
 * catalog: the value is a path to a service-account private key, which has no
 * business being writable from a browser session or readable out of a database.
 */
final class DeviceAppCatalog
{
    public function __construct(private readonly DeviceCatalogStore $store) {}

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
     * An app's own plan catalog, or null when it has none configured — in which
     * case the caller falls back to the shared list. Null and "empty list" are
     * deliberately different: an app must be able to configure zero plans.
     *
     * @return array<int, mixed>|null
     */
    public function plans(string $appName): ?array
    {
        $plans = $this->settings($appName)['plans'] ?? null;

        return is_array($plans) ? array_values($plans) : null;
    }

    /**
     * The app's Firebase project + service-account path, or null when it has no
     * usable credential configured.
     *
     * Each app is its own Firebase project, so this is deliberately not a global
     * setting: sending a Fawateer token through SmartAgent's project fails with
     * 404 UNREGISTERED. Returning null (rather than a partial array) means the
     * caller has exactly one thing to check before sending.
     *
     * @return array{project_id: string, credentials: string}|null
     */
    public function firebase(string $appName): ?array
    {
        $apps = config('device-subscriptions.apps', []);

        if (! is_array($apps)) {
            return null;
        }

        $firebase = null;

        foreach ($apps as $name => $settings) {
            if (is_string($name) && strcasecmp($name, $appName) === 0 && is_array($settings)) {
                $firebase = $settings['firebase'] ?? null;
                break;
            }
        }

        if (! is_array($firebase)) {
            return null;
        }

        $projectId = $firebase['project_id'] ?? null;
        $credentials = $firebase['credentials'] ?? null;

        if (! is_string($projectId) || $projectId === '') {
            return null;
        }

        if (! is_string($credentials) || $credentials === '') {
            return null;
        }

        return ['project_id' => $projectId, 'credentials' => $credentials];
    }

    /**
     * The app_name behind a URL slug (`/api/{slug}/*`), or null if unknown.
     *
     * Unknown slugs resolve to null rather than erroring: the caller then serves
     * the shared catalog, so a typo'd base URL degrades to today's behaviour
     * instead of locking an app out of its plans.
     */
    public function appForSlug(string $slug): ?string
    {
        foreach ($this->store->snapshot()['apps'] as $app) {
            $appSlug = $app['slug'] ?? null;
            $name = $app['name'] ?? null;

            if (is_string($appSlug) && is_string($name) && strcasecmp($appSlug, $slug) === 0) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function settings(string $appName): array
    {
        foreach ($this->store->snapshot()['apps'] as $app) {
            $name = $app['name'] ?? null;

            if (is_string($name) && strcasecmp($name, $appName) === 0) {
                return $app;
            }
        }

        return [];
    }
}
