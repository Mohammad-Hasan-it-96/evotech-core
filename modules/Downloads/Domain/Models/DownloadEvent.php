<?php

namespace Modules\Downloads\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Core\Domain\Concerns\HasUuid;

/**
 * Append-only ledger row recording that a signed download URL was issued for an
 * artifact (ADR 0008 — issue-time is the auditable download event). Never
 * updated or deleted; only `created_at` is managed.
 *
 * @property int $id
 * @property string $uuid
 * @property int $artifact_id
 * @property int|null $company_id
 * @property string $actor_type
 * @property string|null $actor_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon $created_at
 * @property-read Artifact $artifact
 */
class DownloadEvent extends Model
{
    use HasUuid;

    /** Immutable ledger — no updated_at. */
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'artifact_id',
        'company_id',
        'actor_type',
        'actor_id',
        'ip_address',
        'user_agent',
    ];

    /**
     * @return BelongsTo<Artifact, $this>
     */
    public function artifact(): BelongsTo
    {
        return $this->belongsTo(Artifact::class);
    }
}
