<?php

namespace Modules\DeviceSubscriptions\Application\Services;

use Modules\DeviceSubscriptions\Domain\Contracts\DevicePushNotifier;
use Modules\DeviceSubscriptions\Domain\Models\DeviceSeat;

/**
 * Rings the data-only "doorbell" (ADR 0011): after a device pushes, its siblings
 * get a silent, notification-less FCM wake so they pull within seconds instead of
 * waiting for the next poll.
 *
 * Best-effort by design. The doorbell only trims convergence latency — a device
 * that is offline, has no push token, or misses the wake still converges on its
 * regular poll, so a failed ring is never an error worth surfacing to the pusher.
 */
final class SyncDoorbell
{
    /** The data key siblings switch on to know this is a sync wake. */
    public const TYPE = 'sync';

    public function __construct(private readonly DevicePushNotifier $push) {}

    /**
     * Wake every live sibling seat of a business — every device except the one
     * that just wrote, that still holds an FCM token.
     */
    public function ring(int $businessId, int $excludeSeatId, string $appName): void
    {
        $siblings = DeviceSeat::query()
            ->where('device_business_id', $businessId)
            ->whereKeyNot($excludeSeatId)
            ->whereNull('revoked_at')
            ->whereNotNull('push_token')
            ->get(['id', 'push_token']);

        foreach ($siblings as $seat) {
            if (is_string($seat->push_token) && $seat->push_token !== '') {
                $this->push->sendData($appName, $seat->push_token, self::TYPE);
            }
        }
    }
}
