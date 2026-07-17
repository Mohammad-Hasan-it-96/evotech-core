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
        private readonly DeviceAppCatalog $apps,
        private readonly DevicePushNotifier $push,
    ) {}

    public function find(string $deviceId, string $appName): ?DeviceSubscription
    {
        return DeviceSubscription::query()->forDevice($deviceId, $appName)->first();
    }

    /**
     * Register a new device, or refresh an existing one. Idempotent on the
     * (device_id, app_name) pair — mirrors legacy create_device.
     *
     * The shipped Fawateer app reuses this endpoint to file a purchase intent
     * (requested_plan + contact_method + status:'pending'), and it does so for a
     * device that is normally already registered — so the request fields must be
     * recorded on the existing row, not just at creation.
     */
    public function registerOrTouch(
        string $appName,
        string $deviceId,
        string $fullName,
        string $phone,
        ?string $fcmToken,
        ?string $requestedPlan = null,
        ?string $contactMethod = null,
        ?string $status = null,
    ): DeviceSubscription {
        $device = $this->find($deviceId, $appName);

        $planRequest = self::present([
            'requested_plan' => $requestedPlan,
            'contact_method' => $contactMethod,
            'status' => $status,
        ]);

        if ($device !== null) {
            $changes = $planRequest;

            if ($fcmToken !== null) {
                $changes['fcm_token'] = $fcmToken;
            }

            if ($changes !== []) {
                $device->update($changes);
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
            ...$this->grantTrial($appName),
            ...$planRequest,
        ]);
    }

    /**
     * The free trial, stamped once at registration for apps that configure one.
     *
     * Granting it **only on creation** is what makes it unfarmable: the row is
     * keyed by (app_name, device_id) and Android's ANDROID_ID survives uninstall
     * and data-clear, so a reinstall finds the existing row and takes this path's
     * absence — it cannot mint a second trial. Devices already known (including
     * the imported legacy rows) are never retro-granted one.
     *
     * `expires_at` carries the trial expiry because the app gates on that field
     * and never reads `trial_expires_at`; the latter is our own record that a
     * trial was given, and it survives conversion to a paid plan.
     *
     * @return array<string, mixed>
     */
    private function grantTrial(string $appName): array
    {
        $days = $this->apps->trialDays($appName);

        if ($days <= 0) {
            return [];
        }

        $expiresAt = Carbon::now()->addDays($days);

        return [
            'is_verified' => true,
            'expires_at' => $expiresAt,
            'trial_expires_at' => $expiresAt,
        ];
    }

    /**
     * Partial profile update — only the fields actually sent are written.
     *
     * The app rotates its push token by sending fcm_token alone, and edits the
     * profile by sending full_name/phone alone. Writing the absent fields would
     * blank them (a name edit used to wipe the push token, silently costing the
     * device its live-unlock notification).
     */
    public function updateProfile(
        DeviceSubscription $device,
        ?string $fullName,
        ?string $phone,
        ?string $fcmToken,
    ): void {
        $changes = self::present([
            'full_name' => $fullName,
            'phone' => $phone,
            'fcm_token' => $fcmToken,
        ]);

        if ($changes !== []) {
            $device->update($changes);
        }
    }

    /**
     * Drop the keys whose value was not supplied.
     *
     * @param  array<string, string|null>  $values
     * @return array<string, string>
     */
    private static function present(array $values): array
    {
        return array_filter($values, static fn (?string $value): bool => $value !== null);
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
                // Per-app label: one deployment serves several apps, so a Fawateer
                // user must not be asked to renew "المندوب الذكي".
                $label = $this->apps->label((string) $device->app_name);
                $this->push->send(
                    (string) $device->fcm_token,
                    $title,
                    "عزيزي {$device->full_name}، يرجى تجديد اشتراكك في {$label} للاستمرار دون انقطاع.",
                    $type,
                );
                $sent++;
            });

        return $sent;
    }
}
