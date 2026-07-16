<?php

namespace Modules\DeviceSubscriptions\Console;

use Illuminate\Console\Command;
use Modules\DeviceSubscriptions\Application\Services\DeviceSubscriptionService;

/**
 * Sends push reminders to devices at the expired / 7 / 3 / 1-day marks. Scheduled
 * daily (see the module's Routes/console.php); also runnable on demand. Replaces
 * the legacy public send_plan_notifications endpoint.
 */
class SweepDeviceExpiryCommand extends Command
{
    protected $signature = 'device-subscriptions:sweep-expiry';

    protected $description = 'Send subscription-expiry push reminders to devices.';

    public function handle(DeviceSubscriptionService $devices): int
    {
        $sent = $devices->sweepExpiryReminders();

        $this->info("Sent {$sent} expiry reminder(s).");

        return self::SUCCESS;
    }
}
