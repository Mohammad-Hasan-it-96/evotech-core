<?php

namespace Modules\Payments\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Core\Domain\Concerns\HasUuid;
use Modules\Payments\Domain\Enums\PaymentEventType;

/**
 * Append-only ledger row for an invoice (constitution §5 — immutable payment
 * ledger). Never updated or deleted; only `created_at` is managed.
 *
 * @property int $id
 * @property string $uuid
 * @property int $invoice_id
 * @property PaymentEventType $event_type
 * @property string $actor_type
 * @property string|null $actor_id
 * @property array<string, mixed>|null $context
 * @property Carbon $created_at
 * @property-read Invoice $invoice
 */
class PaymentEvent extends Model
{
    use HasUuid;

    /** Immutable ledger — no updated_at. */
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'invoice_id',
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
            'event_type' => PaymentEventType::class,
            'context' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
