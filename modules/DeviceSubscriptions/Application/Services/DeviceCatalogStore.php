<?php

namespace Modules\DeviceSubscriptions\Application\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Modules\DeviceSubscriptions\Domain\Models\DeviceApp;
use Modules\DeviceSubscriptions\Domain\Models\DevicePlan;

/**
 * Loads the app + plan catalog once and caches it.
 *
 * Every device poll (`check_device`) reads this, so it cannot be a fresh pair of
 * queries per request — it used to be a config array, which cost nothing. The cache
 * is dropped explicitly on every write, so an operator's price edit is live
 * immediately; the TTL only bounds staleness from edits made straight in the
 * database.
 *
 * Falls back to config/device-subscriptions.php when the tables are missing or
 * empty. That is not belt-and-braces: it keeps the shipped apps selling through the
 * window between the new code booting and its migration finishing, and it means a
 * catalog accidentally emptied degrades to the last known-good prices rather than
 * offering customers nothing.
 */
final class DeviceCatalogStore
{
    private const CACHE_KEY = 'device-subscriptions:catalog';

    private const CACHE_TTL_SECONDS = 300;

    /** @var array{apps: list<array<array-key, mixed>>, shared_plans: list<array<array-key, mixed>>}|null */
    private ?array $memo = null;

    /**
     * @return array{apps: list<array<array-key, mixed>>, shared_plans: list<array<array-key, mixed>>}
     */
    public function snapshot(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $cached = Cache::get(self::CACHE_KEY);

        if (! is_array($cached)) {
            $snapshot = $this->load();
            Cache::put(self::CACHE_KEY, $snapshot, self::CACHE_TTL_SECONDS);

            return $this->memo = $snapshot;
        }

        // Re-shaped rather than trusted: a cache entry written by an older
        // deployment (or a half-written one) must not reach the device API as a
        // malformed plan list.
        return $this->memo = [
            'apps' => $this->entries($cached['apps'] ?? null),
            'shared_plans' => $this->entries($cached['shared_plans'] ?? null),
        ];
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function entries(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $entries = [];

        foreach ($value as $entry) {
            if (is_array($entry)) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /** Drop the cached catalog. Call after any write to device_apps/device_plans. */
    public function flush(): void
    {
        $this->memo = null;
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array{apps: list<array<array-key, mixed>>, shared_plans: list<array<array-key, mixed>>}
     */
    private function load(): array
    {
        try {
            $apps = DeviceApp::query()->with('plans')->get();
            $sharedPlans = DevicePlan::query()
                ->whereNull('device_app_id')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();
        } catch (QueryException) {
            // Tables not migrated yet.
            return $this->fromConfig();
        }

        if ($apps->isEmpty()) {
            return $this->fromConfig();
        }

        return [
            'apps' => array_values($apps->map(fn (DeviceApp $app): array => [
                'name' => $app->name,
                'slug' => $app->slug,
                'label' => $app->label,
                'trial_days' => $app->trial_days,
                // null = defer to the shared list; [] = sells nothing. Kept apart.
                'plans' => $app->uses_shared_plans
                    ? null
                    : $app->plans->map(fn (DevicePlan $plan): array => $plan->toLegacyArray())->all(),
            ])->all()),
            'shared_plans' => array_values(
                $sharedPlans->map(fn (DevicePlan $plan): array => $plan->toLegacyArray())->all(),
            ),
        ];
    }

    /**
     * @return array{apps: list<array<array-key, mixed>>, shared_plans: list<array<array-key, mixed>>}
     */
    private function fromConfig(): array
    {
        $configured = config('device-subscriptions.apps', []);
        $configured = is_array($configured) ? $configured : [];

        $apps = [];

        foreach ($configured as $name => $settings) {
            if (! is_string($name) || ! is_array($settings)) {
                continue;
            }

            $plans = $settings['plans'] ?? null;
            $trialDays = $settings['trial_days'] ?? 0;
            $slug = $settings['slug'] ?? null;
            $label = $settings['label'] ?? null;

            $apps[] = [
                'name' => $name,
                'slug' => is_string($slug) ? $slug : strtolower($name),
                'label' => is_string($label) && $label !== '' ? $label : $name,
                'trial_days' => is_numeric($trialDays) ? max(0, (int) $trialDays) : 0,
                'plans' => is_array($plans) ? array_values($plans) : null,
            ];
        }

        return [
            'apps' => $apps,
            'shared_plans' => $this->entries(config('device-subscriptions.plans', [])),
        ];
    }
}
