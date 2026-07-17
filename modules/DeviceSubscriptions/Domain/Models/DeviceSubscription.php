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
