<?php

namespace Modules\Payments\Domain\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Companies\Domain\Models\Company;
use Modules\Core\Domain\Concerns\HasUuid;
use Modules\Payments\Database\Factories\InvoiceFactory;
use Modules\Payments\Domain\Enums\InvoiceStatus;
use Modules\Subscriptions\Domain\Models\Subscription;

/**
 * A bill for one subscription billing period (ADR 0006). Admin-managed financial
 * record — never soft-deleted or edited after issue. Composition module —
 * references Companies + Subscriptions.
 *
 * @property int $id
 * @property string $uuid
 * @property string $number
 * @property int $company_id
 * @property int|null $subscription_id
 * @property InvoiceStatus $status
 * @property string $amount
 * @property string $currency
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property Carbon $issued_at
 * @property Carbon|null $due_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $voided_at
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Company $company
 * @property-read Subscription|null $subscription
 * @property-read Collection<int, Payment> $payments
 * @property-read Collection<int, PaymentEvent> $events
 */
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    use HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'number',
        'company_id',
        'subscription_id',
        'status',
        'amount',
        'currency',
        'period_start',
        'period_end',
        'issued_at',
        'due_at',
        'paid_at',
        'voided_at',
        'meta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'amount' => 'decimal:2',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'issued_at' => 'datetime',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return HasMany<PaymentEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class);
    }

    protected static function newFactory(): InvoiceFactory
    {
        return InvoiceFactory::new();
    }
}
