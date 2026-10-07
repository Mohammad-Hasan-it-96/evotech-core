<?php

namespace Modules\DeviceSubscriptions\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One referral that paid out (ADR 0012): the referred device's first paid activation
 * granted the referrer `days` of subscription. `days` is 0 when the referral was
 * recorded but earned nothing (the yearly cap, or a lifetime referrer).
 *
 * `referred_id` is UNIQUE — one reward per referred device, ever — so renewals and
 * concurrent activations cannot pay out twice.
 *
 * @property int $id
 * @property int|null $referrer_id
 * @property int|null $referred_id
 * @property string $app_name
 * @property int $days
 * @property Carbon $granted_at
 */
class DeviceReferralReward extends Model
{
    protected $fillable = [
        'referrer_id',
        'referred_id',
        'app_name',
        'days',
        'granted_at',
    ];

    protected function casts(): array
    {
        return [
            'days' => 'int',
            'granted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<DeviceSubscription, $this> */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(DeviceSubscription::class, 'referrer_id');
    }

    /** @return BelongsTo<DeviceSubscription, $this> */
    public function referred(): BelongsTo
    {
        return $this->belongsTo(DeviceSubscription::class, 'referred_id');
    }
}
