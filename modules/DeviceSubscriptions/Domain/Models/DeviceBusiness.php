<?php

namespace Modules\DeviceSubscriptions\Domain\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Core\Domain\Concerns\HasUuid;

/**
 * The multi-device subscription owner (ADR 0011). Owns the subscription, a seat
 * allowance, and the per-business change log. Deliberately non-tenant — a consumer
 * shop has no Company and no login. This is the scoping key for all sync data;
 * cross-business isolation is the feature's paramount security property.
 *
 * @property int $id
 * @property string $uuid
 * @property string $app_name
 * @property bool $is_verified
 * @property Carbon|null $expires_at
 * @property Carbon|null $trial_expires_at
 * @property string|null $plan_id
 * @property int $device_allowance
 * @property int $last_seq
 * @property int $pruned_through_seq
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DeviceBusiness extends Model
{
    use HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'app_name',
        'is_verified',
        'expires_at',
        'trial_expires_at',
        'plan_id',
        'device_allowance',
        'last_seq',
        'pruned_through_seq',
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
            'device_allowance' => 'integer',
            'last_seq' => 'integer',
            'pruned_through_seq' => 'integer',
        ];
    }

    /**
     * Effective subscription state, identical in spirit to
     * {@see DeviceSubscription::isActive()}: verified and not lapsed. A null
     * `expires_at` means lifetime. This gates *new selling*, never the push of
     * already-recorded changes (§10).
     */
    public function isActive(): bool
    {
        if (! $this->is_verified) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    /**
     * On a trial rather than a paid plan — the business twin of
     * {@see DeviceSubscription::isOnTrial()}. Derived from state: a trial expiry is
     * set, no paid plan yet, still unlocked. Surfaced as `is_trial` on check_device
     * for a device whose subscription state is sourced from this business
     * (ADR 0011, Decision 2).
     */
    public function isOnTrial(): bool
    {
        return $this->trial_expires_at !== null
            && $this->plan_id === null
            && $this->isActive();
    }

    /**
     * Live seat count = non-revoked seats. Seats are enforced at the business
     * level, never per device (Decision 3).
     */
    public function activeSeatCount(): int
    {
        return $this->seats()->whereNull('revoked_at')->count();
    }

    /** True when another seat may still be enrolled under the allowance. */
    public function hasSeatAvailable(): bool
    {
        return $this->activeSeatCount() < $this->device_allowance;
    }

    /**
     * @return HasMany<DeviceSeat, $this>
     */
    public function seats(): HasMany
    {
        return $this->hasMany(DeviceSeat::class);
    }

    /**
     * @return HasMany<DeviceChange, $this>
     */
    public function changes(): HasMany
    {
        return $this->hasMany(DeviceChange::class);
    }

    /**
     * @return HasMany<DeviceSubscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(DeviceSubscription::class, 'business_id');
    }

    /**
     * @param  Builder<DeviceBusiness>  $query
     * @return Builder<DeviceBusiness>
     */
    public function scopeForApp(Builder $query, string $appName): Builder
    {
        return $query->where('app_name', $appName);
    }
}
