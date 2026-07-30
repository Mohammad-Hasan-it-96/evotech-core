<?php

namespace Modules\DeviceSubscriptions\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Modules\DeviceSubscriptions\Application\Services\SyncChangeService;
use Modules\DeviceSubscriptions\Domain\Models\DeviceBusiness;

/**
 * Prunes each business's sync change log of rows older than the retention window
 * (ADR 0011, Decision 14). Scheduled daily (see the module's Routes/console.php);
 * also runnable on demand with `--days=` to override the configured window.
 *
 * The oplog would otherwise grow unbounded — a busy shop writes on the order of a
 * thousand rows a day, most never read again after the first pull. Pruning bounds
 * that; a device offline longer than the window re-bootstraps from a snapshot
 * rather than replaying history that no longer exists.
 */
class PruneSyncChangesCommand extends Command
{
    protected $signature = 'device-subscriptions:prune-sync-changes {--days= : Override the configured retention window (days)}';

    protected $description = 'Prune sync change-log rows older than the retention window.';

    public function handle(SyncChangeService $changes): int
    {
        $option = $this->option('days');
        $days = $option !== null ? (int) $option : Config::integer('device-subscriptions.sync.retention_days');

        if ($days <= 0) {
            $this->warn('Sync change-log retention is disabled (days <= 0); nothing pruned.');

            return self::SUCCESS;
        }

        $cutoff = Carbon::now()->subDays($days);
        $total = 0;

        DeviceBusiness::query()
            ->select('id')
            ->chunkById(100, function ($businesses) use ($changes, $cutoff, &$total): void {
                foreach ($businesses as $business) {
                    $total += $changes->prune($business->id, $cutoff);
                }
            });

        $this->info("Pruned {$total} sync change(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
