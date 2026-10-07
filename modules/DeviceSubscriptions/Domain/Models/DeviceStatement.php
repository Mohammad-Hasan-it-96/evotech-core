<?php

namespace Modules\DeviceSubscriptions\Domain\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Core\Domain\Concerns\HasUuid;

/**
 * A frozen customer statement shared as a public link (ADR 0013).
 *
 * Holds a third party's debts, so it is minimal by construction: `payload` only ever
 * contains the keys DeviceStatementService whitelists, the token is stored hashed,
 * and the row is deleted on revoke or expiry.
 *
 * @property int $id
 * @property string $uuid
 * @property int $device_subscription_id
 * @property string $app_name
 * @property string $token_hash
 * @property array<string, mixed> $payload
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 */
class DeviceStatement extends Model
{
    use HasUuid;

    protected $fillable = [
        'device_subscription_id',
        'app_name',
        'token_hash',
        'payload',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<DeviceSubscription, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(DeviceSubscription::class, 'device_subscription_id');
    }

    /**
     * @param  Builder<DeviceStatement>  $query
     * @return Builder<DeviceStatement>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('expires_at', '>', Carbon::now());
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
