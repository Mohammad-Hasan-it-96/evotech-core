<?php

namespace Modules\DeviceSubscriptions\Domain\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Core\Domain\Concerns\HasUuid;
use Modules\DeviceSubscriptions\Database\Factories\DeviceSubscriptionFactory;

/**
 * A consumer device's subscription (ADR 0010). Keyed by the (app_name, device_id)
 * pair. Deliberately non-tenant — no company_id / BelongsToCompany.
 *
 * @property int $id
 * @property string $uuid
 * @property string|null $app_name
 * @property string|null $device_id
 * @property string|null $full_name
 * @property string|null $phone
 * @property string|null $google_account
 * @property bool $is_verified
 * @property string|null $status
 * @property Carbon|null $expires_at
 * @property Carbon|null $trial_expires_at
 * @property string|null $plan_id
 * @property string|null $requested_plan
 * @property string|null $contact_method
 * @property string|null $fcm_token
 * @property int|null $stars
 * @property string|null $comment
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DeviceSubscription extends Model
{
    /**
     * The literal id every client sends when it cannot read its real one.
     *
     * The apps hash their platform id with a per-app salt, so real ids never
     * collide — but the unreadable-id fallback is a hardcoded constant, identical
     * across every device AND every app. It is therefore not an identity at all:
     * it is a shared bucket, and treating it as one device would let unrelated
     * shops inherit each other's subscription. Quarantined at both gates — it is
     * never granted a trial and never activated.
     *
     * Benign in practice because it is transient: a device that fails to read its
     * ANDROID_ID (the client also falls back on a 3s platform-channel timeout)
     * resolves it on a later launch and registers properly under its real id,
     * leaving this row as inert junk.
     */
    public const FALLBACK_DEVICE_ID = 'fallback_device_id';

    /**
     * `status` tracks the purchase intent only, never the subscription.
     *
     * PENDING is written by the app when the user asks to buy; the operator then
     * either activates (which clears it back to null) or declines. A device with
     * no open request has null here — including one with a perfectly good paid
     * plan, because whether someone is *asking* to buy is a different question
     * from whether they currently *have* access.
     */
    public const STATUS_PENDING = 'pending';

    public const STATUS_DECLINED = 'declined';

    /** @use HasFactory<DeviceSubscriptionFactory> */
    use HasFactory;

    use HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'app_name',
        'device_id',
        'full_name',
        'phone',
        'google_account',
        'is_verified',
        'status',
        'expires_at',
        'trial_expires_at',
        'plan_id',
        'requested_plan',
        'contact_method',
        'fcm_token',
        'stars',
        'comment',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_verified' => 'boolean',
            'expires_at' => 'datetime',
            'trial_expires_at' => 'datetime',
            'stars' => 'integer',
        ];
    }

    /**
     * Effective verification: a device is only truly active if it is verified AND
     * its subscription has not lapsed (legacy forces is_verified=0 past expiry).
     */
    public function isActive(): bool
    {
        if (! $this->is_verified) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    /**
     * On a trial rather than a paid plan — surfaced to the app as `is_trial`.
     *
     * Derived from state, not from `status`: a trial is "granted a trial expiry,
     * still unlocked, and no paid plan yet". Activation sets plan_id, which ends
     * the trial without needing to rewrite any flag. `status` stays free to mean
     * only what the app sends it for (the pending plan request).
     */
    public function isOnTrial(): bool
    {
        return $this->trial_expires_at !== null
            && $this->plan_id === null
            && $this->isActive();
    }

    /**
     * The Drive backup account, reduced to a recognition cue.
     *
     * Keeps the first and last character of the local part plus the domain, and
     * masks everything between: `sara.backups@gmail.com` → `s••••••••••s@gmail.com`.
     *
     * The shape is chosen for what an owner needs to recall *their own* account —
     * initial, length and provider are enough for that, and none of them identify
     * them to a stranger. A fixed-length mask would hide marginally more while
     * making two of a user's own accounts indistinguishable, which defeats the
     * point of showing anything.
     *
     * The domain survives deliberately: Google accounts are overwhelmingly
     * gmail.com, so it carries little identifying signal while being the one part
     * that tells a user "this is my personal account, not my work one". A custom
     * domain does reveal an organisation — the residual risk here, and far smaller
     * than the address itself.
     *
     * A local part of two characters or fewer is masked entirely; there is no way
     * to show an initial without showing most of it.
     */
    public function maskedGoogleAccount(): ?string
    {
        $account = $this->google_account;

        if (! is_string($account) || $account === '') {
            return null;
        }

        $at = mb_strrpos($account, '@');

        // Not address-shaped. Validation should prevent it, but this feeds a
        // public surface: degrade to hiding more, never less.
        if ($at === false || $at === 0) {
            return mb_substr($account, 0, 1).str_repeat('•', max(1, mb_strlen($account) - 1));
        }

        $local = mb_substr($account, 0, $at);
        $domain = mb_substr($account, $at);
        $length = mb_strlen($local);

        if ($length <= 2) {
            return str_repeat('•', $length).$domain;
        }

        return mb_substr($local, 0, 1)
            .str_repeat('•', $length - 2)
            .mb_substr($local, -1)
            .$domain;
    }

    /**
     * True when the id is the shared unreadable-id bucket, not a real device.
     * See [self::FALLBACK_DEVICE_ID].
     */
    public static function isFallbackId(?string $deviceId): bool
    {
        return $deviceId === self::FALLBACK_DEVICE_ID;
    }

    /** True when this row is the shared bucket rather than one device. */
    public function isFallback(): bool
    {
        return self::isFallbackId($this->device_id);
    }

    /**
     * Locate a device by its (device_id, app_name) pair.
     *
     * @param  Builder<DeviceSubscription>  $query
     * @return Builder<DeviceSubscription>
     */
    public function scopeForDevice(Builder $query, string $deviceId, string $appName): Builder
    {
        return $query->where('device_id', $deviceId)->where('app_name', $appName);
    }

    protected static function newFactory(): DeviceSubscriptionFactory
    {
        return DeviceSubscriptionFactory::new();
    }
}
