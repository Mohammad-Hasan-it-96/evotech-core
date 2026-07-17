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
            ...$this->grantTrial($appName, $deviceId),
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
     * The shared fallback id gets nothing: it is one bucket for every device that
     * could not read its own id, so a trial stamped on it would be inherited by
     * every later arrival — the first one consumes it, and everyone after is
     * locked out on day one holding someone else's expiry. Declining costs those
     * devices nothing, because the fallback is transient: they re-register under
     * their real id on a later launch and are trialled properly then.
     *
     * @return array<string, mixed>
     */
    private function grantTrial(string $appName, string $deviceId): array
    {
        if (DeviceSubscription::isFallbackId($deviceId)) {
            return [];
        }

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
     * Resolve the device an operator means to activate, by the id they were given.
     *
     * Scoped by app_name when known: several products share this deployment, and
     * while their salted ids never collide, the shared fallback id does — so an
     * unscoped lookup could hand back another product's row. The fallback id is
     * refused outright ([DeviceSubscription::FALLBACK_DEVICE_ID]): it is a bucket,
     * not a device, and activating it would license every device in it.
     */
    public function findForActivation(string $deviceId, ?string $appName = null): ?DeviceSubscription
    {
        if (DeviceSubscription::isFallbackId($deviceId)) {
            return null;
        }

        return DeviceSubscription::query()
            ->where('device_id', $deviceId)
            ->when($appName !== null, fn ($q) => $q->where('app_name', $appName))
            ->first();
    }

    /**
     * Activate (or extend) a subscription on a device the caller has resolved.
     *
     * Takes the model rather than an id: the id is not reliably unique (the shared
     * fallback bucket, and any duplicate a pre-unique-index race left behind), so
     * re-querying by it risks activating a row other than the one the operator
     * chose — silently licensing a stranger while the console reports failure.
     * Unknown plan_id yields a 0-month term (immediate expiry), as before.
     *
     * The term is read from **this device's own** app catalog (Phase D): apps may
     * price and size plans differently, so the same id can mean six months in one
     * and twelve in another. Resolving against the wrong catalog would hand a
     * paying customer the wrong expiry.
     */
    public function activate(DeviceSubscription $device, string $planId): DeviceSubscription
    {
        $expiresAt = Carbon::now()->addMonths(
            $this->plans->durationMonths($planId, $device->app_name),
        );

        $device->update([
            'is_verified' => true,
            'plan_id' => $planId,
            'expires_at' => $expiresAt,
            // Closes the purchase intent this activation fulfils, so the console's
            // pending queue drains. `requested_plan` stays as the record of what
            // was asked for — the operator may have sold a different plan.
            'status' => null,
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
