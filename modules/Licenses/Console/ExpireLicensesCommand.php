<?php

namespace Modules\Licenses\Console;

use Illuminate\Console\Command;
use Modules\Licenses\Application\Services\LicenseService;

/**
 * Marks active licenses past their expiry as expired. Scheduled daily
 * (see the module's Routes/console.php); also runnable on demand.
 */
class ExpireLicensesCommand extends Command
{
    protected $signature = 'licenses:expire';

    protected $description = 'Mark active licenses past their expiry date as expired.';

    public function handle(LicenseService $licenses): int
    {
        $count = $licenses->expireDue();

        $this->info("Expired {$count} license(s).");

        return self::SUCCESS;
    }
}
