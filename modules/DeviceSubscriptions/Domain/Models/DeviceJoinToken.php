<?php

namespace Modules\DeviceSubscriptions\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Core\Domain\Concerns\HasUuid;

/**
 * A single-use enrollment token plus its bootstrap handoff (ADR 0011).
 *
 * Minted by an owner device, presented once by a joining device. Carries the
 * snapshot seed (Decision 13) so a device joining an existing shop does not come
 * up blank. See the migration header for the full contract.
 *
 * @property int $id
 * @property string $uuid
 * @property int $device_business_id
 * @property string $token_hash
 * @property int|null $bootstrap_cursor
 * @property string|null $snapshot_path
 * @property string|null $snapshot_sha256
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read DeviceBusiness $business
 */
class DeviceJoinToken extends Model
{
    use HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'device_business_id',
        'token_hash',
        'bootstrap_cursor',
        'snapshot_path',
        'snapshot_sha256',
        'expires_at',
        'consumed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bootstrap_cursor' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    /** Still redeemable: neither consumed nor past its short TTL. */
    public function isUsable(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture();
    }

    /**
     * @return BelongsTo<DeviceBusiness, $this>
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(DeviceBusiness::class, 'device_business_id');
    }
}
