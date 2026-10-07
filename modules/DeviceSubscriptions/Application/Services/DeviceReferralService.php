<?php

namespace Modules\DeviceSubscriptions\Application\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Core\Domain\Contracts\AuditLogger;
use Modules\DeviceSubscriptions\Domain\Contracts\DevicePushNotifier;
use Modules\DeviceSubscriptions\Domain\Models\DeviceReferralReward;
use Modules\DeviceSubscriptions\Domain\Models\DeviceSubscription;

/**
 * Device-app referrals (ADR 0012): «ادعُ محلاً واحصل على شهر مجاني».
 *
 * The rule everything here protects: **a reward is paid only when the invited device
 * is activated by an operator** — never on install or trial. Device ids cost nothing
 * to fabricate, so anything granted earlier could be farmed without limit.
 *
 * Opt-in per app (`device_apps.referral_reward_days`); for an app at 0 every method
 * here is a no-op, so Fawateer and SmartAgent are untouched.
 */
final class DeviceReferralService
{
    /** No 0/O or 1/I: codes get read out over the phone and typed by hand. */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const CODE_LENGTH = 6;

    public function __construct(
        private readonly DeviceAppCatalog $apps,
        private readonly DevicePushNotifier $push,
        private readonly AuditLogger $audit,
    ) {}

    public function enabled(string $appName): bool
    {
        return $this->apps->referralRewardDays($appName) > 0;
    }

    /**
     * The device's invite code, minted on first use; null when the app does not run
     * referrals, or for the shared fallback bucket (a code there would be shared by
     * unrelated shops).
     */
    public function codeFor(DeviceSubscription $device): ?string
    {
        if (! $this->enabled((string) $device->app_name) || $device->isFallback()) {
            return null;
        }

        if ($device->referral_code !== null) {
            return $device->referral_code;
        }

        // Unique per app; a collision (≈1 in a billion per draw) just draws again.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = self::generate();

            try {
                $device->forceFill(['referral_code' => $code])->save();

                return $code;
            } catch (UniqueConstraintViolationException) {
                $device->referral_code = null;
            }
        }

        return null;
    }

    /**
     * Rewards this device has earned that actually granted days (what the app shows).
     */
    public function rewardCount(DeviceSubscription $device): int
    {
        return $device->referralRewards()->where('days', '>', 0)->count();
    }

    /**
     * Record who invited this device. Set once, and only while the device has never
     * been activated: a customer who already pays cannot be re-attributed.
     *
     * Every rejection is silent (unknown code, own code, fallback bucket). This runs
     * inside create_device, and a shipped client must never fail registration
     * because someone mistyped a code.
     */
    public function attach(DeviceSubscription $device, ?string $code): void
    {
        $code = self::normalize($code);

        if ($code === null
            || ! $this->enabled((string) $device->app_name)
            || $device->isFallback()
            || $device->referred_by_id !== null
            || $device->plan_id !== null) {
            return;
        }

        $referrer = DeviceSubscription::query()
            ->where('app_name', $device->app_name)
            ->where('referral_code', $code)
            ->first();

        if ($referrer === null || $referrer->id === $device->id || $referrer->isFallback()) {
            return;
        }

        $device->forceFill(['referred_by_id' => $referrer->id])->save();
    }

    /**
     * Pay the referrer for this device's activation, if it was referred and has
     * never paid out before. Called from every activation; renewals find the
     * existing reward row and do nothing.
     */
    public function rewardActivation(DeviceSubscription $referred): void
    {
        $days = $this->apps->referralRewardDays((string) $referred->app_name);

        if ($referred->referred_by_id === null || $days <= 0) {
            return;
        }

        try {
            $reward = DB::transaction(function () use ($referred, $days): ?DeviceReferralReward {
                if (DeviceReferralReward::query()->where('referred_id', $referred->id)->exists()) {
                    return null;
                }

                $referrer = DeviceSubscription::query()
                    ->lockForUpdate()
                    ->find($referred->referred_by_id);

                if ($referrer === null || $referrer->isFallback() || $referrer->app_name !== $referred->app_name) {
                    return null;
                }

                $granted = $this->underCap($referrer) ? $this->extend($referrer, $days) : 0;

                return DeviceReferralReward::create([
                    'referrer_id' => $referrer->id,
                    'referred_id' => $referred->id,
                    'app_name' => (string) $referred->app_name,
                    'days' => $granted,
                    'granted_at' => Carbon::now(),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent activation of the same device paid out first.
            return;
        }

        if ($reward === null) {
            return;
        }

        $referrer = $reward->referrer()->first();

        $this->audit->log('device_referral.rewarded', 'device_subscription', $referrer?->uuid, [
            'app_name' => $reward->app_name,
            'referred' => $referred->uuid,
            'days' => $reward->days,
        ]);

        if ($reward->days > 0 && $referrer?->fcm_token) {
            $label = $this->apps->label($reward->app_name);

            // `new_plan_activated`: the type the clients already treat as "re-check now".
            $this->push->send(
                $reward->app_name,
                $referrer->fcm_token,
                '🎁 حصلت على اشتراك مجاني!',
                "اشترك محلّ دعوته إلى {$label}، فأضفنا {$reward->days} يوماً إلى اشتراكك. شكراً لك! 💙",
                'new_plan_activated',
            );
        }
    }

    private function underCap(DeviceSubscription $referrer): bool
    {
        $cap = config('device-subscriptions.referrals.max_rewards_per_year', 12);
        $cap = is_numeric($cap) ? (int) $cap : 12;

        $paid = $referrer->referralRewards()
            ->where('days', '>', 0)
            ->where('granted_at', '>=', Carbon::now()->subYear())
            ->count();

        return $paid < $cap;
    }

    /**
     * Add the reward to whatever governs the referrer's access: its business when
     * linked (ADR 0011, Decision 2), otherwise the device. A lapsed or Free referrer
     * starts from now. A lifetime one (no expiry) cannot be extended, so it earns 0.
     *
     * @return int the days actually granted
     */
    private function extend(DeviceSubscription $referrer, int $days): int
    {
        $source = $referrer->business_id !== null
            ? ($referrer->business()->first() ?? $referrer)
            : $referrer;

        if ($source->is_verified && $source->expires_at === null) {
            return 0;
        }

        $from = $source->expires_at !== null && $source->expires_at->isFuture()
            ? $source->expires_at
            : Carbon::now();

        $source->update([
            'is_verified' => true,
            'expires_at' => $from->copy()->addDays($days),
        ]);

        return $days;
    }

    private static function generate(): string
    {
        $code = '';

        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }

    /** Upper-cased, with the spaces and dashes people type stripped; null if empty. */
    private static function normalize(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        $code = strtoupper((string) preg_replace('/[\s\-]+/u', '', $code));

        return $code === '' || strlen($code) > 12 ? null : $code;
    }
}
