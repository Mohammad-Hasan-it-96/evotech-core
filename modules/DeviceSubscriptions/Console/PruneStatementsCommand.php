<?php

namespace Modules\DeviceSubscriptions\Console;

use Illuminate\Console\Command;
use Modules\DeviceSubscriptions\Application\Services\DeviceStatementService;

/**
 * Deletes expired statement links (ADR 0013). Scheduled daily: expiry has to remove
 * a debtor's data, not merely stop serving it.
 */
class PruneStatementsCommand extends Command
{
    protected $signature = 'device-subscriptions:prune-statements';

    protected $description = 'Delete expired public statement links.';

    public function handle(DeviceStatementService $statements): int
    {
        $this->info("Deleted {$statements->prune()} expired statement link(s).");

        return self::SUCCESS;
    }
}
