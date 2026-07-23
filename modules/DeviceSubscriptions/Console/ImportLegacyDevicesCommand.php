<?php

namespace Modules\DeviceSubscriptions\Console;

use Illuminate\Console\Command;
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
 */
class ImportLegacyDevicesCommand extends Command
{
    protected $signature = 'device-subscriptions:import-legacy {--dry-run : Report counts without writing}';

    protected $description = 'Import legacy app_harfoshs rows into device_subscriptions.';

    public function handle(): int
    {
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
        $imported = 0;
        $skipped = 0;

        DB::connection($connection)->table($table)->orderBy('id')->each(
            function (object $row) use (&$imported, &$skipped, $dryRun): void {
                $appName = $row->app_name ?? null;
                $deviceId = $row->device_id ?? null;

                if ($appName === null || $deviceId === null) {
                    $skipped++;

                    return;
                }

                if ($dryRun) {
                    $imported++;

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
                $existing = DeviceSubscription::query()
                    ->where('app_name', $appName)
                    ->where('device_id', $deviceId)
                    ->first();

                if (
                    $existing?->trial_expires_at !== null
                    && $attributes['plan_id'] === null
                    && $attributes['expires_at'] === null
                ) {
                    unset($attributes['is_verified'], $attributes['expires_at'], $attributes['plan_id']);
                }

                DeviceSubscription::query()->updateOrCreate(
                    ['app_name' => $appName, 'device_id' => $deviceId],
                    $attributes,
                );
                $imported++;
            }
        );

        $verb = $dryRun ? 'Would import' : 'Imported';
        $this->info("{$verb} {$imported} device(s); skipped {$skipped} (missing app_name/device_id).");

        return self::SUCCESS;
    }
}
