<?php

namespace Modules\DeviceSubscriptions\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Modules\DeviceSubscriptions\Domain\Models\DeviceSubscription;

/**
 * Published when a device's subscription is activated or renewed. Other modules
 * (e.g. Notifications, Audit) may react via listeners; DeviceSubscriptions does
 * not depend on them (constitution §2.1: cross-module comms via events only).
 */
final class DeviceActivated
{
    use Dispatchable;

    public function __construct(public readonly DeviceSubscription $device) {}
}
