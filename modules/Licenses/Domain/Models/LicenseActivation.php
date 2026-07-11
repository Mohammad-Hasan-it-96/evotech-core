<?php

namespace Modules\Licenses\Domain\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Core\Domain\Concerns\HasUuid;
use Modules\Licenses\Database\Factories\LicenseActivationFactory;
use Modules\Subscriptions\Domain\Enums\IdentifierType;

/**
 * A single device/domain that has claimed one of a license's activation slots.
 * Reactivated in place if a previously deactivated identifier returns; a slot is
 * freed by setting `revoked_at`.
 *
 * @property int $id
 * @property string $uuid
 * @property int $license_id
 * @property IdentifierType $identifier_type
 * @property string $identifier
 * @property string|null $name
 * @property array<string, mixed>|null $meta
 * @property Carbon $activated_at
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read License $license
 */
class LicenseActivation extends Model
{
    /** @use HasFactory<LicenseActivationFactory> */
    use HasFactory;

    use HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'license_id',
        'identifier_type',
        'identifier',
        'name',
        'meta',
        'activated_at',
        'last_seen_at',
        'revoked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'identifier_type' => IdentifierType::class,
            'meta' => 'array',
            'activated_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** Whether this activation currently occupies a slot. */
    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /**
     * @return BelongsTo<License, $this>
     */
    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    protected static function newFactory(): LicenseActivationFactory
    {
        return LicenseActivationFactory::new();
    }
}
