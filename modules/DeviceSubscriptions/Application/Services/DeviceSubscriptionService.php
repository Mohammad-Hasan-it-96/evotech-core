<?php

namespace Modules\DeviceSubscriptions\Application\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Modules\DeviceSubscriptions\Domain\Contracts\DevicePushNotifier;
use Modules\DeviceSubscriptions\Domain\Events\DeviceActivated;
use Modules\DeviceSubscriptions\Domain\Models\DeviceSubscription;

/**
 * Device-subscription use-cases (ADR 0010). Preserves the legacy behavior exactly:
 * devices are keyed by (device_id, app_name) for self-service, but activation and
 * the admin listing match on device_id alone — see activate().
 */
final class DeviceSubscriptionService
{
    public function __construct(
        private readonly DevicePlanCatalog $plans,
        private readonly DevicePushNotifier $push,
    ) {}

    public function find(string $deviceId, string $appName): ?DeviceSubscription
    {
        return DeviceSubscription::query()->forDevice($deviceId, $appName)->first();
    }

    /**
     * Register a new device, or refresh an existing one's push token. Idempotent
     * on the (device_id, app_name) pair — mirrors legacy create_device.
     */
    public function registerOrTouch(
        string $appName,
        string $deviceId,
        string $fullName,
        string $phone,
        ?string $fcmToken,
    ): DeviceSubscription {
        $device = $this->find($deviceId, $appName);

        if ($device !== null) {
            if ($fcmToken !== null) {
                $device->update(['fcm_token' => $fcmToken]);
            }

            return $device;
        }

        return DeviceSubscription::create([
            'app_name' => $appName,
            'device_id' => $deviceId,
            'full_name' => $fullName,
            'phone' => $phone,
            'is_verified' => false,
            'fcm_token' => $fcmToken,
        ]);
    }

    public function updateProfile(
        DeviceSubscription $device,
        string $fullName,
        string $phone,
        ?string $fcmToken,
    ): void {
        $device->update([
            'full_name' => $fullName,
            'phone' => $phone,
            'fcm_token' => $fcmToken,
        ]);
    }

    public function addReview(DeviceSubscription $device, int $stars, ?string $comment): void
    {
        $device->update(['stars' => $stars, 'comment' => $comment]);
    }

    /**
     * Activate (or extend) a subscription. Looks up by device_id ONLY, matching
     * the legacy contract — the first matching row wins. Returns null if no device
     * exists. Unknown plan_id yields a 0-month term (immediate expiry), as before.
     */
    public function activate(string $deviceId, string $planId): ?DeviceSubscription
    {
        $device = DeviceSubscription::query()->where('device_id', $deviceId)->first();

        if ($device === null) {
            return null;
        }

        $expiresAt = Carbon::now()->addMonths($this->plans->durationMonths($planId));

        $device->update([
            'is_verified' => true,
            'plan_id' => $planId,
            'expires_at' => $expiresAt,
        ]);

        DeviceActivated::dispatch($device);

        if ($device->fcm_token) {
            $this->push->send(
                $device->fcm_token,
                '🎉 تم تفعيل اشتراكك بنجاح!',
                "أهلاً {$device->full_name}! تم تفعيل خطّتك بنجاح ✅\nتنتهي بتاريخ: {$expiresAt->format('Y/m/d')}",
                'new_plan_activated',
            );
        }

        return $device;
    }

    /**
     * All devices, latest first — backs the admin listing (legacy getDevice).
     *
     * @return Collection<int, DeviceSubscription>
     */
    public function all(): Collection
    {
        return DeviceSubscription::query()->latest()->get();
    }

    /**
     * Send expiry reminders to every device with a push token at the expired / 7 /
     * 3 / 1-day marks. Backs the scheduled sweep (legacy send_plan_notifications).
     * Returns the number of notifications sent.
     */
    public function sweepExpiryReminders(): int
    {
        $sent = 0;
        $now = Carbon::now();

        DeviceSubscription::query()
            ->whereNotNull('fcm_token')
            ->whereNotNull('expires_at')
            ->each(function (DeviceSubscription $device) use ($now, &$sent): void {
                /** @var Carbon $expiresAt */
                $expiresAt = $device->expires_at;
                $daysLeft = (int) $now->diffInDays($expiresAt, false);

                $message = match (true) {
                    $daysLeft < 0 => ['🔴 انتهت صلاحية اشتراكك', 'plan_deactivated'],
                    $daysLeft === 7 => ['📅 اشتراكك ينتهي بعد 7 أيام', 'still_7_days'],
                    $daysLeft === 3 => ['⏳ تبقّى 3 أيام على انتهاء اشتراكك', 'still_3_days'],
                    $daysLeft === 1 => ['⚠️ آخر يوم في اشتراكك!', 'still_1_day'],
                    default => null,
                };

                if ($message === null) {
                    return;
                }

                [$title, $type] = $message;
                $this->push->send(
                    (string) $device->fcm_token,
                    $title,
                    "عزيزي {$device->full_name}، يرجى تجديد اشتراكك في المندوب الذكي للاستمرار دون انقطاع.",
                    $type,
                );
                $sent++;
            });

        return $sent;
    }
}
