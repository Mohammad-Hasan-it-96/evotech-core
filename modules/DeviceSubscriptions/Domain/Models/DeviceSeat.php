<?php

namespace Modules\DeviceSubscriptions\Domain\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Core\Domain\Concerns\HasUuid;

/**
 * A device's seat in a business, and its per-device sync credential (ADR 0011).
 *
 * Implements {@see AuthenticatableContract} so it can be the user the `device-sync`
 * guard resolves — exactly as Gateway's ProductApiKey backs `auth:product`. The
 * authenticated seat resolves a `business_id` server-side; a request body's
 * business/app_name is never trusted for authorization (Decision 4).
 *
 * @property int $id
 * @property string $uuid
 * @property int $device_business_id
 * @property string $app_name
 * @property string $device_id
 * @property string $node_id
 * @property string $role
 * @property string|null $name
 * @property string $prefix
 * @property string $token_hash
 * @property string|null $push_token
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read DeviceBusiness $business
 */
class DeviceSeat extends Model implements AuthenticatableContract
{
    use Authenticatable;
    use HasUuid;

    public const ROLE_OWNER = 'owner';

    public const ROLE_MEMBER = 'member';

    /** The owner-assigned display name is capped at this many (multibyte) characters. */
    public const NAME_MAX_LENGTH = 40;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'device_business_id',
        'app_name',
        'device_id',
        'node_id',
        'role',
        'name',
        'prefix',
        'token_hash',
        'push_token',
        'last_used_at',
        'revoked_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['token_hash', 'push_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** A live credential: not revoked. (Seats do not expire; the subscription does.) */
    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    public function isOwner(): bool
    {
        return $this->role === self::ROLE_OWNER;
    }

    /**
     * The HLC node id for this device — the first 16 hex chars of its device id
     * (§3). Deterministic, so it needs no mapping table; the no-echo pull filter
     * compares it against {@see DeviceChange::$origin_device}.
     */
    public static function nodeIdFor(string $deviceId): string
    {
        return substr($deviceId, 0, 16);
    }

    /**
     * Normalise an owner-assigned display name to what the column stores: trimmed,
     * capped at {@see NAME_MAX_LENGTH} multibyte chars (so Arabic counts as
     * characters, not bytes), and null when empty after trimming. The server is
     * authoritative — a proposed name may come back trimmed or truncated, so the
     * client renders the returned value rather than assuming its own survived.
     */
    public static function normalizeName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $trimmed = trim($name);

        if ($trimmed === '') {
            return null;
        }

        return mb_substr($trimmed, 0, self::NAME_MAX_LENGTH);
    }

    /**
     * @return BelongsTo<DeviceBusiness, $this>
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(DeviceBusiness::class, 'device_business_id');
    }

    /**
     * @param  Builder<DeviceSeat>  $query
     * @return Builder<DeviceSeat>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }
}
