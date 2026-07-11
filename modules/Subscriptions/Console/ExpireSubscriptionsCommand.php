<?php

namespace Modules\Subscriptions\Console;

use Illuminate\Console\Command;
use Modules\Subscriptions\Application\Services\SubscriptionService;

/**
 * Marks active subscriptions past their end date as expired. Scheduled daily
 * (see the module's Routes/console.php); also runnable on demand.
 */
class ExpireSubscriptionsCommand extends Command
{
    protected $signature = 'subscriptions:expire';

    protected $description = 'Mark active subscriptions past their end date as expired.';

    public function handle(SubscriptionService $subscriptions): int
    {
        $count = $subscriptions->expireDue();

        $this->info("Expired {$count} subscription(s).");

        return self::SUCCESS;
    }
}
