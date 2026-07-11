<?php

namespace Modules\Audit\Domain\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Audit\Database\Factories\AuditLogFactory;
use Modules\Core\Domain\Concerns\HasUuid;

/**
 * Append-only audit-trail row (constitution §5/§6.14, ADR 0007). Immutable —
 * never updated or deleted; only `created_at` is managed.
 *
 * @property int $id
 * @property string $uuid
 * @property string $action
 * @property string $actor_type
 * @property string|null $actor_id
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property array<string, mixed>|null $context
 * @property string|null $ip_address
 * @property Carbon $created_at
 */
class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    use HasUuid;

    /** Immutable ledger — no updated_at. */
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'action',
        'actor_type',
        'actor_id',
        'subject_type',
        'subject_id',
        'context',
        'ip_address',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context' => 'array',
        ];
    }

    protected static function newFactory(): AuditLogFactory
    {
        return AuditLogFactory::new();
    }
}
