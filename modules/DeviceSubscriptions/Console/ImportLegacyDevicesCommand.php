<?php

namespace Modules\DeviceSubscriptions\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\DeviceSubscriptions\Domain\Models\DeviceSubscription;

/**
 * One-off import of the legacy app_harfoshs rows into device_subscriptions
 * (ADR 0010). Reads from a separate DB connection (config
 * device-subscriptions.legacy.connection) and upserts by the (app_name, device_id)
 * pair, so it is safe to re-run. Fresh UUIDs are minted per row.
 *
 * Usage:
 *   DEVICE_LEGACY_CONNECTION=legacy php artisan device-subscriptions:import-legacy
 *   php artisan device-subscriptions:import-legacy --dry-run
 *   php artisan device-subscriptions:import-legacy --app=daftar_hesabat --dry-run
 *
 * `--app` scopes a re-import to one product. Each app cuts over on its own day
 * (docs/GO-LIVE-FAWATEER.md §7), and its fresh re-import right before the flip
 * must not drag other apps' drifted rows along with it.
 *
 * Both modes print a per-app report — counts only, never names or phones — that
 * classifies each legacy row against this server: `new` (not here yet),
 * `changed` (verified / plan / expiry differ — i.e. drift since the last import),
 * or `unchanged`. A dry run is therefore the drift check to run before a cutover.
 * `fallback ids` counts the shared unreadable-id buckets
 * ([DeviceSubscription::isFallbackId]); one holding a plan is warned about, since
 * that subscription belongs to no single device.
 */
class ImportLegacyDevicesCommand extends Command
{
    protected $signature = 'device-subscriptions:import-legacy
        {--dry-run : Report what would change without writing}
        {--app=* : Only import rows with this app_name (repeatable; exact match)}';

    protected $description = 'Import legacy app_harfoshs rows into device_subscriptions.';

    /** @var array<string, array{rows: int, new: int, changed: int, unchanged: int, with_plan: int, fallback: int}> */
    private array $report = [];

    public function handle(): int
    {
        // The command instance is reused by Artisan within one process.
        $this->report = [];

        $connection = config('device-subscriptions.legacy.connection');
        $table = config('device-subscriptions.legacy.table', 'app_harfoshs');

        if (! is_string($connection) || $connection === '') {
            $this->error('Set device-subscriptions.legacy.connection (DEVICE_LEGACY_CONNECTION) to the legacy database first.');

            return self::FAILURE;
        }

        if (! is_string($table)) {
            $table = 'app_harfoshs';
        }

        $dryRun = (bool) $this->option('dry-run');
        $apps = array_values(array_filter(
            (array) $this->option('app'),
            fn (mixed $app): bool => is_string($app) && $app !== '',
        ));
        $imported = 0;
        $skipped = 0;

        $query = DB::connection($connection)->table($table)->orderBy('id');

        if ($apps !== []) {
            $query->whereIn('app_name', $apps);
        }

        $query->each(
            function (object $row) use (&$imported, &$skipped, $dryRun): void {
                $appName = $row->app_name ?? null;
                $deviceId = $row->device_id ?? null;

                if (! is_string($appName) || ! is_string($deviceId)) {
                    $skipped++;

                    return;
                }

                $attributes = [
                    'full_name' => $row->full_name ?? null,
                    'phone' => $row->phone ?? null,
                    'is_verified' => (bool) ($row->is_verified ?? false),
                    'expires_at' => $row->expires_at ?? null,
                    'plan_id' => $row->plan_id ?? null,
                    'fcm_token' => $row->fcm_token ?? null,
                    'stars' => $row->stars ?? null,
                    'comment' => $row->comment ?? null,
                    'created_at' => $row->created_at ?? null,
                    'updated_at' => $row->updated_at ?? null,
                ];

                $existing = DeviceSubscription::query()
                    ->where('app_name', $appName)
                    ->where('device_id', $deviceId)
                    ->first();

                /*
                 * A device can exist in both worlds: registered fresh here (and
                 * granted a trial) AND present in the legacy dump. When the legacy
                 * row carries no subscription of its own (no plan, no expiry),
                 * letting it overwrite the subscription fields erased the trial's
                 * expiry — and a NULL expiry means "lifetime", so the device
                 * became verified forever (the 2026-07-22 production incident).
                 * Such a row may still refresh the profile; it must not touch the
                 * subscription. Legacy rows carrying real paid data still win.
                 */
                if (
                    $existing?->trial_expires_at !== null
                    && $attributes['plan_id'] === null
                    && $attributes['expires_at'] === null
                ) {
                    unset($attributes['is_verified'], $attributes['expires_at'], $attributes['plan_id']);
                }

                $this->tally($appName, $deviceId, $attributes, $existing);

                if (! $dryRun) {
                    DeviceSubscription::query()->updateOrCreate(
                        ['app_name' => $appName, 'device_id' => $deviceId],
                        $attributes,
                    );
                }

                $imported++;
            }
        );

        $verb = $dryRun ? 'Would import' : 'Imported';
        $this->info("{$verb} {$imported} device(s); skipped {$skipped} (missing app_name/device_id).");
        $this->printReport();

        return self::SUCCESS;
    }

    /**
     * Classify one legacy row against this server, by subscription fields only.
     *
     * @param  array<string, mixed>  $attributes  what the import would write
     */
    private function tally(string $appName, string $deviceId, array $attributes, ?DeviceSubscription $existing): void
    {
        $line = $this->report[$appName] ?? ['rows' => 0, 'new' => 0, 'changed' => 0, 'unchanged' => 0, 'with_plan' => 0, 'fallback' => 0];
        $line['rows']++;

        $plan = $attributes['plan_id'] ?? null;
        $hasPlan = is_string($plan) && $plan !== '';

        if ($hasPlan) {
            $line['with_plan']++;
        }

        if (DeviceSubscription::isFallbackId($deviceId)) {
            $line['fallback']++;

            if ($hasPlan) {
                $this->warn("{$appName}: a shared fallback device id holds plan '{$plan}' — that subscription belongs to no single device.");
            }
        }

        if ($existing === null) {
            $line['new']++;
        } elseif ($this->differs($attributes, $existing)) {
            $line['changed']++;
        } else {
            $line['unchanged']++;
        }

        $this->report[$appName] = $line;
    }

    /**
     * Whether the write would change the subscription. Fields the trial guard
     * removed from $attributes are not written, so they cannot differ.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function differs(array $attributes, DeviceSubscription $existing): bool
    {
        if (array_key_exists('is_verified', $attributes) && (bool) $attributes['is_verified'] !== $existing->is_verified) {
            return true;
        }

        if (array_key_exists('plan_id', $attributes) && $attributes['plan_id'] !== $existing->plan_id) {
            return true;
        }

        if (array_key_exists('expires_at', $attributes)) {
            $legacy = $attributes['expires_at'];
            $legacyTs = is_string($legacy) && $legacy !== '' ? Carbon::parse($legacy)->getTimestamp() : null;

            return $legacyTs !== $existing->expires_at?->getTimestamp();
        }

        return false;
    }

    private function printReport(): void
    {
        if ($this->report === []) {
            return;
        }

        ksort($this->report);
        $rows = [];

        foreach ($this->report as $app => $line) {
            $rows[] = [$app, $line['rows'], $line['new'], $line['changed'], $line['unchanged'], $line['with_plan'], $line['fallback']];
        }

        $this->table(['app_name', 'rows', 'new', 'changed', 'unchanged', 'with plan', 'fallback ids'], $rows);
    }
}
