<?php

namespace Modules\Licenses\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Core\Domain\Concerns\HasUuid;
use Modules\Licenses\Domain\Enums\LicenseEventType;

/**
 * Append-only ledger row for a license (constitution §6 — immutable license
 * ledger). Never updated or deleted; only `created_at` is managed.
 *
 * @property int $id
 * @property string $uuid
 * @property int $license_id
 * @property LicenseEventType $event_type
 * @property string $actor_type
 * @property string|null $actor_id
 * @property array<string, mixed>|null $context
 * @property Carbon $created_at
 * @property-read License $license
 */
class LicenseEvent extends Model
{
    use HasUuid;

    /** Immutable ledger — no updated_at. */
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'license_id',
        'event_type',
        'actor_type',
        'actor_id',
        'context',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_type' => LicenseEventType::class,
            'context' => 'array',
        ];
    }

    /**
     * @return BelongsTo<License, $this>
     */
    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }
}
